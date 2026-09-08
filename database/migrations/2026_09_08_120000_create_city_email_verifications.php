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
        foreach (['users' => 'email', 'employees' => 'city_email'] as $table => $column) {
            if (DB::table($table)->selectRaw('1')->whereNotNull($column)->groupByRaw("LOWER({$column})")->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Case-insensitive email collisions require identity review before migration.');
            }
        }

        DB::statement('CREATE UNIQUE INDEX users_email_casefold_unique ON users (LOWER(email))');
        DB::statement('CREATE UNIQUE INDEX employees_city_email_casefold_unique ON employees (LOWER(city_email))');

        Schema::create('city_email_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employees')->cascadeOnDelete();
            $table->string('email');
            $table->string('original_user_email');
            $table->string('original_employee_city_email')->nullable();
            $table->unsignedInteger('security_version');
            $table->timestamp('acknowledged_at');
            $table->string('token_hash', 64)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_status', 16)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_email_verifications');
        DB::statement('DROP INDEX users_email_casefold_unique');
        DB::statement('DROP INDEX employees_city_email_casefold_unique');
    }
};
