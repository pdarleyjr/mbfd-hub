<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, nullable device health for push subscriptions: when a device last
 * accepted or refused an alert, how many refusals in a row, when to try again,
 * and a name the member can recognize in their device list.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_failure_reason', 120)->nullable();
            $table->timestamp('backoff_until')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_label', 80)->nullable();
        });
    }

    public function down(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropColumn([
                'last_success_at',
                'last_failure_at',
                'failure_count',
                'last_failure_reason',
                'backoff_until',
                'user_agent',
                'device_label',
            ]);
        });
    }
};
