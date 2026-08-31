<?php

declare(strict_types=1);

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReadinessProbe
{
    /** @return array{database: bool, redis: bool} */
    public function check(): array
    {
        return [
            'database' => $this->databaseIsAvailable(),
            'redis' => $this->redisIsAvailable(),
        ];
    }

    private function databaseIsAvailable(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisIsAvailable(): bool
    {
        try {
            Cache::store()->get('mbfd:health-readiness');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
