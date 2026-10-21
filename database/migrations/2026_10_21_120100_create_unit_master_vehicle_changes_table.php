<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field-level history for master vehicle records. One row per field change:
 * "applied" changes already reached the master row; "proposed" ones are
 * contradictions (FY27 list or a take-home inspection) waiting for Fleet to
 * accept or reject. Nothing here is ever rewritten after the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_master_vehicle_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_master_vehicle_id')->constrained('unit_master_vehicles')->cascadeOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('status', 16); // applied | proposed | accepted | rejected
            $table->string('source', 48); // fy27_reconciliation | take_home_inspection | take_home_new_vehicle
            $table->string('source_reference')->nullable();
            $table->foreignId('take_home_vehicle_inspection_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['unit_master_vehicle_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_master_vehicle_changes');
    }
};
