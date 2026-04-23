<?php

namespace App\Services\Shopify\Client;

use Illuminate\Support\Facades\Redis;

/**
 * Rolling-window tracker for the ratio of actual to requested GraphQL cost,
 * keyed per query hash (sha1 of the query string).
 *
 * Shopify's `requestedQueryCost` is a pre-execution estimate. For list queries
 * with `first: N`, the actual charge can be 5-20x lower. Learning the ratio
 * per query lets us pre-reserve conservatively without being paranoid.
 */
class ShopifyCostTracker
{
    private const MIN_ESTIMATE = 10;
    private const WINDOW_SIZE = 20;
    private const EXPIRY_SECONDS = 86400;

    public function record(string $queryHash, int $requestedCost, int $actualCost): void
    {
        if ($requestedCost <= 0) {
            return;
        }

        $key = $this->key($queryHash);

        Redis::pipeline(function ($pipe) use ($key, $requestedCost, $actualCost) {
            $pipe->lpush($key, "{$requestedCost}:{$actualCost}");
            $pipe->ltrim($key, 0, self::WINDOW_SIZE - 1);
            $pipe->expire($key, self::EXPIRY_SECONDS);
        });
    }

    public function estimate(string $queryHash, int $requestedCost): int
    {
        $samples = Redis::lrange($this->key($queryHash), 0, -1);
        if (empty($samples)) {
            return max(self::MIN_ESTIMATE, $requestedCost);
        }

        $totalRequested = 0;
        $totalActual = 0;
        foreach ($samples as $sample) {
            [$req, $act] = explode(':', $sample);
            $totalRequested += (int) $req;
            $totalActual += (int) $act;
        }

        if ($totalRequested === 0) {
            return max(self::MIN_ESTIMATE, $requestedCost);
        }

        $ratio = $totalActual / $totalRequested;
        $estimate = (int) ceil($requestedCost * $ratio);

        return max(self::MIN_ESTIMATE, $estimate);
    }

    private function key(string $queryHash): string
    {
        return "shopify:cost:{$queryHash}";
    }
}
