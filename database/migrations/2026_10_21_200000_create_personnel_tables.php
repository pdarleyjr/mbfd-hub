<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_tables', function (Blueprint $table): void {
            $table->id();
            $table->string('directory_key', 32)->nullable()->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('private');
            $table->string('membership_mode', 16)->default('fixed');
            $table->jsonb('criteria');
            $table->jsonb('source_columns');
            $table->jsonb('columns');
            $table->jsonb('retired_columns');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_by', 'visibility']);
        });

        Schema::create('personnel_table_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('table_id')->constrained('personnel_tables')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->jsonb('values');
            $table->string('highlight', 16)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['table_id', 'employee_id']);
            $table->index(['table_id', 'removed_at']);
        });

        Schema::create('personnel_table_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('table_id')->constrained('personnel_tables')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('settings');
            $table->timestamps();
            $table->unique(['table_id', 'user_id', 'name']);
        });

        Schema::create('personnel_table_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('table_id')->constrained('personnel_tables')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->jsonb('metadata');
            $table->timestamps();
            $table->index(['table_id', 'created_at']);
        });

        foreach ([
            'personnel.directory.view', 'personnel.directory.edit', 'personnel.directory.export',
            'personnel.tables.view', 'personnel.tables.create', 'personnel.tables.edit',
            'personnel.tables.share', 'personnel.tables.export', 'personnel.tables.archive',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Code rollback must retain administrative tracking and its audit trail.
        if (DB::table('personnel_tables')->exists()) {
            throw new LogicException('Personnel tracking data must be retained during rollback.');
        }
        Schema::dropIfExists('personnel_table_events');
        Schema::dropIfExists('personnel_table_views');
        Schema::dropIfExists('personnel_table_entries');
        Schema::dropIfExists('personnel_tables');
    }
};
