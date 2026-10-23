<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_requests', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnel_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
            $table->dropColumn('deletion_reason');
        });
    }
};
