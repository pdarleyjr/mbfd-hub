<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QueueStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')),
            403,
        );

        return response()->json([
            'pending' => DB::table((string) config('queue.connections.database.table', 'jobs'))->count(),
        ]);
    }
}
