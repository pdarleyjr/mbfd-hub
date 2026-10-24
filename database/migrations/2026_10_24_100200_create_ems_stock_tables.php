<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medical Supply Room stock ledger (docs/ems/ems-inventory-contract.md section 4 and 6).
 * Additive only. Final snapshots, snapshot lines and stock movements are immutable (enforced by
 * the models); corrections are new compensating rows that reference the corrected movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ems_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Assigned when the snapshot is finalized (drafts have none), so version order is finalization order.
            $table->unsignedInteger('version')->nullable()->unique();
            $table->string('kind', 20);
            $table->string('status', 8)->default('draft');
            $table->string('scope_location_code', 24)->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->string('finalized_name', 160)->nullable();
            $table->timestampTz('cutoff_at')->nullable();
            $table->unsignedBigInteger('through_movement_id')->nullable();
            $table->foreignId('previous_snapshot_id')->nullable()->constrained('ems_inventory_snapshots')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'version']);
        });

        Schema::create('ems_inventory_snapshot_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('ems_inventory_snapshots')->restrictOnDelete();
            $table->foreignId('supply_item_id')->constrained('ems_supply_items')->restrictOnDelete();
            $table->string('location_code', 24)->nullable();
            $table->string('item_name', 255);
            $table->integer('quantity_base')->nullable();
            $table->json('entry')->nullable();
            $table->string('value_source', 16);
            $table->json('warnings')->nullable();
            $table->timestampsTz();
            $table->unique(['snapshot_id', 'supply_item_id']);
            $table->index('supply_item_id');
        });

        Schema::create('ems_supply_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supply_item_id')->constrained('ems_supply_items')->restrictOnDelete();
            $table->string('status', 12)->default('placed');
            $table->unsignedInteger('packages');
            $table->integer('quantity_base')->nullable();
            $table->string('supplier_code', 40)->nullable();
            $table->string('item_number', 80)->nullable();
            $table->foreignId('placed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('placed_by_name', 160)->nullable();
            $table->timestampTz('placed_at');
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestampsTz();
            $table->index(['supply_item_id', 'status']);
        });

        Schema::create('ems_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supply_item_id')->constrained('ems_supply_items')->restrictOnDelete();
            $table->string('movement_type', 12);
            $table->integer('quantity_base');
            $table->timestampTz('occurred_at');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 160)->nullable();
            $table->text('reason')->nullable();
            // History rows are never rewritten, so a referenced parent can never be deleted out from under them.
            $table->foreignId('request_id')->nullable()->constrained('ems_inventory_requests')->restrictOnDelete();
            $table->foreignId('request_line_id')->nullable()->constrained('ems_inventory_request_lines')->restrictOnDelete();
            $table->foreignId('supply_order_id')->nullable()->constrained('ems_supply_orders')->restrictOnDelete();
            $table->unsignedBigInteger('corrects_movement_id')->nullable()->index();
            $table->foreign('corrects_movement_id')->references('id')->on('ems_stock_movements')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->timestampTz('created_at');
            $table->index(['supply_item_id', 'id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('EMS inventory tables hold request, stock and audit history and are never dropped outside local or testing.');
        }
        Schema::dropIfExists('ems_stock_movements');
        Schema::dropIfExists('ems_supply_orders');
        Schema::dropIfExists('ems_inventory_snapshot_lines');
        Schema::dropIfExists('ems_inventory_snapshots');
    }
};
