<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four medical inventory forms (docs/ems/four-inventory-forms.md). Additive only: every request written so far was the
 * single Medical Inventory Request, which is now ALS Transport, so existing rows are marked that way. The previous image
 * never reads or writes the column (it leaves null), and every reader treats null as ALS Transport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_inventory_requests', function (Blueprint $table): void {
            $table->string('form_family', 40)->nullable()->index();
        });

        // One statement, so there is no half-marked state. The column is only ever written by a request being created.
        DB::table('ems_inventory_requests')->whereNull('form_family')->update(['form_family' => 'als-transport']);
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('EMS inventory tables hold request, stock and audit history and are never altered backwards outside local or testing.');
        }
        Schema::table('ems_inventory_requests', function (Blueprint $table): void {
            $table->dropIndex(['form_family']);
            $table->dropColumn('form_family');
        });
    }
};
