<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('station')->nullable();
            $table->string('phone')->nullable();
            $table->string('display_name')->nullable();
        });

        // Only newly introduced fields come from the existing login profile.
        // Employee name/rank and all identities/credentials remain authoritative.
        DB::table('employees')->orderBy('id')->chunkById(200, function ($employees): void {
            foreach ($employees as $employee) {
                $user = DB::table('users')->where('employee_profile_id', $employee->id)
                    ->where('employee_id', $employee->employee_id)->first();
                if ($user === null) {
                    continue;
                }
                DB::table('employees')->where('id', $employee->id)->update([
                    'station' => $user->station, 'phone' => $user->phone, 'display_name' => $user->display_name,
                ]);
                DB::table('users')->where('id', $user->id)->update([
                    'name' => $employee->name, 'rank' => $employee->rank,
                ]);
            }
        });

        Schema::create('employee_profile_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('result', 30);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profile_events');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['station', 'phone', 'display_name']);
        });
    }
};
