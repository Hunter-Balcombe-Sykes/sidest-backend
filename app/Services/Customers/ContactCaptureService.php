<?php

namespace App\Services\Customers;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Professional\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralized contact capture for affiliate CRM entries.
 *
 * Handles the upsert rules for core.customers: soft-delete restore,
 * email-based dedup (matches the `(professional_id, lower(email))` unique index),
 * phone uniqueness collision retries, and source preservation (first capture wins).
 *
 * Marketing list membership lives in notifications.email_subscriptions and is
 * upserted via captureMarketingSubscription(). Call that AFTER captureContact()
 * so the EmailSubscription 'saved' hook finds the customer row and syncs the
 * marketing_opt_in_cached flag.
 *
 * All methods are non-throwing by design — contact capture is a side-effect of
 * primary business flows (Shopify order webhook, Square booking, site leads)
 * and must never fail the parent operation. Failures are logged as warnings.
 */
class ContactCaptureService
{
    /**
     * Upsert a contact on the given affiliate's customer list.
     *
     * Dedup key: (professional_id, lower(email)). Soft-deleted rows are restored
     * so a customer who unsubscribed and later re-purchases comes back into the list.
     *
     * Source preservation: the existing row's source is left untouched. Only new
     * rows or rows with an empty source get the incoming source value. This means
     * the first capture point to see a given email wins — a customer who books
     * first then buys keeps source='square_booking'.
     *
     * @param  array{email:string, full_name?:?string, phone?:?string, source:string, external_id?:?string}  $data
     */
    public function captureContact(string $professionalId, array $data): ?Customer
    {
        try {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email === '') {
                return null;
            }

            $fullName = trim((string) ($data['full_name'] ?? ''));
            $fullName = $fullName !== '' ? $fullName : null;

            $phone = trim((string) ($data['phone'] ?? ''));
            $phone = $phone !== '' ? $phone : null;

            $source = trim((string) ($data['source'] ?? ''));
            $externalId = isset($data['external_id']) ? trim((string) $data['external_id']) : '';
            $externalId = $externalId !== '' ? $externalId : null;

            $existing = Customer::query()
                ->withTrashed()
                ->where('professional_id', $professionalId)
                ->whereRaw('lower(email) = ?', [$email])
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ($fullName !== null) {
                    $existing->full_name = $fullName;
                }
                // First capture wins — only fill source on rows that don't have one.
                if (($existing->source ?? '') === '' && $source !== '') {
                    $existing->source = $source;
                }
                if ($externalId !== null && ($existing->external_id ?? '') === '') {
                    $existing->external_id = $externalId;
                }

                $this->savePreservingPhoneOnCollision($existing, $phone);

                return $existing;
            }

            $attributes = [
                'professional_id' => $professionalId,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'source' => $source !== '' ? $source : null,
                'external_id' => $externalId,
                // Customers default to opted-out; captureMarketingSubscription() flips this via the EmailSubscription saved hook.
                'marketing_opt_in_cached' => false,
            ];

            try {
                return Customer::query()->create($attributes);
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                // Phone collides with another contact on the same affiliate — retry without phone.
                $attributes['phone'] = null;

                return Customer::query()->create($attributes);
            }
        } catch (Throwable $e) {
            Log::warning('Contact capture failed', [
                'professional_id' => $professionalId,
                'source' => $data['source'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Upsert a marketing list subscription for the given affiliate + email.
     *
     * Creates an EmailSubscription row (list_key='marketing') if none exists, or
     * reactivates an existing one via markSubscribed(). The EmailSubscription
     * `saved` hook syncs the marketing_opt_in_cached flag on the matching
     * Customer row, so callers should run captureContact() first.
     *
     * @param  array{ip_hash?:?string, user_agent?:?string}  $consentMeta
     */
    public function captureMarketingSubscription(
        string $professionalId,
        string $email,
        ?string $fullName,
        string $consentSource,
        array $consentMeta = []
    ): void {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        try {
            $sub = EmailSubscription::query()
                ->where('professional_id', $professionalId)
                ->where('list_key', 'marketing')
                ->where('email_lc', $email)
                ->first();

            if (! $sub) {
                $sub = new EmailSubscription([
                    'professional_id' => $professionalId,
                    'list_key' => 'marketing',
                    'email' => $email,
                    'email_lc' => $email,
                    'full_name' => $fullName,
                    'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
                ]);
            } else {
                if ($fullName !== null && $fullName !== '') {
                    $sub->full_name = $fullName;
                }
                if (! $sub->unsubscribe_token) {
                    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
                }
            }

            $sub->markSubscribed([
                'source' => $consentSource,
                'ip_hash' => $consentMeta['ip_hash'] ?? null,
                'user_agent' => $consentMeta['user_agent'] ?? null,
            ]);

            $sub->save();
        } catch (QueryException $e) {
            // 23505 = another request won the race; fine to ignore.
            if ($e->getCode() !== '23505') {
                Log::warning('Marketing subscription capture failed', [
                    'professional_id' => $professionalId,
                    'message' => $e->getMessage(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Marketing subscription capture failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save the customer, handling the per-affiliate phone uniqueness index.
     * If the incoming phone collides with another contact, keep the original
     * phone and proceed — we never want a phone collision to drop the whole
     * upsert, since email is the primary dedup key.
     */
    private function savePreservingPhoneOnCollision(Customer $customer, ?string $phone): void
    {
        if ($phone === null) {
            $customer->save();

            return;
        }

        try {
            $customer->phone = $phone;
            $customer->save();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $customer->phone = $customer->getOriginal('phone');
            $customer->save();
        }
    }
}
