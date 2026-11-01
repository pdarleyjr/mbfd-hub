<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The SCBA and radio numbers on each apparatus right now, so the next member only changes what changed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_equipment_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            // "field:<id>" or "item:<compartment>/<item>" for a checklist entry, "extra:<uuid>" for one a member added.
            $table->string('slot');
            $table->string('kind', 16);
            $table->string('label', 120)->nullable();
            $table->string('number');
            $table->foreignId('source_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['apparatus_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_equipment_numbers');
    }
};
