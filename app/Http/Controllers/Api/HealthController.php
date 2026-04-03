<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

// V2: Liveness/readiness probe. Checks database and Redis connectivity with response times for deployment health checks.
class HealthController extends ApiController
{
    public function check(): JsonResponse
    {
        $db = $this->checkDatabase();
        $cache = $this->checkCache();

        $status = [
            'app' => 'healthy',
            'database' => $db,
            'cache' => $cache,
        ];

        $allHealthy = ($db['status'] === 'healthy') && ($cache['status'] === 'healthy');

        return $this->success($status, $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::connection()->getPdo();
            $ms = (microtime(true) - $start) * 1000;

            return ['status' => 'healthy', 'ms' => $ms];
        } catch (Throwable $e) {
            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => 'unhealthy',
                'ms' => $ms,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        $start = microtime(true);

        try {
            $store = config('cache.default'); // or config('cache.default') depending on your config
            $key = 'health:cache:' . bin2hex(random_bytes(8));
            $value = bin2hex(random_bytes(8));

            Cache::put($key, $value, now()->addSeconds(10));
            $read = Cache::get($key);
            Cache::forget($key);

            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => ($read === $value) ? 'healthy' : 'unhealthy',
                'ms' => $ms,
            ];
        } catch (Throwable $e) {
            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => 'unhealthy',
                'ms' => $ms,
                'error' => $e->getMessage(),
            ];
        }
    }
}
