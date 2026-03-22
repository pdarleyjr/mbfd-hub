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
        Schema::create('workgroup_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workgroup_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workgroup_session_id')->nullable()->constrained('workgroup_sessions')->nullOnDelete();
            $table->string('title');
            $table->text('content');
            $table->timestamps();
            
            $table->index('workgroup_member_id');
            $table->index('workgroup_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workgroup_notes');
    }
};
