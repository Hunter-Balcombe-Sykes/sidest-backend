<?php

namespace App\Jobs\Shopify\Gdpr;

use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Handles Shopify `customers/redact` webhook. Anonymises the matching
// core.customers row (kept for commission ledger integrity) and hard-deletes
// email_subscriptions + site.enquiries rows for the customer email.
// Also scrubs denormalised PII from analytics.booking_events — those rows
// have no customer_id FK so they don't cascade via the customers anonymisation.
class RedactCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $gdprRequestId)
    {
        $this->onQueue(config('sidest.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $resolver = app(ShopifyShopResolver::class);
        $gdpr = GdprRequest::find($this->gdprRequestId);

        if (! $gdpr) {
            Log::warning('RedactCustomerJob: gdpr_requests row not found', ['id' => $this->gdprRequestId]);

            return;
        }

        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);

        try {
            $professionalId = $resolver->resolveProfessionalId($gdpr->shop_domain);

            if (! $professionalId) {
                $gdpr->markSkipped('no integration for shop_domain');

                return;
            }

            $gdpr->update(['professional_id' => $professionalId]);

            $email = $this->customerEmail($gdpr->payload);

            if ($email === null) {
                $gdpr->markSkipped('payload missing customer.email');

                return;
            }

            // Include soft-deleted rows — they still hold real PII and must
            // be anonymised on a GDPR redact request.
            $customer = Customer::query()
                ->withTrashed()
                ->where('professional_id', $professionalId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->whereNull('redacted_at')
                ->first();

            if ($customer) {
                $placeholderDomain = config('sidest.gdpr.redact_placeholder_domain', 'gdpr.sidest.io');

                $customer->update([
                    'email' => 'redacted-'.Str::uuid()->toString().'@'.$placeholderDomain,
                    'phone' => null,
                    'full_name' => 'Redacted Customer',
                    'external_id' => null,
                    'notes' => null,
                    'marketing_opt_in_cached' => null,
                    'redacted_at' => now(),
                ]);
            }

            $emailLc = mb_strtolower($email);

            // Run sibling cleanup unconditionally on the email address.
            // A visitor who only submitted a contact enquiry (no booking) will
            // have no core.customers row but still have real PII in these tables.
            $deletedSubs = DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->where('professional_id', $professionalId)
                ->where('email_lc', $emailLc)
                ->delete();

            $deletedEnquiries = DB::connection('pgsql')
                ->table('site.enquiries')
                ->where('professional_id', $professionalId)
                ->whereRaw('LOWER(email) = ?', [$emailLc])
                ->delete();

            // Scrub denormalised PII on booking_events. These rows have no
            // customer_id FK so core.customers anonymisation doesn't cascade.
            // raw_payload (full Square/Fresha booking JSON) is reset to '{}' because
            // it contains the customer object. '{}'::jsonb on Postgres; plain '{}' on SQLite.
            $scrubbedBookings = DB::connection('pgsql')
                ->table('analytics.booking_events')
                ->where('professional_id', $professionalId)
                ->whereRaw('LOWER(customer_email) = ?', [$emailLc])
                ->update([
                    'customer_name' => null,
                    'customer_email' => null,
                    'customer_phone' => null,
                    'raw_payload' => '{}',
                    'updated_at' => now(),
                ]);

            // Scrub PII on commerce.orders. Customer-linked Shopify order rows hold the
            // raw Shopify payload (billing/shipping address, customer name/email/phone) on
            // shopify_data, plus customer-authored fields under line_items[*].properties.
            // Full-nuke shopify_data to '{}' (matches the booking_events.raw_payload pattern
            // — works on Postgres and SQLite). Cents columns are kept for analytics.
            $scrubbedOrders = 0;
            if ($customer) {
                $orderIds = DB::connection('pgsql')
                    ->table('commerce.orders')
                    ->where('customer_id', $customer->id)
                    ->pluck('id')
                    ->all();

                if (! empty($orderIds)) {
                    $scrubbedOrders = DB::connection('pgsql')
                        ->table('commerce.orders')
                        ->whereIn('id', $orderIds)
                        ->update([
                            'shopify_data' => '{}',
                            'customer_id' => null,
                            'updated_at' => now(),
                        ]);

                    // Order events (audit log) can hold customer-named refund notes,
                    // adjustment reasons, denormalised customer fields. Full-nuke metadata.
                    DB::connection('pgsql')
                        ->table('commerce.order_events')
                        ->whereIn('order_id', $orderIds)
                        ->update(['metadata' => '{}']);
                }
            }

            // Nothing found for this email — legitimate skip (email address may
            // never have interacted with this shop via Side St).
            if (! $customer && $deletedSubs === 0 && $deletedEnquiries === 0
                && $scrubbedBookings === 0 && $scrubbedOrders === 0) {
                $gdpr->markSkipped('no data found for email in this shop');

                return;
            }

            $gdpr->markCompleted();

            Log::info('RedactCustomerJob completed.', [
                'gdpr_request_id' => $gdpr->id,
                'professional_id' => $professionalId,
                'customer_id' => $customer?->id,
                'deleted_subscriptions' => $deletedSubs,
                'deleted_enquiries' => $deletedEnquiries,
                'scrubbed_bookings' => $scrubbedBookings,
                'scrubbed_orders' => $scrubbedOrders,
            ]);
        } catch (\Throwable $e) {
            $gdpr->markFailed($e->getMessage());
            Log::error('RedactCustomerJob failed', [
                'gdpr_request_id' => $gdpr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Pluck the customer email out of a customers/redact payload.
     * Shopify shape: { "customer": { "id", "email", "phone" }, "orders_to_redact": [...] }
     */
    private function customerEmail(array $payload): ?string
    {
        $email = $payload['customer']['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }
}
