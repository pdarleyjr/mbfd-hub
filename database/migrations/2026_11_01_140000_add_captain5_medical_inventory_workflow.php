<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

/**
 * Friday forms and Monday counts (docs/ems/captain5-medical-inventory-workflow.md).
 * Additive only: every existing request, movement and snapshot keeps its meaning, and the previous
 * image keeps working because it never reads these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_inventory_requests', function (Blueprint $table): void {
            $table->string('reviewed_by_name', 160)->nullable()->after('reviewed_by_user_id');
            $table->foreignId('revision_of_request_id')->nullable()->unique()->after('client_submission_id')
                ->constrained('ems_inventory_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('revision_number')->default(1)->after('revision_of_request_id');
            $table->timestampTz('returned_at')->nullable();
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('returned_by_name', 160)->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestampTz('reconciled_at')->nullable()->index();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reconciled_by_name', 160)->nullable();
            $table->uuid('reconciliation_batch')->nullable()->index();
        });

        Schema::table('ems_supply_items', function (Blueprint $table): void {
            // What the person types on the Monday count. Stock itself stays in base units.
            $table->string('count_unit', 12)->default('each')->after('base_unit');
        });

        Schema::table('ems_inventory_snapshots', function (Blueprint $table): void {
            // count_form | direct_edit | workbook; null for counts made before this release or from the Admin page.
            $table->string('origin', 20)->nullable()->after('kind')->index();
            $table->unsignedInteger('counted_items')->nullable();
            $table->unsignedInteger('uncounted_items')->nullable();
            $table->unsignedInteger('order_items')->nullable();
        });

        Schema::table('ems_inventory_snapshot_lines', function (Blueprint $table): void {
            // Stamped at finalize and never recomputed, so a report never changes when the item does.
            $table->string('section_key', 60)->nullable();
            $table->string('count_unit', 12)->nullable();
            $table->unsignedInteger('units_per_count_unit')->nullable();
            $table->unsignedInteger('counted_value')->nullable();
            $table->integer('expected_quantity_base')->nullable();
            $table->unsignedInteger('required_qty_base')->nullable();
            $table->string('threshold_source_text', 120)->nullable();
            $table->unsignedInteger('order_qty_base')->nullable();
            $table->unsignedInteger('order_qty_count_unit')->nullable();
            $table->string('config_problem', 200)->nullable();
        });

        // One decision per catalog identity: which supply room item a form line takes stock from.
        Schema::create('ems_catalog_supply_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_item_id')->unique()->constrained('ems_catalog_items')->restrictOnDelete();
            $table->foreignId('supply_item_id')->nullable()->constrained('ems_supply_items')->restrictOnDelete();
            $table->boolean('not_stocked')->default(false);
            $table->string('source', 20); // reviewed | person
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_name', 160)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('EMS history is never removed outside local and testing; roll back the image only.');
        }

        Schema::dropIfExists('ems_catalog_supply_links');
        Schema::table('ems_inventory_snapshot_lines', function (Blueprint $table): void {
            $table->dropColumn(['section_key', 'count_unit', 'units_per_count_unit', 'counted_value', 'expected_quantity_base', 'required_qty_base', 'threshold_source_text', 'order_qty_base', 'order_qty_count_unit', 'config_problem']);
        });
        Schema::table('ems_inventory_snapshots', function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropColumn(['origin', 'counted_items', 'uncounted_items', 'order_items']);
        });
        Schema::table('ems_supply_items', function (Blueprint $table): void {
            $table->dropColumn('count_unit');
        });
        Schema::table('ems_inventory_requests', function (Blueprint $table): void {
            $table->dropUnique(['revision_of_request_id']);
            $table->dropForeign(['revision_of_request_id']);
            $table->dropForeign(['returned_by_user_id']);
            $table->dropForeign(['reconciled_by_user_id']);
            $table->dropIndex(['reconciled_at']);
            $table->dropIndex(['reconciliation_batch']);
            $table->dropColumn(['reviewed_by_name', 'revision_of_request_id', 'revision_number', 'returned_at', 'returned_by_user_id', 'returned_by_name', 'return_reason', 'reconciled_at', 'reconciled_by_user_id', 'reconciled_by_name', 'reconciliation_batch']);
        });
    }
};
