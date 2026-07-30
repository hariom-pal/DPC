<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Report application and infrastructure health (database, Redis, cache).
     *
     * Returns HTTP 200 when every dependency is reachable, otherwise 503.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(function (): bool {
                DB::connection()->getPdo();

                return true;
            }),
            'redis' => $this->probe(function (): bool {
                Redis::connection()->ping();

                return true;
            }),
            'cache' => $this->probe(function (): bool {
                Cache::put('health:ping', 'ok', 5);

                return Cache::get('health:ping') === 'ok';
            }),
        ];

        $healthy = ! in_array('error', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'laravel' => app()->version(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * Execute a health probe and normalise the result to "ok" / "error".
     *
     * @param  callable():bool  $probe
     */
    private function probe(callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'error';
        } catch (Throwable) {
            return 'error';
        }
    }
}
