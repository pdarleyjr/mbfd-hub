<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('vehicle_number');
            $table->string('assignment')->nullable()->after('designation');
            $table->string('current_location')->nullable()->after('assignment');
            $table->string('class_description')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropColumn(['designation', 'assignment', 'current_location', 'class_description']);
        });
    }
};
