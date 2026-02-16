<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 2: Add vendor fields to inventory_items
        if (!Schema::hasColumn('inventory_items', 'vendor_name')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->string('vendor_name')->nullable()->default('Grainger');
                $table->string('vendor_url')->nullable();
                $table->string('vendor_sku')->nullable();
            });
        }

        // Phase 3: Ordering workflow tables
        if (!Schema::hasTable('station_supply_orders')) {
            Schema::create('station_supply_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sent_via')->nullable(); // resend|gmail|manual
                $table->string('status')->default('draft'); // draft|sent|failed|manual_ordered
                $table->string('subject')->nullable();
                $table->string('to')->nullable();
                $table->string('cc')->nullable();
                $table->string('vendor_name')->nullable()->default('Grainger');
                $table->timestamp('sent_at')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('station_supply_order_lines')) {
            Schema::create('station_supply_order_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('station_supply_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('station_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('station_inventory_item_id')->nullable();
                $table->integer('qty_suggested')->default(0);
                $table->integer('qty_ordered')->nullable();
                $table->integer('qty_delivered')->nullable();
                $table->string('status')->default('pending'); // pending|ordered|delivered|canceled
                $table->timestamps();
            });
        }

        // Phase 4: Email communications table
        if (!Schema::hasTable('communications')) {
            Schema::create('communications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('to');
                $table->string('cc')->nullable();
                $table->string('bcc')->nullable();
                $table->string('subject');
                $table->text('body_html')->nullable();
                $table->string('status')->default('draft'); // draft|sent|failed
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('provider')->nullable(); // gmail_oauth|gmail_smtp
                $table->string('provider_message_id')->nullable();
                $table->unsignedBigInteger('station_supply_order_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
        Schema::dropIfExists('station_supply_order_lines');
        Schema::dropIfExists('station_supply_orders');

        if (Schema::hasColumn('inventory_items', 'vendor_name')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->dropColumn(['vendor_name', 'vendor_url', 'vendor_sku']);
            });
        }
    }
};
