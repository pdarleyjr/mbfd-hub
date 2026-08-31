<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Health\ReadinessProbe;
use Illuminate\Http\JsonResponse;

final class HealthReadinessController
{
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $checks = $probe->check();
        $ready = $checks['database'] && $checks['redis'];

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
        ], $ready ? 200 : 503);
    }
}
