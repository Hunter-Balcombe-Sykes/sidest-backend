<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\UploadedFile;
use RuntimeException;

// V2: Shared CSV parsing for brand-affiliate invite imports. Used by both
// the self-service BrandAffiliateInviteController and the on-behalf-of
// StaffInviteController so they accept the exact same CSV format and aliases.
trait ParsesBrandAffiliateInviteCsv
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseInviteCsvRows(UploadedFile $file, int $maxRows): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open the uploaded CSV file.');
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new RuntimeException('CSV file is empty.');
            }

            if (isset($header[0])) {
                // Strip UTF-8 BOM that some spreadsheet exporters prepend.
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            }

            $columnMap = $this->resolveInviteCsvColumnMap($header);
            if (! isset($columnMap['email'])) {
                throw new RuntimeException('CSV must include an email column.');
            }

            $rows = [];
            $lineNumber = 1;

            while (($csvRow = fgetcsv($handle)) !== false) {
                $lineNumber++;

                if (! is_array($csvRow) || $this->inviteCsvRowIsEmpty($csvRow)) {
                    continue;
                }

                $row = ['_row_number' => $lineNumber];
                foreach ($columnMap as $field => $index) {
                    $row[$field] = isset($csvRow[$index]) ? trim((string) $csvRow[$index]) : null;
                }

                $rows[] = $row;

                if (count($rows) > $maxRows) {
                    throw new RuntimeException('CSV row limit exceeded. Maximum '.$maxRows.' rows are allowed per import.');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new RuntimeException('CSV does not contain any invite rows.');
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<string, int>
     */
    private function resolveInviteCsvColumnMap(array $header): array
    {
        $aliasMap = [
            'email' => ['email', 'email_address', 'e_mail', 'mail'],
            'phone' => ['phone', 'phone_number', 'mobile', 'mobile_number', 'contact_number'],
            'first_name' => ['first_name', 'first', 'firstname', 'given_name', 'givenname'],
            'last_name' => ['last_name', 'last', 'lastname', 'surname', 'family_name', 'familyname'],
            'message' => ['message', 'note', 'notes', 'invite_message'],
            'expiration' => ['expiration', 'expiry', 'expires', 'expires_in', 'expire_in', 'expire_after', 'expires_after'],
        ];

        $recognized = [];
        foreach ($header as $index => $value) {
            $normalized = $this->normalizeInviteCsvHeader((string) $value);
            if ($normalized === '') {
                continue;
            }

            foreach ($aliasMap as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && ! array_key_exists($field, $recognized)) {
                    $recognized[$field] = (int) $index;
                    break;
                }
            }
        }

        return $recognized;
    }

    private function normalizeInviteCsvHeader(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    /**
     * @param  array<int, mixed>  $csvRow
     */
    private function inviteCsvRowIsEmpty(array $csvRow): bool
    {
        foreach ($csvRow as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
