<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Fuel PINs (the City fuel list "Driver Prompt ID").
 *
 * fuel_id_sources keeps every imported file on the private disk as the preserved source record, identified by its
 * SHA-256 so the same file is never imported twice. fuel_id_rows keeps every row of every source; the PIN is
 * encrypted at rest and stays a string so leading zeros survive. current_employee_id is set only on the one row that
 * holds an employee's current PIN; its unique index enforces one current PIN per employee on every database.
 * fuel_id_events is the audit trail (imports, reveals, resolutions, source views). It never stores a PIN.
 *
 * Permissions are created here and granted only through Employees & Access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_id_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->char('sha256', 64)->unique();
            $table->char('data_sha256', 64)->nullable()->index();
            $table->string('original_name', 255);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('file_size');
            $table->string('format', 16);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('exception_count')->default(0);
            $table->timestamp('imported_at');
            $table->timestamps();
        });

        Schema::create('fuel_id_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fuel_id_source_id')->constrained('fuel_id_sources')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->unsignedSmallInteger('page')->nullable();
            $table->string('source_employee_number', 32);
            $table->string('normalized_employee_number', 32)->index();
            $table->string('last_name', 120)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->text('prompt_id');
            $table->string('status', 32)->nullable();
            $table->string('department', 64)->nullable();
            $table->string('match_status', 24)->index();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('candidate_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('current_employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->boolean('employee_departed')->default(false);
            $table->foreignId('superseded_by_row_id')->nullable()->constrained('fuel_id_rows')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->unique(['fuel_id_source_id', 'row_number']);
            $table->index(['employee_id', 'match_status']);
        });

        Schema::create('fuel_id_events', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 40)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fuel_id_source_id')->nullable()->constrained('fuel_id_sources')->nullOnDelete();
            $table->foreignId('fuel_id_row_id')->nullable()->constrained('fuel_id_rows')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['fuel_id_row_id', 'created_at']);
        });

        foreach (['personnel.fuel_ids.view', 'personnel.fuel_ids.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Imported fuel lists are department records: a code rollback must keep them.
        if (DB::table('fuel_id_sources')->exists()) {
            throw new LogicException('Imported fuel ID sources must be retained during rollback.');
        }
        Schema::dropIfExists('fuel_id_events');
        Schema::dropIfExists('fuel_id_rows');
        Schema::dropIfExists('fuel_id_sources');
    }
};
