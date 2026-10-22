<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: bounded redelivery of ledger rows left `queued` (inspections:retry-notifications). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_notification_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->index(['status', 'updated_at'], 'inspection_notification_deliveries_status_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('inspection_notification_deliveries_status_updated_index');
        });
        Schema::table('inspection_notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn('attempts');
        });
    }
};
