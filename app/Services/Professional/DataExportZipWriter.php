<?php

namespace App\Services\Professional;

use RuntimeException;
use ZipArchive;

// V2: Streams a payload array into a temp .zip on disk. Returns the path,
// SHA-256 hash, byte size, and a record_counts summary for the audit row.
// Builds CSVs only for sections a non-technical user opens in Excel.
class DataExportZipWriter
{
    /**
     * @return array{path: string, sha256: string, size: int, record_counts: array<string, int>}
     */
    public function write(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for export zip.');
        }
        // tempnam creates an empty file; ZipArchive::CREATE refuses to overwrite a
        // non-zip file in some PHP versions. Unlink it so ZipArchive opens fresh.
        @unlink($path);
        $path .= '.zip';

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Failed to open zip for writing: {$path}");
        }

        $zip->addFromString('data.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $recordCounts = $this->recordCounts($payload);

        $this->maybeAddCsv($zip, 'customers.csv', $payload['customers'] ?? [], [
            'id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at',
        ]);

        $this->maybeAddCsv($zip, 'enquiries.csv', $payload['enquiries'] ?? [], [
            'id', 'name', 'email', 'phone', 'subject', 'message', 'created_at',
        ]);

        $this->maybeAddCsv($zip, 'bookings.csv', $payload['bookings']['booking_events'] ?? [], [
            'id', 'occurred_at', 'status', 'source', 'customer_name', 'customer_email', 'customer_phone', 'amount_paid_cents', 'currency_code', 'created_at',
        ]);

        $this->maybeAddCsv($zip, 'commission_payouts.csv', $payload['billing']['commission_payouts'] ?? [], [
            'id', 'status', 'amount_cents', 'created_at',
        ]);

        $zip->close();

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
            'size' => filesize($path),
            'record_counts' => $recordCounts,
        ];
    }

    /**
     * Add a CSV entry to the zip iff the section has rows. Empty sections are
     * intentionally omitted to keep the zip small for accounts with no
     * customers/bookings yet.
     */
    private function maybeAddCsv(ZipArchive $zip, string $name, array $rows, array $columns): void
    {
        if (empty($rows)) {
            return;
        }

        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $columns);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fp, $line);
        }

        rewind($fp);
        $zip->addFromString($name, stream_get_contents($fp));
        fclose($fp);
    }

    /**
     * @return array<string, int>
     */
    private function recordCounts(array $payload): array
    {
        return [
            'customers' => count($payload['customers'] ?? []),
            'services' => count($payload['services'] ?? []),
            'service_categories' => count($payload['service_categories'] ?? []),
            'enquiries' => count($payload['enquiries'] ?? []),
            'email_subscriptions' => count($payload['email_subscriptions'] ?? []),
            'booking_events' => count($payload['bookings']['booking_events'] ?? []),
            'lead_submissions' => count($payload['bookings']['lead_submissions'] ?? []),
            'site_media' => count($payload['media']['site_media'] ?? []),
            'integrations' => count($payload['integrations'] ?? []),
            'commission_movements' => count($payload['billing']['commission_movements'] ?? []),
            'commission_payouts' => count($payload['billing']['commission_payouts'] ?? []),
        ];
    }
}
