<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Đo thời gian API ở môi trường local. Không ghi nội dung SQL hoặc dữ liệu nhạy cảm.
 * Xem tab Network -> Headers: Server-Timing và X-QNTD-Queries.
 */
class MeasureApiPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment('local') ||
            (!$request->is('api/*') && !$request->is('login'))) {
            return $next($request);
        }

        $start = hrtime(true);
        $queryCount = 0;
        $databaseMs = 0.0;

        DB::listen(static function (QueryExecuted $query) use (&$queryCount, &$databaseMs): void {
            $queryCount++;
            $databaseMs += (float) $query->time;
        });

        $response = $next($request);
        $totalMs = (hrtime(true) - $start) / 1_000_000;
        $response->headers->set(
            'Server-Timing',
            sprintf('app;dur=%.1f, db;dur=%.1f', $totalMs, $databaseMs)
        );
        $response->headers->set('X-QNTD-Queries', (string) $queryCount);

        return $response;
    }
}
