<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('station_supply_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('station_supply_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_supply_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');
            $table->integer('quantity_ordered')->default(1);
            $table->timestamps();
        });

        // Add vendor fields to inventory_items if they don't exist (defensive)
        if (!Schema::hasColumn('inventory_items', 'vendor_name')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->string('vendor_name')->nullable();
                $table->string('vendor_url')->nullable();
                $table->string('vendor_sku')->nullable();
            });
        }

        // Add SentEmail table if not exists (for logging)
        if (!Schema::hasTable('sent_emails')) {
            Schema::create('sent_emails', function (Blueprint $table) {
                $table->id();
                $table->string('to');
                $table->string('subject');
                $table->text('body');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('station_supply_order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider')->nullable();
                $table->string('message_id')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_supply_order_lines');
        Schema::dropIfExists('station_supply_orders');
        Schema::dropIfExists('sent_emails');

        if (Schema::hasColumn('inventory_items', 'vendor_name')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->dropColumn(['vendor_name', 'vendor_url', 'vendor_sku']);
            });
        }
    }
};
