<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create Chatify tables if they don't exist.
     * These may already be created by the chatify vendor migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ch_messages')) {
            Schema::create('ch_messages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('from_id');
                $table->unsignedBigInteger('to_id');
                $table->text('body')->nullable();
                $table->json('attachment')->nullable();
                $table->boolean('seen')->default(false);
                $table->timestamps();
            });
            // Set default UUID generation
            DB::statement('ALTER TABLE ch_messages ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }

        if (!Schema::hasTable('ch_favorites')) {
            Schema::create('ch_favorites', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('friend_id');
                $table->timestamps();
            });
            DB::statement('ALTER TABLE ch_favorites ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ch_messages');
        Schema::dropIfExists('ch_favorites');
    }
};
