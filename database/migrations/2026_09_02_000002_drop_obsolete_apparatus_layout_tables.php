<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('apparatus_layout_snapshots');
        Schema::dropIfExists('apparatus_compartments');
        Schema::dropIfExists('apparatus_layout_tools');
    }

    public function down(): void
    {
        Schema::create('apparatus_layout_tools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->decimal('length', 8, 2);
            $table->decimal('width', 8, 2);
            $table->decimal('height', 8, 2);
            $table->decimal('weight', 8, 2)->nullable();
            $table->boolean('can_rotate')->default(true);
            $table->boolean('requires_clearance')->default(false);
            $table->decimal('clearance_depth', 8, 2)->nullable();
            $table->string('icon_path')->nullable();
            $table->string('color')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('category');
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('apparatus_compartments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            $table->string('label');
            $table->string('side');
            $table->decimal('width', 8, 2);
            $table->decimal('height', 8, 2);
            $table->decimal('depth', 8, 2);
            $table->string('shelf_type')->default('fixed');
            $table->integer('shelf_count')->default(0);
            $table->boolean('has_pegboard')->default(false);
            $table->json('pegboard_faces')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['apparatus_id', 'side']);
        });

        Schema::create('apparatus_layout_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->jsonb('placements');
            $table->boolean('is_auto_save')->default(false);
            $table->boolean('is_published')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['apparatus_id', 'user_id']);
            $table->index(['apparatus_id', 'is_published']);
            $table->index(['user_id', 'is_auto_save']);
        });
    }
};
