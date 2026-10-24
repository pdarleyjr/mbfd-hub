<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Keeps the cleaned vehicle identification section on the inspection that carried
 * it (so answers a failed savepoint lost can be replayed), and lets an apparatus be deleted
 * without being blocked by its identification questions and history. The history goes with the
 * unit: it describes nothing once the unit is gone, and the vehicle record's own change history
 * keeps every change that reached the vehicle record. No access is granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->json('vehicle_identification')->nullable();
        });

        foreach (['apparatus_identification_questions', 'apparatus_identification_events'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['apparatus_id']);
            });
            Schema::table($name, function (Blueprint $table): void {
                $table->foreign('apparatus_id')->references('id')->on('apparatuses')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['apparatus_identification_questions', 'apparatus_identification_events'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['apparatus_id']);
            });
            Schema::table($name, function (Blueprint $table): void {
                $table->foreign('apparatus_id')->references('id')->on('apparatuses')->restrictOnDelete();
            });
        }

        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropColumn('vehicle_identification');
        });
    }
};
