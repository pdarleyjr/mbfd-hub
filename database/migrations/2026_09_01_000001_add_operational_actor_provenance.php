<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->after('apparatus_id')
                ->constrained('users')->restrictOnDelete();
        });

        Schema::table('station_inventory_audits', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->after('inventory_item_id')
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_employee_id')->nullable()->after('actor_user_id')
                ->constrained('employees')->restrictOnDelete();
        });

        Schema::table('station_supply_requests', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->after('station_id')
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_employee_id')->nullable()->after('actor_user_id')
                ->constrained('employees')->restrictOnDelete();
        });

        Schema::table('station_inventory_submissions', function (Blueprint $table): void {
            $table->foreignId('actor_employee_id')->nullable()->after('created_by')
                ->constrained('employees')->restrictOnDelete();
        });

        Schema::table('station_requests', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->after('requested_by_employee_id')
                ->constrained('users')->restrictOnDelete();
        });

        Schema::table('station_inspections', function (Blueprint $table): void {
            $table->uuid('client_submission_id')->nullable()->unique()->after('id');
        });

        Schema::create('trt_inventory_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_submission_id')->unique();
            $table->foreignId('session_id')->constrained('trt_inventory_sessions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('entries_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trt_inventory_submissions');
        Schema::table('station_inspections', function (Blueprint $table): void {
            $table->dropUnique(['client_submission_id']);
            $table->dropColumn('client_submission_id');
        });
        Schema::table('station_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_user_id');
        });
        Schema::table('station_inventory_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_employee_id');
        });
        Schema::table('station_supply_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_employee_id');
            $table->dropConstrainedForeignId('actor_user_id');
        });
        Schema::table('station_inventory_audits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_employee_id');
            $table->dropConstrainedForeignId('actor_user_id');
        });
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_user_id');
        });
    }
};
