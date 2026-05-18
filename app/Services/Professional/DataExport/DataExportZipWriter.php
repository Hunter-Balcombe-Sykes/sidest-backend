<?php

namespace App\Services\Professional\DataExport;

use Generator;
use RuntimeException;
use ZipArchive;

// V2: Streams a payload into a temp .zip on disk. Returns the path, SHA-256
// hash, byte size, and a record_counts summary for the audit row.
//
// Two entry points:
//   - write(array $payload)             — legacy, materialises the whole
//                                         payload in memory. Kept for tests
//                                         and small callers.
//   - writeStreaming(builder, $profId)  — production path. Drives the builder
//                                         row-by-row, streaming data.json and
//                                         CSV entries to disk so peak memory
//                                         stays bounded regardless of tenant
//                                         size. GDPR exports must not OOM.
class DataExportZipWriter
{
    /**
     * Stream the builder's sections into a zip on disk. Drives one DB cursor
     * per unbounded section and writes rows straight to data.json (and any
     * applicable CSV) without ever holding the whole result set in memory.
     *
     * @return array{path: string, sha256: string, size: int, record_counts: array<string, int>}
     */
    public function writeStreaming(DataExportPayloadBuilder $builder, string $professionalId): array
    {
        $zipPath = $this->reserveZipPath();
        $jsonPath = tempnam(sys_get_temp_dir(), 'export-json-');
        if ($jsonPath === false) {
            throw new RuntimeException('Failed to create temp file for export json.');
        }

        $jh = fopen($jsonPath, 'wb');
        if ($jh === false) {
            throw new RuntimeException("Failed to open temp json for writing: {$jsonPath}");
        }

        // Track CSV temp files (created lazily on first row).
        /** @var array<string, array{path: string, fp: resource, columns: array<string>}> $csvHandles */
        $csvHandles = [];

        $recordCounts = [];
        $groups = []; // nested-group buffer for JSON assembly

        try {
            fwrite($jh, "{\n");
            $first = true;

            foreach ($builder->stream($professionalId) as $section) {
                $name = $section['name'];

                if ($section['kind'] === 'value') {
                    if (str_contains($name, '.')) {
                        // Buffer the value inside its group; we'll emit the
                        // group once we've seen all its children. Groups are
                        // small (subscription is one row) so this is fine.
                        [$group, $key] = explode('.', $name, 2);
                        $groups[$group]['values'][$key] = $section['value'];
                    } else {
                        $this->writeJsonEntry($jh, $name, $section['value'], $first);
                        $first = false;
                    }

                    continue;
                }

                // kind === 'rows'
                $rows = $section['rows'];
                $csvColumns = $section['csv_columns'] ?? null;
                $countKey = $this->recordCountKey($name);

                if (str_contains($name, '.')) {
                    [$group, $key] = explode('.', $name, 2);
                    if (! isset($groups[$group])) {
                        $groups[$group] = ['values' => [], 'rows' => []];
                    }
                    $rowsJson = $this->streamRowsToJson($jh, $rows, $csvHandles, $csvColumns, $name);
                    $groups[$group]['rows'][$key] = $rowsJson['placeholder_path'];
                    $recordCounts[$countKey] = $rowsJson['count'];
                } else {
                    $this->beginJsonEntry($jh, $name, $first);
                    $first = false;
                    $count = $this->streamRowsInline($jh, $rows, $csvHandles, $csvColumns, $name);
                    $recordCounts[$countKey] = $count;
                }
            }

            // Emit buffered nested groups (media, notification_preferences,
            // bookings, billing, audit). Each group's row sub-sections were
            // captured to per-section temp files; splice them back in.
            foreach ($groups as $group => $payload) {
                $this->emitGroup($jh, $group, $payload, $first);
                $first = false;
            }

            fwrite($jh, "\n}");
        } finally {
            if (is_resource($jh)) {
                fclose($jh);
            }
            // Close CSV handles so they flush before we add them to the zip.
            foreach ($csvHandles as $h) {
                if (is_resource($h['fp'])) {
                    fclose($h['fp']);
                }
            }
            // Clean up any group-row scratch files now that data.json is built.
            foreach ($groups as $group) {
                foreach ($group['rows'] ?? [] as $path) {
                    if (is_string($path) && file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            @unlink($jsonPath);
            throw new RuntimeException("Failed to open zip for writing: {$zipPath}");
        }

        $zip->addFile($jsonPath, 'data.json');
        foreach ($csvHandles as $name => $h) {
            $zip->addFile($h['path'], $name);
        }
        $zip->close();

        // ZipArchive holds source files open until close(); only delete the
        // backing temp files after the archive is finalised.
        $sha = hash_file('sha256', $zipPath);
        $size = filesize($zipPath);

        @unlink($jsonPath);
        foreach ($csvHandles as $h) {
            @unlink($h['path']);
        }

        return [
            'path' => $zipPath,
            'sha256' => $sha,
            'size' => $size,
            'record_counts' => $this->normaliseRecordCounts($recordCounts),
        ];
    }

    /**
     * Legacy entry point — fully materialised payload. Kept for tests and any
     * caller that already has a payload in hand. Memory-bounded callers
     * should use writeStreaming() instead.
     *
     * @return array{path: string, sha256: string, size: int, record_counts: array<string, int>}
     */
    public function write(array $payload): array
    {
        $path = $this->reserveZipPath();

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

    private function reserveZipPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for export zip.');
        }
        // tempnam creates an empty file; ZipArchive::CREATE refuses to overwrite a
        // non-zip file in some PHP versions. Unlink it so ZipArchive opens fresh.
        @unlink($path);

        return $path.'.zip';
    }

    /**
     * Stream rows into an inline (top-level) JSON array, also routing them to
     * a CSV file if csv_columns is set. Returns the row count.
     *
     * @param  resource  $jh
     * @param  array<string, array{path: string, fp: resource, columns: array<string>}>  $csvHandles
     */
    private function streamRowsInline($jh, Generator $rows, array &$csvHandles, ?array $csvColumns, string $sectionName): int
    {
        fwrite($jh, '[');
        $count = 0;
        foreach ($rows as $row) {
            if ($count > 0) {
                fwrite($jh, ',');
            }
            fwrite($jh, json_encode($row, JSON_UNESCAPED_SLASHES));
            if ($csvColumns !== null) {
                $this->writeCsvRow($csvHandles, $sectionName, $csvColumns, $row);
            }
            $count++;
        }
        fwrite($jh, ']');

        return $count;
    }

    /**
     * Stream rows for a nested-group child section to a scratch JSON file.
     * Returns the scratch path + count so emitGroup can splice the array
     * into the parent group's JSON object.
     *
     * @param  resource  $jh  (unused — kept for symmetry with inline)
     * @param  array<string, array{path: string, fp: resource, columns: array<string>}>  $csvHandles
     * @return array{placeholder_path: string, count: int}
     */
    private function streamRowsToJson($jh, Generator $rows, array &$csvHandles, ?array $csvColumns, string $sectionName): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export-grp-');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for group section.');
        }
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Failed to open group temp for writing: {$path}");
        }

        fwrite($fp, '[');
        $count = 0;
        foreach ($rows as $row) {
            if ($count > 0) {
                fwrite($fp, ',');
            }
            fwrite($fp, json_encode($row, JSON_UNESCAPED_SLASHES));
            if ($csvColumns !== null) {
                $this->writeCsvRow($csvHandles, $sectionName, $csvColumns, $row);
            }
            $count++;
        }
        fwrite($fp, ']');
        fclose($fp);

        return ['placeholder_path' => $path, 'count' => $count];
    }

    /**
     * @param  resource  $jh
     */
    private function writeJsonEntry($jh, string $key, mixed $value, bool $first): void
    {
        if (! $first) {
            fwrite($jh, ",\n");
        }
        fwrite($jh, json_encode($key).': '.json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  resource  $jh
     */
    private function beginJsonEntry($jh, string $key, bool $first): void
    {
        if (! $first) {
            fwrite($jh, ",\n");
        }
        fwrite($jh, json_encode($key).': ');
    }

    /**
     * @param  resource  $jh
     * @param  array{values: array<string, mixed>, rows: array<string, string>}  $payload
     */
    private function emitGroup($jh, string $group, array $payload, bool $first): void
    {
        if (! $first) {
            fwrite($jh, ",\n");
        }
        fwrite($jh, json_encode($group).': {');

        $firstChild = true;
        foreach ($payload['values'] ?? [] as $key => $value) {
            if (! $firstChild) {
                fwrite($jh, ',');
            }
            fwrite($jh, json_encode($key).':'.json_encode($value, JSON_UNESCAPED_SLASHES));
            $firstChild = false;
        }
        foreach ($payload['rows'] ?? [] as $key => $scratchPath) {
            if (! $firstChild) {
                fwrite($jh, ',');
            }
            fwrite($jh, json_encode($key).':');
            $rh = fopen($scratchPath, 'rb');
            if ($rh === false) {
                throw new RuntimeException("Failed to read group scratch file: {$scratchPath}");
            }
            stream_copy_to_stream($rh, $jh);
            fclose($rh);
            $firstChild = false;
        }

        fwrite($jh, '}');
    }

    /**
     * @param  array<string, array{path: string, fp: resource, columns: array<string>}>  $csvHandles
     */
    private function writeCsvRow(array &$csvHandles, string $sectionName, array $columns, array $row): void
    {
        $csvName = $this->csvNameFor($sectionName);
        if (! isset($csvHandles[$csvName])) {
            $path = tempnam(sys_get_temp_dir(), 'export-csv-');
            if ($path === false) {
                throw new RuntimeException('Failed to create temp file for csv.');
            }
            $fp = fopen($path, 'wb');
            if ($fp === false) {
                throw new RuntimeException("Failed to open csv temp for writing: {$path}");
            }
            fputcsv($fp, $columns);
            $csvHandles[$csvName] = ['path' => $path, 'fp' => $fp, 'columns' => $columns];
        }

        $line = [];
        foreach ($columns as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($csvHandles[$csvName]['fp'], $line);
    }

    private function csvNameFor(string $sectionName): string
    {
        // bookings.booking_events → bookings.csv (single CSV per group child),
        // otherwise plain section.csv.
        return match ($sectionName) {
            'customers' => 'customers.csv',
            'enquiries' => 'enquiries.csv',
            'bookings.booking_events' => 'bookings.csv',
            'billing.commission_payouts' => 'commission_payouts.csv',
            default => str_replace('.', '_', $sectionName).'.csv',
        };
    }

    /**
     * Normalise record-count keys to the legacy flat schema audit consumers
     * expect: bookings.booking_events → booking_events, etc.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function normaliseRecordCounts(array $counts): array
    {
        $out = [];
        foreach ($counts as $key => $value) {
            $out[$this->recordCountKey($key)] = $value;
        }

        return $out;
    }

    private function recordCountKey(string $sectionName): string
    {
        $tail = strrchr($sectionName, '.');

        return $tail === false ? $sectionName : ltrim($tail, '.');
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
