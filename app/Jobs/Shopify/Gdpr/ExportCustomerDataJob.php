<?php

namespace App\Jobs\Shopify\Gdpr;

use App\Mail\Gdpr\CustomerDataExportMail;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Handles Shopify `customers/data_request`. Read-only — gathers every
// PII record we hold for the requesting customer (scoped to this shop's
// professional) and emails the JSON dump to the merchant. Merchant forwards
// to the customer; Shopify tracks compliance on their side.
class ExportCustomerDataJob implements ShouldQueue
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
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $resolver = app(ShopifyShopResolver::class);
        $gdpr = GdprRequest::find($this->gdprRequestId);

        if (! $gdpr) {
            Log::warning('ExportCustomerDataJob: gdpr_requests row not found', ['id' => $this->gdprRequestId]);

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

            $email = $gdpr->payload['customer']['email'] ?? null;

            if (! is_string($email) || $email === '') {
                $gdpr->markSkipped('payload missing customer.email');

                return;
            }

            $professional = Professional::find($professionalId);

            if (! $professional) {
                $gdpr->markSkipped('professional row gone');

                return;
            }

            // Professional model uses primary_email and public_contact_email
            $recipientEmail = $professional->public_contact_email ?: $professional->primary_email;

            if (! $recipientEmail) {
                $gdpr->markFailed('professional has no contact email address');

                return;
            }

            $exportData = $this->gatherExportData($professionalId, $email);

            Mail::to($recipientEmail)->send(
                new CustomerDataExportMail($gdpr->shop_domain, $email, $exportData)
            );

            $gdpr->markCompleted();

            Log::info('ExportCustomerDataJob completed.', [
                'gdpr_request_id' => $gdpr->id,
                'professional_id' => $professionalId,
                'recipient' => $recipientEmail,
                'customer_records' => count($exportData['customers'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $gdpr->markFailed($e->getMessage());
            Log::error('ExportCustomerDataJob failed', [
                'gdpr_request_id' => $gdpr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Gather every PII record we hold for the given customer email, scoped
     * to the specified professional. Groups by source table.
     *
     * @return array{customers: array, email_subscriptions: array, enquiries: array, lead_submissions: array}
     */
    private function gatherExportData(string $professionalId, string $email): array
    {
        $emailLc = mb_strtolower($email);

        $customers = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [$emailLc])
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'phone' => $c->phone,
                'full_name' => $c->full_name,
                'source' => $c->source,
                'notes' => $c->notes,
                'created_at' => optional($c->created_at)->toIso8601String(),
                'updated_at' => optional($c->updated_at)->toIso8601String(),
            ])
            ->all();

        $subscriptions = DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('professional_id', $professionalId)
            ->where('email_lc', $emailLc)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        // Deliberately exclude ip_hash and user_agent — technical fingerprint
        // metadata not provided by the customer; human-readable fields only.
        $enquiries = DB::connection('pgsql')
            ->table('site.enquiries')
            ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [$emailLc])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $customerIds = array_column($customers, 'id');

        $leadSubmissions = [];
        if (! empty($customerIds)) {
            $leadSubmissions = DB::connection('pgsql')
                ->table('analytics.lead_submissions')
                ->whereIn('customer_id', $customerIds)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        // analytics.booking_events is intentionally excluded: those rows are
        // denormalised analytics copies with no FK back to a verified customer
        // identity, and are scrubbed separately by RedactCustomerJob on a redact request.

        return [
            'customers' => $customers,
            'email_subscriptions' => $subscriptions,
            'enquiries' => $enquiries,
            'lead_submissions' => $leadSubmissions,
        ];
    }
}
