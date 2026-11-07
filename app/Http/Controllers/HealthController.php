<?php

namespace App\Http\Controllers;

use App\Services\Ops\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health for an uptime monitor: 200 when everything critical works, 503 when not.
 * The checks themselves are listed only with the health token.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthChecks $health): JsonResponse
    {
        $checks = $health->run();
        $healthy = HealthChecks::healthy($checks);
        $token = (string) config('ops.health_token');
        $authorised = $token !== '' && hash_equals($token, (string) ($request->header('X-Health-Token') ?? $request->query('token', '')));

        return response()->json([
            'status' => $healthy ? 'ok' : 'failing',
            ...($authorised ? ['checks' => $checks] : []),
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
