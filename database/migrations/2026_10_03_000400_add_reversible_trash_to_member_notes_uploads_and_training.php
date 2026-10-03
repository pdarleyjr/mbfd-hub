<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['workgroup_notes', 'workgroup_shared_uploads', 'training_todos', 'training_todo_updates'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
