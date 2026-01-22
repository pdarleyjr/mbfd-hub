<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('fire_equipment|uniforms|etc');
            $table->string('file_path');
            $table->integer('rows_processed')->default(0);
            $table->integer('items_created')->default(0);
            $table->integer('items_updated')->default(0);
            $table->json('metadata')->nullable()->comment('Warnings, stats, etc');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('type');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
