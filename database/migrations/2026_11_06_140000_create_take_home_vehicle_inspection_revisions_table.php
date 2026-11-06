<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member corrections to a submitted Take Home Vehicle Inspection. The submitted
 * row stays exactly as signed; each correction is a new, immutable revision that
 * records who changed what (before and after) and the full corrected answers.
 * The latest revision is what the report, PDF and dashboards show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('take_home_vehicle_inspection_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('take_home_vehicle_inspection_id')
                ->constrained('take_home_vehicle_inspections', indexName: 'thvi_revisions_inspection_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('revised_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('revised_by_name');
            // The edit form's one-time id: a double tap or retry never records a second revision.
            $table->uuid('client_revision_id')->unique();
            // {field: {from, to}} for every answer this revision changed.
            $table->json('changed_fields');
            // The complete corrected answers after this revision.
            $table->json('answers');
            $table->text('reason')->nullable();
            // True when the correction reports a problem the inspection did not have before.
            $table->boolean('added_problem')->default(false);
            $table->timestamps();

            $table->unique(['take_home_vehicle_inspection_id', 'revision_number'], 'thvi_revisions_number_unique');
            $table->index(['added_problem', 'created_at'], 'thvi_revisions_problem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('take_home_vehicle_inspection_revisions');
    }
};
