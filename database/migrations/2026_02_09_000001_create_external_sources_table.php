<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_sources', function (Blueprint $table) {
            $table->id();
            $table->string('division')->default('training')->index();
            $table->string('name');
            $table->string('provider')->default('baserow');
            $table->string('base_url');
            $table->text('token_encrypted')->nullable();
            $table->string('token_hint')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_sources');
    }
};
