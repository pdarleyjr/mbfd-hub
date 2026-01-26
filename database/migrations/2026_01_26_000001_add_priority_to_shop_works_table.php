<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_works', function (Blueprint $table) {
            $table->integer('priority')->default(5)->after('status');
            $table->string('category')->nullable()->after('project_name');
            $table->integer('quantity')->default(1)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('shop_works', function (Blueprint $table) {
            $table->dropColumn(['priority', 'category', 'quantity']);
        });
    }
};
