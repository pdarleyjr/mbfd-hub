<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trt_inventory_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trailer_id')->nullable();
            $table->date('session_date');
            $table->timestamps();

            $table->unique(['trailer_id', 'session_date']);
            $table->index('session_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trt_inventory_sessions');
    }
};
