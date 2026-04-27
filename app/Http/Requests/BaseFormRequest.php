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
}
