<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Captain 5 EMS Operations Center (docs/captain5-ems-operations-center.md section 3).
 * Additive only. Issues, events and messages stay in the existing continuity tables.
 */
return new class extends Migration
{
    private const ACTIVE_ASSIGNMENT_INDEX = 'captain5_duty_assignments_one_active_per_day';

    private const CURRENT_OVERRIDE_INDEX = 'captain5_access_overrides_one_current';

    private const CURRENT_CLASSIFICATION_INDEX = 'captain5_item_classifications_one_current';

    public function up(): void
    {
        Schema::create('captain5_duty_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->date('duty_date');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('shift_letter', 1)->nullable();
            $table->text('reason');
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestampsTz();
            $table->index(['employee_profile_id', 'duty_date']);
            $table->index(['starts_at', 'ends_at']);
        });
        DB::statement('CREATE UNIQUE INDEX '.self::ACTIVE_ASSIGNMENT_INDEX.' ON captain5_duty_assignments (duty_date) WHERE revoked_at IS NULL');

        Schema::create('captain5_access_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->string('effect', 8);
            $table->string('level', 8)->nullable();
            $table->text('reason');
            $table->foreignId('set_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('superseded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['employee_profile_id', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX '.self::CURRENT_OVERRIDE_INDEX.' ON captain5_access_overrides (employee_profile_id) WHERE superseded_at IS NULL');

        Schema::create('captain5_item_classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('checklist_type', 40);
            $table->string('item_key', 128);
            $table->string('item_name', 255);
            $table->string('classification', 16);
            $table->text('reason');
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('superseded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['checklist_type', 'item_key']);
        });
        DB::statement('CREATE UNIQUE INDEX '.self::CURRENT_CLASSIFICATION_INDEX.' ON captain5_item_classifications (checklist_type, item_key) WHERE superseded_at IS NULL');

        Schema::create('captain5_handoff_notes', function (Blueprint $table): void {
            $table->id();
            $table->date('duty_date');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('author_authority', 32);
            $table->text('body');
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->index(['duty_date', 'created_at']);
        });

        Permission::findOrCreate('captain5.admin', 'web');
    }

    public function down(): void
    {
        Schema::dropIfExists('captain5_handoff_notes');
        DB::statement('DROP INDEX IF EXISTS '.self::CURRENT_CLASSIFICATION_INDEX);
        Schema::dropIfExists('captain5_item_classifications');
        DB::statement('DROP INDEX IF EXISTS '.self::CURRENT_OVERRIDE_INDEX);
        Schema::dropIfExists('captain5_access_overrides');
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_ASSIGNMENT_INDEX);
        Schema::dropIfExists('captain5_duty_assignments');
        Permission::query()->where('name', 'captain5.admin')->where('guard_name', 'web')->delete();
    }
};
