<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply room stock levels and low-stock alerts (docs/ems/captain5-medical-inventory-workflow.md section 12).
 * Additive only. The two lines are null until a person sets one, which means "the preset": half and a quarter
 * of the expected amount. The alert list is filled by `ems:stock-alert-recipients`, never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_supply_items', function (Blueprint $table): void {
            $table->integer('reorder_at_qty_base')->nullable()->after('threshold_source_text');
            $table->integer('critical_at_qty_base')->nullable()->after('reorder_at_qty_base');
            // The level last seen by the alert check, so an item alerts once when it drops into red.
            $table->string('stock_alert_level', 8)->nullable()->after('critical_at_qty_base');
            $table->timestampTz('stock_alert_level_at')->nullable()->after('stock_alert_level');
        });

        Schema::create('ems_stock_alert_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('added_by_name', 160);
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Roll back with the previous image; this migration is not reversed in production.');
        }
        Schema::dropIfExists('ems_stock_alert_recipients');
        Schema::table('ems_supply_items', function (Blueprint $table): void {
            $table->dropColumn(['reorder_at_qty_base', 'critical_at_qty_base', 'stock_alert_level', 'stock_alert_level_at']);
        });
    }
};
