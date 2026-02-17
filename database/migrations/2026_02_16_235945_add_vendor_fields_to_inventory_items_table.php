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
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('vendor_url')->nullable()->after('sku');
            $table->string('vendor_name')->nullable()->after('vendor_url');
            $table->string('vendor_sku')->nullable()->after('vendor_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['vendor_url', 'vendor_name', 'vendor_sku']);
        });
    }
};
