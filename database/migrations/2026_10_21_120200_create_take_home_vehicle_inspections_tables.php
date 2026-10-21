<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Take Home Vehicle Inspections: a record type separate from Daily apparatus
 * checkouts. Each submission is immutable and carries a snapshot of the vehicle
 * as it was when signed, so later master-roster edits never rewrite history.
 * Photos are uploaded one at a time before submission (private disk) and are
 * attached to the inspection when it is submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('take_home_vehicle_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->uuid('client_submission_id')->unique();
            $table->foreignId('unit_master_vehicle_id')->constrained('unit_master_vehicles')->restrictOnDelete();
            $table->foreignId('inspector_user_id')->constrained('users')->restrictOnDelete();
            $table->string('inspector_name');
            $table->foreignId('driver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('driver_name');
            $table->string('driver_employee_number', 64)->nullable();
            $table->boolean('driver_verified')->default(false);
            $table->json('vehicle_snapshot');
            $table->unsignedInteger('odometer')->nullable();
            $table->string('odometer_exception', 32)->nullable(); // not_equipped | nonroad
            $table->boolean('odometer_flagged')->default(false);
            $table->string('odometer_note')->nullable();
            $table->json('checks');
            $table->boolean('has_issues')->default(false);
            $table->boolean('has_not_checked')->default(false);
            $table->boolean('keys_present');
            $table->boolean('acceptable_for_use');
            $table->text('comments')->nullable();
            $table->timestamp('acknowledged_at');
            $table->string('signature_path');
            $table->char('signature_sha256', 64);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['unit_master_vehicle_id', 'submitted_at']);
            $table->index(['inspector_user_id', 'submitted_at']);
            $table->index(['acceptable_for_use', 'submitted_at']);
        });

        Schema::create('take_home_vehicle_inspection_photos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('take_home_vehicle_inspection_id')->nullable()
                ->constrained('take_home_vehicle_inspections')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            // front | rear | driver_side | passenger_side | interior | dashboard | issue
            $table->string('angle', 32);
            $table->string('check_key', 32)->nullable();
            $table->string('path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['uploaded_by_user_id', 'take_home_vehicle_inspection_id'], 'thvi_photos_owner_attach_idx');
        });

        Schema::table('unit_master_vehicle_changes', function (Blueprint $table): void {
            $table->foreign('take_home_vehicle_inspection_id', 'umv_changes_thvi_fk')
                ->references('id')->on('take_home_vehicle_inspections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_master_vehicle_changes', function (Blueprint $table): void {
            $table->dropForeign('umv_changes_thvi_fk');
        });
        Schema::dropIfExists('take_home_vehicle_inspection_photos');
        Schema::dropIfExists('take_home_vehicle_inspections');
    }
};
