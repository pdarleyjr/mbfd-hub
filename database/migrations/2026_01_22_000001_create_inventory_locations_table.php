<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->string('location_name')->comment('e.g., Supply Closet');
            $table->char('shelf', 1)->nullable()->comment('A-F');
            $table->integer('row')->nullable()->comment('1-N');
            $table->string('bin')->nullable()->comment('Optional bin identifier');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Unique constraint
            $table->unique(['location_name', 'shelf', 'row', 'bin'], 'location_unique');
            
            // Indexes
            $table->index('location_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_locations');
    }
};
