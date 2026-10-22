<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Hub administrator without a personnel record may sign in to the
        // Command Board as their canonical Hub user. The foreign key remains.
        Schema::table('oidc_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_profile_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rollback keeps the column nullable: re-adding NOT NULL could fail on
        // audited sessions created by unlinked administrators.
    }
};
