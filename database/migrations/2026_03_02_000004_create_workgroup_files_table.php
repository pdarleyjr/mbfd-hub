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
        Schema::create('workgroup_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workgroup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workgroup_session_id')->nullable()->constrained('workgroup_sessions')->nullOnDelete();
            $table->string('filename');
            $table->string('filepath');
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            
            $table->index('workgroup_id');
            $table->index('workgroup_session_id');
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workgroup_files');
    }
};
