<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/apparatus-issue-history.md section 3. New table only, nothing existing changes.
 *
 * One append-only row per concern, note or damage report a member sends with a Daily Checkout.
 * apparatus_defect_id is the one column filled in after the row is written (an issue becomes,
 * or joins, a defect inside the same submission transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_inspection_concerns', function (Blueprint $table): void {
            $table->id();
            // The physical apparatus row the checkout settled on, not necessarily the unit's own row.
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->foreignId('apparatus_inspection_id')->constrained('apparatus_inspections')->restrictOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name', 255)->nullable();
            $table->uuid('client_id');
            $table->string('kind', 8);
            $table->text('description');
            $table->string('location', 255)->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('apparatus_defect_id')->nullable()->constrained('apparatus_defects')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['apparatus_inspection_id', 'client_id'], 'inspection_concerns_inspection_client_unique');
            // Newest notes first for one apparatus.
            $table->index(['apparatus_id', 'kind', 'id'], 'inspection_concerns_apparatus_kind_index');
            $table->index('apparatus_defect_id', 'inspection_concerns_defect_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_inspection_concerns');
    }
};
