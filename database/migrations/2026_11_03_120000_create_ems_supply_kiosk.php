<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supply room screen (docs/ems/supply-room-kiosk.md). Additive only: four new tables and one nullable link
 * on the stock ledger so a kiosk issue and its undo can be found from either side. No screen is paired here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ems_kiosk_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->char('pairing_token_hash', 64)->nullable()->unique();
            // A short code typed on the screen itself; same 30 minutes and single use as the link.
            $table->char('pairing_code_hash', 64)->nullable()->unique();
            $table->timestampTz('pairing_expires_at')->nullable();
            $table->char('secret_hash', 64)->nullable();
            $table->timestampTz('paired_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 160);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('offline_alerted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_by_name', 160)->nullable();
            $table->timestampsTz();
        });

        Schema::create('ems_kiosk_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('device_id')->constrained('ems_kiosk_devices')->restrictOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->string('employee_number', 32);
            $table->string('employee_name', 160);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('apparatus_id')->nullable()->constrained('apparatuses')->nullOnDelete();
            $table->string('unit_label', 40)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 12)->default('taken');
            $table->string('copy', 12);
            $table->timestampTz('taken_at');
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();
            $table->index('taken_at');
            $table->index(['employee_profile_id', 'taken_at']);
        });

        Schema::create('ems_kiosk_withdrawal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('ems_kiosk_withdrawals')->restrictOnDelete();
            $table->foreignId('supply_item_id')->constrained('ems_supply_items')->restrictOnDelete();
            $table->string('item_name', 255);
            $table->unsignedInteger('quantity');
            $table->string('unit_key', 24);
            $table->string('unit_label', 60);
            $table->unsignedInteger('per');
            $table->integer('quantity_base');
            $table->boolean('shelf_showed_empty')->default(false);
            $table->foreignId('stock_movement_id')->nullable()->constrained('ems_stock_movements')->restrictOnDelete();
            $table->foreignId('undo_movement_id')->nullable()->constrained('ems_stock_movements')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('supply_item_id');
        });

        Schema::table('ems_stock_movements', function (Blueprint $table): void {
            $table->foreignId('kiosk_withdrawal_id')->nullable()->constrained('ems_kiosk_withdrawals')->restrictOnDelete();
        });

        // A withdrawal the screen undid before it reached the Hub; if that request arrives late it is refused.
        Schema::create('ems_kiosk_cancelled_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained('ems_kiosk_devices')->cascadeOnDelete();
            $table->timestampTz('created_at');
        });

        Schema::create('ems_kiosk_search_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 80);
            $table->foreignId('supply_item_id')->constrained('ems_supply_items')->cascadeOnDelete();
            $table->unsignedInteger('picks')->default(0);
            $table->timestampTz('last_picked_at')->nullable();
            // learning -> approved | dismissed. Only approved words reach the screen's search.
            $table->string('status', 12)->default('learning');
            $table->string('decided_by_name', 160)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['term', 'supply_item_id']);
            $table->index(['status', 'picks']);
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Roll back with the previous image; this migration is not reversed in production.');
        }
        Schema::dropIfExists('ems_kiosk_search_terms');
        Schema::dropIfExists('ems_kiosk_cancelled_withdrawals');
        Schema::table('ems_stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('kiosk_withdrawal_id');
        });
        Schema::dropIfExists('ems_kiosk_withdrawal_lines');
        Schema::dropIfExists('ems_kiosk_withdrawals');
        Schema::dropIfExists('ems_kiosk_devices');
    }
};
