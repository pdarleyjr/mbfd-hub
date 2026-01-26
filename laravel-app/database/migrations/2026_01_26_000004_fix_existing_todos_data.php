<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Set default status for all existing todos that don't have one
        DB::table('todos')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'pending']);
            
        // Fix todos marked as complete to have 'completed' status
        DB::table('todos')
            ->where('is_completed', true)
            ->update(['status' => 'completed']);
    }

    public function down(): void
    {
        // No need to revert
    }
};
