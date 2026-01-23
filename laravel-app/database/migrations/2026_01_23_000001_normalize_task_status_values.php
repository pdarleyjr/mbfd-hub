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
        DB::table('tasks')->where('status', 'To Do')->update(['status' => 'todo']);
        DB::table('tasks')->where('status', 'In Progress')->update(['status' => 'in_progress']);
        DB::table('tasks')->where('status', 'Blocked')->update(['status' => 'blocked']);
        DB::table('tasks')->where('status', 'Done')->update(['status' => 'done']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tasks')->where('status', 'todo')->update(['status' => 'To Do']);
        DB::table('tasks')->where('status', 'in_progress')->update(['status' => 'In Progress']);
        DB::table('tasks')->where('status', 'blocked')->update(['status' => 'Blocked']);
        DB::table('tasks')->where('status', 'done')->update(['status' => 'Done']);
    }
};
