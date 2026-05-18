<?php

namespace App\Services\Professional\DataExport;

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;

// V2: Pure builder. Assembles the full data-export payload (Ring 1 + 2 of the
// scope rings — see docs/superpowers/specs/2026-04-25-data-export-design.md).
// No I/O beyond DB reads — testable with fixture data, no filesystem touches.
class DataExportPayloadBuilder
{
    private const SCHEMA_VERSION = 1;

    private const PII_DISCLOSURE = 'This export contains personally identifiable information (PII) you collected from your customers via Partna (booking history, enquiries, email subscriptions). Handle in accordance with applicable privacy law.';

    /**
     * Build the full payload for a single professional.
     *
     * @return array{metadata: array, profile: array, site: array, media: array, integrations: array, customers: array, services: array, service_categories: array, enquiries: array, email_subscriptions: array, notification_preferences: array, bookings: array, billing: array, audit: array}
     */
    public function build(string $professionalId): array
    {
        $professional = Professional::query()
            ->withTrashed()
            ->where('id', $professionalId)
            ->firstOrFail();

        return [
            'metadata' => $this->metadata($professional),
            'profile' => $this->profile($professional),
            'site' => $this->site($professionalId),
            'media' => $this->media($professionalId),
            'integrations' => $this->integrations($professionalId),
            'customers' => $this->customers($professionalId),
            'services' => $this->services($professionalId),
            'service_categories' => $this->serviceCategories($professionalId),
            'enquiries' => $this->enquiries($professionalId),
            'email_subscriptions' => $this->emailSubscriptions($professionalId),
            'notification_preferences' => $this->notificationPreferences($professionalId),
            'bookings' => $this->bookings($professionalId),
            'billing' => $this->billing($professionalId),
            'audit' => $this->audit($professionalId),
        ];
    }

    private function metadata(Professional $p): array
    {
        return [
            'professional_id' => $p->id,
            'professional_handle' => $p->handle,
            'exported_at' => now()->toIso8601String(),
            'schema_version' => self::SCHEMA_VERSION,
            'notes' => self::PII_DISCLOSURE,
        ];
    }

    private function profile(Professional $p): array
    {
        // Strip secrets — never let auth or tokens leak into an export.
        $row = $p->toArray();
        unset($row['auth_user_id'], $row['deletion_token_hash']);

        $brandProfile = DB::connection('pgsql')
            ->table('brand.brand_profiles')
            ->where('professional_id', $p->id)
            ->first();

        $brandPartnerLinks = DB::connection('pgsql')
            ->table('brand.brand_partner_links')
            ->where('brand_professional_id', $p->id)
            ->orWhere('affiliate_professional_id', $p->id)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'professional' => $row,
            'brand_profile' => $brandProfile ? (array) $brandProfile : null,
            'brand_partner_links' => $brandPartnerLinks,
        ];
    }

    private function site(string $professionalId): array
    {
        $site = DB::connection('pgsql')
            ->table('site.sites')
            ->where('professional_id', $professionalId)
            ->first();

        if (! $site) {
            return ['site' => null, 'blocks' => []];
        }

        $blocks = DB::connection('pgsql')
            ->table('site.blocks')
            ->where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'site' => (array) $site,
            'blocks' => $blocks,
        ];
    }

    private function media(string $professionalId): array
    {
        $items = DB::connection('pgsql')
            ->table('site.site_media')
            ->select(['id', 'pool', 'purpose', 'path', 'width', 'height', 'caption', 'alt_text', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return ['site_media' => $items];
    }

    private function integrations(string $professionalId): array
    {
        // Strip access_token and refresh_token — credentials never go in an export.
        return DB::connection('pgsql')
            ->table('core.professional_integrations')
            ->select(['id', 'provider', 'shop_domain', 'last_sync_at', 'created_at', 'updated_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function customers(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('core.customers')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function services(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('site.services')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function serviceCategories(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('site.service_categories')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function enquiries(string $professionalId): array
    {
        // Mirror the redaction in ExportCustomerDataJob — drop ip_hash + user_agent
        // (technical fingerprint, not part of the user-visible enquiry).
        return DB::connection('pgsql')
            ->table('site.enquiries')
            ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function emailSubscriptions(string $professionalId): array
    {
        // Explicit allow-list — `unsubscribe_token`, `consent_ip_hash`, and
        // `consent_user_agent` are in EmailSubscription::$hidden because they
        // are either credentials (token unsubscribes anyone) or technical
        // fingerprints. DB::table() bypasses $hidden, so we re-state the
        // allow-list here. Mirrors the column set returned by
        // /api/email-subscribers.
        return DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->select(['id', 'professional_id', 'list_key', 'email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at', 'consent_source', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Per-category email opt-in/out preferences plus any staff-issued policy
     * overrides scoped to this professional. Required for GDPR Article 15
     * (right of access) — users must be able to see every preference we store
     * about them, not just the marketing subscription list.
     */
    private function notificationPreferences(string $professionalId): array
    {
        $preferences = DB::connection('pgsql')
            ->table('notifications.notification_email_preferences')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        // Per-professional policy overrides only — global policies apply to
        // every user and are not personal data.
        $policies = DB::connection('pgsql')
            ->table('notifications.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'category_preferences' => $preferences,
            'staff_policy_overrides' => $policies,
        ];
    }

    private function bookings(string $professionalId): array
    {
        // raw_payload deliberately excluded — it is the full third-party API
        // response (Square/Fresha) and may contain other parties' data
        // (staff member who took the booking, etc.).
        $events = DB::connection('pgsql')
            ->table('analytics.booking_events')
            ->select(['id', 'occurred_at', 'status', 'source', 'customer_name', 'customer_email', 'customer_phone', 'amount_paid_cents', 'currency_code', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        // Mirror the redaction in enquiries() — drop ip_hash + user_agent
        // (technical fingerprint, not user-visible lead data).
        $leads = DB::connection('pgsql')
            ->table('analytics.lead_submissions')
            ->select(['id', 'occurred_at', 'outcome', 'form_started_at_ms', 'customer_id', 'subdomain', 'site_id', 'referrer'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'booking_events' => $events,
            'lead_submissions' => $leads,
        ];
    }

    private function billing(string $professionalId): array
    {
        $subscription = DB::connection('pgsql')
            ->table('billing.subscriptions')
            ->where('professional_id', $professionalId)
            ->first();

        $ledger = DB::connection('pgsql')
            ->table('commerce.commission_movements')
            ->where('affiliate_professional_id', $professionalId)
            ->orWhere('brand_professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $payouts = DB::connection('pgsql')
            ->table('commerce.commission_payouts')
            ->where('affiliate_professional_id', $professionalId)
            ->orWhere('brand_professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'subscription' => $subscription ? (array) $subscription : null,
            'commission_movements' => $ledger,
            'commission_payouts' => $payouts,
        ];
    }

    private function audit(string $professionalId): array
    {
        $exports = DB::connection('pgsql')
            ->table('core.data_export_audit')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'data_export_audit' => $exports,
        ];
    }
}
