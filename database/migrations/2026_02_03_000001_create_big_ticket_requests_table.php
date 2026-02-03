<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('big_ticket_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained();
            $table->string('room_type'); // kitchen, common_areas, dorms, apparatus_bay, watch_office
            $table->string('room_label')->nullable();
            $table->json('items'); // selected items array
            $table->string('other_item')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('big_ticket_requests');
    }
};