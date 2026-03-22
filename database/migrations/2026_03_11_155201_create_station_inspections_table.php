<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users');
            $table->date('inspection_date');
            $table->string('inspection_type');
            $table->json('form_data');
            $table->enum('overall_status', ['pass', 'fail', 'needs_attention']);
            $table->text('inspector_signature')->nullable();
            $table->text('reviewer_signature')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('sog_mandate_acknowledged')->default(false);
            $table->date('extinguishing_system_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_inspections');
    }
};
