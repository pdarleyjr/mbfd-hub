<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table): void {
            $table->boolean('track_attendance_matrix')->default(false);
        });
        Schema::create('training_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_course_id')->constrained('training_courses')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->json('audience')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'training_course_id'], 'training_modules_course_identity');
            $table->index(['training_course_id', 'sort_order']);
        });
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('training_module_id')->nullable();
            $table->foreign(['training_module_id', 'training_course_id'], 'training_session_module_course_fk')
                ->references(['id', 'training_course_id'])->on('training_modules')->restrictOnDelete();
            $table->index(['training_course_id', 'training_module_id', 'held_on', 'status'], 'training_session_module_date');
        });
    }

    public function down(): void
    {
        throw new LogicException('Training modules and reporting attribution must be retained during rollback.');
    }
};
