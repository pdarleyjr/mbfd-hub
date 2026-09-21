<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            // Null preserves the interpretation of every historical inspection.
            $table->string('processing_status', 32)->nullable()->index();
            $table->json('checklist_evidence')->nullable();
        });
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->string('operational_impact', 32)->default('unclassified');
            $table->foreignId('service_ticket_id')->nullable()->constrained('apparatus_service_tickets')->restrictOnDelete();
        });
        Schema::create('apparatus_inspection_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inspection_id')->constrained('apparatus_inspections')->restrictOnDelete();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->string('field', 64);
            $table->string('reason', 64);
            $table->decimal('submitted_value', 16, 1)->nullable();
            $table->decimal('baseline_value', 16, 1)->nullable();
            $table->decimal('authoritative_value', 16, 1)->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['apparatus_inspection_id', 'field'], 'inspection_exception_field_unique');
        });
        Schema::create('apparatus_defect_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_defect_id')->constrained('apparatus_defects')->restrictOnDelete();
            $table->foreignId('apparatus_inspection_id')->constrained('apparatus_inspections')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observation', 32);
            $table->string('reported_status', 16)->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->unique(['apparatus_defect_id', 'apparatus_inspection_id'], 'defect_inspection_observation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_defect_observations');
        Schema::dropIfExists('apparatus_inspection_exceptions');
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_ticket_id');
            $table->dropColumn('operational_impact');
        });
        Schema::table('apparatus_inspections', fn (Blueprint $table) => $table->dropColumn(['processing_status', 'checklist_evidence']));
    }
};
