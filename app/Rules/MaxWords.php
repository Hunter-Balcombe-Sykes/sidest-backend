<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// V2: Validation rule that enforces a maximum word count on string inputs.
class MaxWords implements ValidationRule
{
    public function __construct(private int $maxWords) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) return;

        $text = trim((string) $value);
        if ($text === '') return;

        // normalize whitespace then count "wordy" tokens
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $count = (int) preg_match_all('/\S+/u', $text);

        if ($count > $this->maxWords) {
            $fail("The {$attribute} may not be greater than {$this->maxWords} words.");
        }
    }
}
