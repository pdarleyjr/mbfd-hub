<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The inventory now holds firefighting PPE as well as uniforms, so size is free
 * text (boot, glove and turnout sizes) and items carry optional make/model detail.
 * Additive only: every new column is nullable and existing rows are untouched.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private const TEXT_COLUMNS = [
        'category' => 100,
        'item_type' => 150,
        'manufacturer' => 150,
        'model' => 150,
        'color' => 100,
        'serial_number' => 150,
        'condition' => 100,
        'storage_location' => 150,
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE uniforms DROP CONSTRAINT IF EXISTS uniforms_size_check');
        }

        Schema::table('uniforms', function (Blueprint $table): void {
            $table->string('size', 100)->nullable()->change();
        });

        Schema::table('uniforms', function (Blueprint $table): void {
            foreach (self::TEXT_COLUMNS as $column => $length) {
                if (! Schema::hasColumn('uniforms', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
            if (! Schema::hasColumn('uniforms', 'manufacture_date')) {
                $table->date('manufacture_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('uniforms', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                [...array_keys(self::TEXT_COLUMNS), 'manufacture_date'],
                fn (string $column): bool => Schema::hasColumn('uniforms', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
        // Size stays free text: restoring the old XS–XXXL check could reject rows entered since.
    }
};
