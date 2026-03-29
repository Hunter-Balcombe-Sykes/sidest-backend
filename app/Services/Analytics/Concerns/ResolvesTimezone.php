<?php

namespace App\Services\Analytics\Concerns;

use App\Models\Core\Professional\Professional;

trait ResolvesTimezone
{
    /** @var array<string, string> */
    private array $timezoneCache = [];

    private function professionalTimezone(string $professionalId): string
    {
        if (isset($this->timezoneCache[$professionalId])) {
            return $this->timezoneCache[$professionalId];
        }

        $timezone = Professional::query()
            ->where('id', $professionalId)
            ->value('timezone');

        $timezone = trim((string) $timezone);
        $resolved = $timezone !== '' ? $timezone : 'UTC';

        try {
            new \DateTimeZone($resolved);
        } catch (\Exception) {
            $resolved = 'UTC';
        }

        $this->timezoneCache[$professionalId] = $resolved;

        return $resolved;
    }
}
