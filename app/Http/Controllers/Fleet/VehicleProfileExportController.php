<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\Fleet\UnitMasterVehicleProfileExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VehicleProfileExportController extends Controller
{
    public function __invoke(Request $request, UnitMasterVehicle $vehicle, UnitMasterVehicleProfileExporter $exporter): Response
    {
        $data = $request->validate([
            'format' => ['required', 'in:pdf,xlsx'],
            'documents' => ['sometimes', 'array', 'max:100'],
            'documents.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $exporter->download($vehicle, $user, $data['format'], $data['documents'] ?? []);
    }
}
