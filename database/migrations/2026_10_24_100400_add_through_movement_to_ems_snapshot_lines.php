<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A count line remembers the newest stock movement that existed when the person typed it
 * ("through_movement_id"), so issuances made while a draft count is open are never lost when it is
 * finalized. Lines without a value (reconciliation lines, older rows) fall back to the snapshot's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_inventory_snapshot_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('through_movement_id')->nullable()->after('warnings');
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('EMS inventory tables hold request, stock and audit history and are never altered backwards outside local or testing.');
        }
        Schema::table('ems_inventory_snapshot_lines', function (Blueprint $table): void {
            $table->dropColumn('through_movement_id');
        });
    }
};
