<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply room order workflow (docs/ems/captain5-medical-inventory-workflow.md section 11): an order can be
 * pending, ordered or back ordered before it arrives, and every change is kept. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_supply_orders', function (Blueprint $table): void {
            $table->string('order_unit', 12)->nullable()->after('packages');
            $table->date('expected_on')->nullable()->after('order_unit');
            $table->timestampTz('status_changed_at')->nullable()->after('expected_on');
            $table->string('status_changed_by_name', 160)->nullable()->after('status_changed_at');
        });

        Schema::create('ems_supply_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supply_order_id')->constrained('ems_supply_orders')->restrictOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->integer('quantity_base')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 160);
            $table->text('note')->nullable();
            $table->timestampTz('created_at');
            $table->index(['supply_order_id', 'id']);
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Supply order history is never dropped outside local or testing.');
        }
        Schema::dropIfExists('ems_supply_order_events');
        Schema::table('ems_supply_orders', function (Blueprint $table): void {
            $table->dropColumn(['order_unit', 'expected_on', 'status_changed_at', 'status_changed_by_name']);
        });
    }
};
