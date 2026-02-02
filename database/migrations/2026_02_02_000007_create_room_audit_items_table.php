<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_audit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_audit_id')->constrained('room_audits')->cascadeOnDelete();
            $table->foreignId('room_asset_id')->nullable()->constrained('room_assets')->nullOnDelete();
            $table->enum('status', ['Verified', 'Missing', 'Damaged', 'Extra'])->default('Verified');
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();

            $table->index(['room_audit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_audit_items');
    }
};