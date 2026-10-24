<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Ties each apparatus row (the operational profile) to at most one
 * master vehicle record (the record keeper). The link is unique so one master
 * vehicle never carries two apparatus rows. master_link_status is null until the
 * row has been reconciled, then "linked" or "needs_review"; master_link_note holds
 * the plain-language reason a row needs review. No data is changed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table): void {
            $table->foreignId('unit_master_vehicle_id')->nullable()->unique()
                ->constrained('unit_master_vehicles')->nullOnDelete();
            $table->string('master_link_status', 24)->nullable()->index();
            $table->string('master_link_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table): void {
            $table->dropForeign(['unit_master_vehicle_id']);
            $table->dropUnique(['unit_master_vehicle_id']);
            $table->dropIndex(['master_link_status']);
            $table->dropColumn(['unit_master_vehicle_id', 'master_link_status', 'master_link_note']);
        });
    }
};
