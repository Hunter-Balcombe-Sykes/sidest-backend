<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Server-side Supabase user management via the GoTrue Admin API. Creates users and looks up existing accounts by email.
class SupabaseAdminService
{
    private string $baseUrl;
    private string $serviceRoleKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('supabase.url'), '/');
        $this->serviceRoleKey = (string) config('supabase.service_role_key');

        if ($this->baseUrl === '' || $this->serviceRoleKey === '') {
            throw new RuntimeException('Supabase URL and service role key must be configured.');
        }
    }

    /**
     * Create a Supabase user server-side (no password — magic link auth).
     *
     * @return array{id: string, email: string, created: bool}
     */
    public function createUser(string $email, array $metadata = []): array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            throw new RuntimeException('Email is required to create a Supabase user.');
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl}/auth/v1/admin/users", [
                'email' => $email,
                'email_confirm' => true,
                'user_metadata' => $metadata ?: (object) [],
            ]);

        if ($response->successful()) {
            $user = $response->json();

            return [
                'id' => (string) ($user['id'] ?? ''),
                'email' => (string) ($user['email'] ?? $email),
                'created' => true,
            ];
        }

        // User already exists — try to extract from error response, fall back to paginated search
        if ($response->status() === 422 || $response->status() === 409) {
            $body = $response->json();

            // GoTrue v2 includes the existing user object in the error response
            $existingId = $body['user']['id'] ?? null;
            if ($existingId) {
                return [
                    'id' => (string) $existingId,
                    'email' => (string) ($body['user']['email'] ?? $email),
                    'created' => false,
                ];
            }

            // Fallback: paginated search (legacy GoTrue versions)
            $existing = $this->getUserByEmail($email);

            if ($existing !== null) {
                return [
                    'id' => $existing['id'],
                    'email' => $existing['email'],
                    'created' => false,
                ];
            }
        }

        Log::error('Supabase admin: failed to create user', [
            'email' => $email,
            'status' => $response->status(),
            'error_code' => $response->json('code'),
            'error_msg' => $response->json('msg'),
        ]);

        throw new RuntimeException('Failed to create Supabase user.');
    }

    /**
     * Look up a Supabase user by email.
     *
     * @return array{id: string, email: string}|null
     */
    public function getUserByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        // Paginate through users to find by email — GoTrue admin API has no direct email filter.
        $page = 1;
        $perPage = 50;
        $users = [];

        while (true) {
            $response = Http::withHeaders($this->headers())
                ->timeout(10)
                ->get("{$this->baseUrl}/auth/v1/admin/users", [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

            if (! $response->successful()) {
                Log::warning('Supabase admin: failed to look up user by email', [
                    'email' => $email,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $batch = $response->json('users', []);

            foreach ($batch as $user) {
                if (strtolower(trim((string) ($user['email'] ?? ''))) === $email) {
                    $users = [$user];
                    break 2;
                }
            }

            if (count($batch) < $perPage) {
                break;
            }

            $page++;
        }

        if (empty($users)) {
            return null;
        }

        $user = $users[0];

        return [
            'id' => (string) ($user['id'] ?? ''),
            'email' => (string) ($user['email'] ?? $email),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->serviceRoleKey,
            'apikey' => $this->serviceRoleKey,
            'Content-Type' => 'application/json',
        ];
    }
}
