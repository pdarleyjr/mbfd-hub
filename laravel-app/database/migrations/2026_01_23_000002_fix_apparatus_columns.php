<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing columns if they don't exist
        if (!Schema::hasColumn('apparatuses', 'name')) {
            DB::statement('ALTER TABLE apparatuses ADD COLUMN name VARCHAR(255)');
        }
        if (!Schema::hasColumn('apparatuses', 'type')) {
            DB::statement('ALTER TABLE apparatuses ADD COLUMN type VARCHAR(255)');
        }
        if (!Schema::hasColumn('apparatuses', 'slug')) {
            DB::statement('ALTER TABLE apparatuses ADD COLUMN slug VARCHAR(255) UNIQUE');
        }
        
        // Mark the problematic migration as ran if not already
        $exists = DB::table('migrations')
            ->where('migration', '2026_01_22_000005_add_name_slug_to_apparatuses')
            ->exists();
            
        if (!$exists) {
            DB::table('migrations')->insert([
                'migration' => '2026_01_22_000005_add_name_slug_to_apparatuses',
                'batch' => 5
            ]);
        }
    }

    public function down(): void
    {
        // Do nothing - this is a one-time fix
    }
};
