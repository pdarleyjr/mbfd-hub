<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_works', function (Blueprint $table) {
            $table->id();
            $table->string('project_name');
            $table->text('description')->nullable();
            $table->foreignId('apparatus_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['Pending', 'In Progress', 'Waiting for Parts', 'Completed', 'Cancelled'])->default('Pending');
            $table->text('parts_list')->nullable();
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->decimal('actual_cost', 10, 2)->nullable();
            $table->date('started_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->string('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_works');
    }
};
