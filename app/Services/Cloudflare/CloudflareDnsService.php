<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Wraps Cloudflare DNS Records API for sidest.co zone management.
// Used to provision platform subdomains (brand.sidest.co) for Hydrogen storefronts.
class CloudflareDnsService
{
    private string $zoneId;

    private string $apiToken;

    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct()
    {
        $this->zoneId = (string) config('services.cloudflare.zone_id', '');
        $this->apiToken = (string) config('services.cloudflare.api_token', '');
    }

    /**
     * Ensure a CNAME record exists for $name pointing to $target.
     * Creates it if missing; returns the Cloudflare record ID.
     *
     * $name should be just the subdomain part (e.g. "mybrand") for a zone-relative record.
     * $proxied = true routes traffic through Cloudflare CDN (orange-cloud).
     *
     * Returns null in dev mode (missing credentials) or on unrecoverable API error.
     */
    public function ensureCname(string $name, string $target, bool $proxied = true): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $existing = $this->findRecord('CNAME', $name);
        if ($existing !== null) {
            return $existing['id'];
        }

        $response = Http::withToken($this->apiToken)
            ->post($this->zonesUrl('/dns_records'), [
                'type' => 'CNAME',
                'name' => $name,
                'content' => $target,
                'proxied' => $proxied,
                // ttl: 1 means "automatic" when proxied is true.
                'ttl' => 1,
            ]);

        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create CNAME record.', [
                'name' => $name,
                'target' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return (string) $response->json('result.id', '');
    }

    /**
     * Create or update a CNAME record, including flipping the proxied flag.
     * Unlike ensureCname (which skips if it exists), this patches an existing
     * record when its target or proxied setting differs — needed when a record
     * was previously created with the wrong settings (e.g. proxied=true for
     * Shopify Oxygen, which requires DNS-only / proxied=false).
     */
    public function upsertCname(string $name, string $target, bool $proxied = false): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $existing = $this->findRecord('CNAME', $name);

        if ($existing !== null) {
            if ($existing['content'] === $target) {
                // Check proxied state — requires a fresh fetch as findRecord doesn't return it.
                $response = Http::withToken($this->apiToken)
                    ->patch($this->zonesUrl("/dns_records/{$existing['id']}"), [
                        'proxied' => $proxied,
                    ]);

                if (! $response->successful()) {
                    Log::error('CloudflareDnsService: failed to update CNAME proxied state.', [
                        'name' => $name,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                return $existing['id'];
            }

            $response = Http::withToken($this->apiToken)
                ->patch($this->zonesUrl("/dns_records/{$existing['id']}"), [
                    'content' => $target,
                    'proxied' => $proxied,
                ]);

            if (! $response->successful()) {
                Log::error('CloudflareDnsService: failed to update CNAME record.', [
                    'name' => $name,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $existing['id'];
        }

        $response = Http::withToken($this->apiToken)
            ->post($this->zonesUrl('/dns_records'), [
                'type' => 'CNAME',
                'name' => $name,
                'content' => $target,
                'proxied' => $proxied,
                'ttl' => 1,
            ]);

        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create CNAME record.', [
                'name' => $name,
                'target' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return (string) $response->json('result.id', '');
    }

    /**
     * Ensure a TXT record exists.
     * Used for Shopify domain verification challenges.
     * Returns the Cloudflare record ID, or null on error / dev mode.
     */
    public function ensureTxt(string $name, string $content): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $existing = $this->findRecord('TXT', $name);
        if ($existing !== null) {
            return $existing['id'];
        }

        $response = Http::withToken($this->apiToken)
            ->post($this->zonesUrl('/dns_records'), [
                'type' => 'TXT',
                'name' => $name,
                'content' => $content,
                'ttl' => 1,
            ]);

        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create TXT record.', [
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return (string) $response->json('result.id', '');
    }

    /**
     * Create or update a TXT record. Unlike ensureTxt (which skips if it exists),
     * this patches the content if the record exists with a different value — needed
     * for Shopify verification tokens that rotate on each domain-connect attempt.
     */
    public function upsertTxt(string $name, string $content): ?string
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $existing = $this->findRecord('TXT', $name);

        if ($existing !== null) {
            if ($existing['content'] === $content) {
                return $existing['id'];
            }

            $response = Http::withToken($this->apiToken)
                ->patch($this->zonesUrl("/dns_records/{$existing['id']}"), [
                    'content' => $content,
                ]);

            if (! $response->successful()) {
                Log::error('CloudflareDnsService: failed to update TXT record.', [
                    'name' => $name,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $existing['id'];
        }

        $response = Http::withToken($this->apiToken)
            ->post($this->zonesUrl('/dns_records'), [
                'type' => 'TXT',
                'name' => $name,
                'content' => $content,
                'ttl' => 1,
            ]);

        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create TXT record.', [
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return (string) $response->json('result.id', '');
    }

    /**
     * Delete a DNS record by ID. No-op if the record doesn't exist (404 is swallowed).
     */
    public function deleteRecord(string $recordId): void
    {
        if (! $this->hasCredentials()) {
            return;
        }

        $response = Http::withToken($this->apiToken)
            ->delete($this->zonesUrl("/dns_records/{$recordId}"));

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('CloudflareDnsService: failed to delete DNS record.', [
                'record_id' => $recordId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Find an existing DNS record by type and name.
     * Returns an array with 'id', 'type', 'name', 'content' keys, or null if not found.
     */
    public function findRecord(string $type, string $name): ?array
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $response = Http::withToken($this->apiToken)
            ->get($this->zonesUrl('/dns_records'), [
                'type' => $type,
                'name' => $name,
            ]);

        if (! $response->successful()) {
            Log::warning('CloudflareDnsService: failed to query DNS records.', [
                'type' => $type,
                'name' => $name,
                'status' => $response->status(),
            ]);

            return null;
        }

        $results = $response->json('result', []);
        if (! is_array($results) || empty($results)) {
            return null;
        }

        $record = $results[0];

        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => (string) ($record['type'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'content' => (string) ($record['content'] ?? ''),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns false (and logs a warning) when zone_id or api_token are absent.
     * Allows the app to operate without DNS side-effects in local/dev environments.
     */
    private function hasCredentials(): bool
    {
        if ($this->zoneId === '' || $this->apiToken === '') {
            Log::warning('CloudflareDnsService: zone_id or api_token not configured — skipping DNS operation.');

            return false;
        }

        return true;
    }

    /** Build a Cloudflare API URL scoped to this zone. */
    private function zonesUrl(string $path): string
    {
        return self::BASE_URL.'/zones/'.$this->zoneId.$path;
    }
}
