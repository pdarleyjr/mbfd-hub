<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CloudflareUsageBudget extends Model
{
    protected $fillable = [
        'cycle_start',
        'cycle_end',
        'provider_chargeable_used',
        'provider_verified_destination_used',
        'provider_daily_quota',
        'provider_daily_used',
        'hub_safe_ceiling',
        'worker_requests_used',
        'worker_cpu_ms_used',
        'worker_request_threshold',
        'worker_cpu_ms_threshold',
        'reconciled_at',
        'provider_daily_reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start' => 'immutable_datetime',
            'cycle_end' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'provider_daily_reconciled_at' => 'immutable_datetime',
        ];
    }
}
