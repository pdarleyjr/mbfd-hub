<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EMS inventory reference data (docs/ems/ems-inventory-contract.md section 4).
 * Additive only: catalogue, imported source forms, storage locations, supply-room items,
 * the supply item audit, counters (MIR numbers, stock write lock) and the idempotency store.
 * Written for SQLite (tests) and PostgreSQL (production).
 */
return new class extends Migration
{
    private const ONE_ACTIVE_FORM_INDEX = 'ems_source_forms_one_active_per_key';

    public function up(): void
    {
        Schema::create('ems_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 255);
            $table->string('category', 80)->nullable();
            $table->json('aliases')->nullable();
            $table->boolean('refrigerated')->default(false);
            $table->boolean('controlled_substance')->default(false);
            $table->boolean('has_expiration')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('ems_source_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40);
            $table->string('version_label', 60)->nullable();
            $table->string('title', 255);
            $table->string('file_name', 255)->nullable();
            $table->char('sha256', 64);
            $table->json('notes')->nullable();
            $table->timestampTz('imported_at');
            $table->boolean('active')->default(false);
            $table->timestampsTz();
            $table->unique(['key', 'sha256']);
        });
        DB::statement('CREATE UNIQUE INDEX '.self::ONE_ACTIVE_FORM_INDEX.' ON ems_source_forms ("key") WHERE active');

        Schema::create('ems_source_form_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_form_id')->constrained('ems_source_forms')->restrictOnDelete();
            $table->string('item_key', 160);
            $table->foreignId('catalog_item_id')->nullable()->constrained('ems_catalog_items')->nullOnDelete();
            $table->string('section_key', 80);
            $table->string('section_label', 160);
            $table->string('parent_section_key', 80)->nullable();
            $table->string('label', 255);
            $table->string('source_label', 255);
            $table->string('min_text', 120)->nullable();
            $table->unsignedInteger('min_qty')->nullable();
            $table->string('min_unit', 40)->nullable();
            $table->string('min_applies_to', 24)->nullable();
            $table->string('variant_group', 160)->nullable();
            $table->string('variant_label', 160)->nullable();
            $table->string('response_type', 12)->default('quantity');
            $table->json('flags')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->text('review_reason')->nullable();
            $table->json('source_ref')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['source_form_id', 'item_key']);
            $table->index(['source_form_id', 'sort']);
        });

        Schema::create('ems_storage_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('label', 120);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('ems_supply_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_item_id')->nullable()->constrained('ems_catalog_items')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('ems_storage_locations')->nullOnDelete();
            $table->string('source_row_key', 40)->nullable()->unique();
            $table->string('section_key', 80)->nullable();
            $table->string('name', 255);
            $table->string('bag_alias_text', 255)->nullable();
            $table->string('base_unit', 24)->default('each');
            $table->string('package_unit', 24)->nullable();
            $table->unsignedInteger('units_per_package')->nullable();
            $table->unsignedInteger('packages_per_case')->nullable();
            $table->integer('threshold_qty_base')->nullable();
            $table->string('threshold_source_text', 120)->nullable();
            $table->string('qty_per_package_text', 160)->nullable();
            $table->string('order_amount_text', 160)->nullable();
            $table->unsignedInteger('order_qty_packages')->nullable();
            $table->json('suppliers')->nullable();
            $table->json('item_numbers')->nullable();
            $table->boolean('refrigerated')->default(false);
            $table->boolean('controlled_substance')->default(false);
            $table->boolean('has_expiration')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->json('review_reasons')->nullable();
            $table->json('source')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->index(['location_id', 'name']);
            $table->index('catalog_item_id');
        });

        Schema::create('ems_supply_item_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supply_item_id')->constrained('ems_supply_items')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 160)->nullable();
            $table->string('field', 60);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason');
            $table->timestampTz('created_at');
            $table->index(['supply_item_id', 'created_at']);
        });

        // Named counters: the MIR number sequence and the single stock-write lock row.
        Schema::create('ems_counters', function (Blueprint $table): void {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
        DB::table('ems_counters')->insert([['name' => 'mir', 'value' => 0], ['name' => 'stock', 'value' => 0]]);

        // One stored response per (user, scope, key): a replayed write returns the first result.
        Schema::create('ems_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 60);
            $table->string('key', 100);
            $table->json('response')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['user_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('EMS inventory tables hold request, stock and audit history and are never dropped outside local or testing.');
        }
        Schema::dropIfExists('ems_idempotency_keys');
        Schema::dropIfExists('ems_counters');
        Schema::dropIfExists('ems_supply_item_events');
        Schema::dropIfExists('ems_supply_items');
        Schema::dropIfExists('ems_storage_locations');
        Schema::dropIfExists('ems_source_form_items');
        DB::statement('DROP INDEX IF EXISTS '.self::ONE_ACTIVE_FORM_INDEX);
        Schema::dropIfExists('ems_source_forms');
        Schema::dropIfExists('ems_catalog_items');
    }
};
