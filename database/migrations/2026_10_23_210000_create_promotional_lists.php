<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Additive only. Promotional lists, their ranked entries and an append-only history,
 * plus the direct capability personnel.promotions.manage.
 *
 * The capability is created and granted to nobody: the production deploy gate refuses permission
 * changes on established accounts, so it is given deliberately in the Admin capability editor.
 */
return new class extends Migration
{
    private const PERMISSION = 'personnel.promotions.manage';

    public function up(): void
    {
        Schema::create('promotional_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('list_type', 40);
            $table->string('type_label', 80)->nullable();
            $table->string('title', 200);
            $table->date('effective_date');
            $table->date('expires_on')->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('source_reference');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['list_type', 'effective_date']);
            $table->index(['status', 'expires_on']);
        });

        Schema::create('promotional_list_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('list_id')->constrained('promotional_lists')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('soi_id', 40)->nullable();
            $table->string('last_name', 120);
            $table->string('first_name', 120);
            $table->unsignedInteger('original_rank');
            $table->jsonb('scores');
            $table->string('status', 16)->default('eligible');
            $table->text('status_reason')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedInteger('current_rank')->nullable();
            $table->timestamps();
            $table->unique(['list_id', 'original_rank']);
            $table->unique(['list_id', 'employee_id']);
            $table->index(['list_id', 'status', 'current_rank']);
            $table->index('employee_id');
        });

        Schema::create('promotional_list_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('list_id')->constrained('promotional_lists')->restrictOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('promotional_list_entries')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->jsonb('metadata');
            $table->timestamp('created_at')->nullable();
            $table->index(['list_id', 'created_at']);
        });

        Permission::findOrCreate(self::PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Promotional rankings and their history are official records and must survive a code rollback.
        if (DB::table('promotional_lists')->exists()) {
            throw new LogicException('Promotional list data must be retained during rollback.');
        }
        Schema::dropIfExists('promotional_list_events');
        Schema::dropIfExists('promotional_list_entries');
        Schema::dropIfExists('promotional_lists');
    }
};
