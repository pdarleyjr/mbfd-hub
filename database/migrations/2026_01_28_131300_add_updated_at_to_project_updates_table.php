<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes: SQLSTATE[42703] Undefined column "updated_at" on project_updates table
     */
    public function up(): void
    {
        Schema::table('project_updates', function (Blueprint $table) {
            if (!Schema::hasColumn('project_updates', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->useCurrent();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_updates', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
};
