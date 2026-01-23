<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatuses', function (Blueprint $table) {
            $table->id();
            $table->string('unit_id')->unique();
            $table->string('vin')->nullable();
            $table->string('make');
            $table->string('model');
            $table->integer('year')->nullable();
            $table->enum('status', ['In Service', 'Out of Service', 'Maintenance'])->default('In Service');
            $table->integer('mileage')->default(0);
            $table->date('last_service_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatuses');
    }
};
