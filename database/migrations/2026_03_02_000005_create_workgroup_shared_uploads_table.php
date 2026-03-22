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
        Schema::create('workgroup_shared_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workgroup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workgroup_session_id')->constrained('workgroup_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workgroup_member_id')->constrained('workgroup_members')->cascadeOnDelete();
            $table->string('filename');
            $table->string('filepath');
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->timestamps();
            
            $table->index('workgroup_id');
            $table->index('workgroup_session_id');
            $table->index('user_id');
            $table->index('workgroup_member_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workgroup_shared_uploads');
    }
};
