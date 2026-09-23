<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_onboarding_roster_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('employee_id');
            $table->string('city_email');
            $table->string('source_sha256', 64);
            $table->timestamp('approved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_onboarding_roster_bindings');
    }
};
