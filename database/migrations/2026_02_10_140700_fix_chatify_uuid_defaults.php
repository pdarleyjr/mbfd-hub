<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix ch_messages table - add default gen_random_uuid() to id column
        if (Schema::hasTable('ch_messages')) {
            DB::statement('ALTER TABLE ch_messages ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
        
        // Fix ch_favorites table - add default gen_random_uuid() to id column
        if (Schema::hasTable('ch_favorites')) {
            DB::statement('ALTER TABLE ch_favorites ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove defaults
        if (Schema::hasTable('ch_messages')) {
            DB::statement('ALTER TABLE ch_messages ALTER COLUMN id DROP DEFAULT');
        }
        if (Schema::hasTable('ch_favorites')) {
            DB::statement('ALTER TABLE ch_favorites ALTER COLUMN id DROP DEFAULT');
        }
    }
};
