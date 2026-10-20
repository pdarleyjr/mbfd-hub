<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserNotificationSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: a possible truck swap reported during Daily Checkout is held
 * here, as structured data, until a Fleet administrator decides it. Nothing in
 * the inspection or apparatus tables changes shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_vehicle_swap_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inspection_id')->unique()->constrained('apparatus_inspections')->restrictOnDelete();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->foreignId('reported_apparatus_id')->nullable()->constrained('apparatuses')->nullOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name', 255);
            $table->string('unit_designation', 100)->nullable();
            $table->string('assigned_vehicle_number', 64)->nullable();
            $table->string('reported_vehicle_number', 64);
            $table->boolean('reported_vehicle_listed')->default(true);
            $table->string('answer', 24);
            $table->text('note')->nullable();
            // Encrypted meter baseline issued with the checkout; replayed when the findings are applied.
            $table->text('meter_baseline_token')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->json('decision_audit')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['apparatus_id', 'status']);
        });

        // Existing fleet managers start subscribed to the new event; each can turn it off.
        User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'admin.fleet.manage'))
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    UserNotificationSubscription::ensureVehicleSwapForUser((int) $user->id);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_vehicle_swap_requests');
    }
};
