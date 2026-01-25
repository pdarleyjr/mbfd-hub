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
        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->renameColumn('stocker_id', 'stockable_id');
            $table->renameColumn('stocker_type', 'stockable_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->renameColumn('stockable_id', 'stocker_id');
            $table->renameColumn('stockable_type', 'stocker_type');
        });
    }
};
