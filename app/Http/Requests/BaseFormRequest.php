<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// V2: Base form request with shared input sanitization helpers — trim, lowercase, and email normalization.
abstract class BaseFormRequest extends FormRequest
{
    /**
     * Authorization is enforced entirely by route middleware (VerifySupabaseJwt,
     * LoadCurrentProfessional, staff guards) and resource Policies invoked from
     * controllers via `authorizeForUser($pro, ...)`. This method is intentionally
     * `final` — Supabase JWT means `Auth::user()` is always null, so any logic
     * here would gate against a null user and create a second, inconsistent
     * authorization surface. Do NOT override.
     */
    final public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim string inputs.
     */
    protected function trimStrings(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Lowercase string inputs.
     */
    protected function lowercaseStrings(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $data[$key] = strtolower(trim($value));
            }
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Sanitize email inputs (trim and lowercase).
     */
    protected function sanitizeEmails(array $keys): void
    {
        $this->lowercaseStrings($keys);
    }

    /**
     * Normalize phone-like inputs to digits and a leading `+` only.
     * Strips whitespace, parens, dashes, and any other punctuation. Empty
     * results coerce to null so downstream code never has to distinguish
     * '' from null. Skips keys that are absent or not strings.
     */
    protected function normalizePhones(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = preg_replace('/[^\d+]/', '', trim($value));
            $data[$key] = $normalized === '' ? null : $normalized;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }
}
