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
        Schema::table('personnel_request_items', function (Blueprint $table): void {
            $table->unsignedInteger('arrived_quantity')->default(0);
        });

        DB::table('personnel_request_items as items')
            ->join('personnel_requests as requests', 'requests.id', '=', 'items.personnel_request_id')
            ->select('items.id', 'items.quantity', 'items.fulfilled_quantity', 'requests.status')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $arrived = in_array($item->status, ['arrived', 'ready_for_pickup', 'completed'], true)
                        ? max((int) $item->quantity, (int) $item->fulfilled_quantity)
                        : (int) $item->fulfilled_quantity;
                    DB::table('personnel_request_items')->where('id', $item->id)->update(['arrived_quantity' => $arrived]);
                }
            }, 'items.id', 'id');
    }

    public function down(): void
    {
        Schema::table('personnel_request_items', function (Blueprint $table): void {
            $table->dropColumn('arrived_quantity');
        });
    }
};
