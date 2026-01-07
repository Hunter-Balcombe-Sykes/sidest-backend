<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CacheStats extends Command
{
    protected $signature = 'cache:stats';
    protected $description = 'Show Redis cache statistics';

    public function handle(): int
    {
        $redis = Redis::connection('cache');

        $info = $redis->info('stats');
        $memory = $redis->info('memory');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Keys', $redis->dbsize()],
                ['Memory Used', $this->formatBytes($memory['used_memory'] ?? 0)],
                ['Total Commands', $info['total_commands_processed'] ?? 'N/A'],
                ['Keyspace Hits', $info['keyspace_hits'] ?? 'N/A'],
                ['Keyspace Misses', $info['keyspace_misses'] ?? 'N/A'],
                ['Hit Rate', $this->calculateHitRate($info)],
            ]
        );

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function calculateHitRate(array $info): string
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 'N/A';
        }

        return round(($hits / $total) * 100, 2) . '%';
    }
}
