<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable copy of a submitted Daily Checkout, rendered only from the stored
 * evidence. Authorized for the submitting user (by foreign key) or for anyone
 * the inspection policy lets view it (Fleet administrators).
 */
class VehicleInspectionPdfController extends Controller
{
    private const MAX_IMAGE_BYTES = 1_500_000;

    private const MAX_TOTAL_IMAGE_BYTES = 12_000_000;

    private const LOGO_PATH = 'images/mbfd_logo-256.png';

    public function __invoke(
        Request $request,
        ApparatusInspection $inspection,
        EmployeeInspectionHistoryService $history,
    ): Response {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        abort_unless(
            $user->can('viewOwn', $inspection) || $user->can('view', $inspection),
            403,
        );

        $detail = $history->detail($inspection);
        [$images, $omitted] = $this->embedImages($history, $detail);

        $pdf = Pdf::loadView('pdf.apparatus-inspection', [
            'detail' => $detail,
            'images' => $images,
            'omittedImages' => $omitted,
            'logo' => $this->logo(),
            'generatedAt' => now()->setTimezone(EmployeeInspectionHistoryService::TIMEZONE),
        ]);
        $pdf->setPaper('letter', 'portrait');

        $filename = $this->filename($inspection);
        $response = $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{0: array<string, string>, 1: int}
     */
    private function embedImages(EmployeeInspectionHistoryService $history, array $detail): array
    {
        $candidates = [];
        foreach ($detail['compartments'] as $compartment) {
            foreach ($compartment['items'] as $item) {
                if ($item['photo_key'] !== null && $item['photo_path'] !== null) {
                    $candidates[$item['photo_key']] = $item['photo_path'];
                }
            }
        }
        if ($detail['signature_path'] !== null) {
            // The signature is embedded first so a heavy photo set never evicts it.
            $candidates = ['signature' => $detail['signature_path']] + $candidates;
        }

        $images = [];
        $total = 0;
        $omitted = 0;
        foreach ($candidates as $key => $path) {
            $uri = $history->embeddableImage($path, self::MAX_IMAGE_BYTES);
            if ($uri === null || $total + strlen($uri) > self::MAX_TOTAL_IMAGE_BYTES) {
                $omitted++;

                continue;
            }
            $total += strlen($uri);
            $images[$key] = $uri;
        }

        return [$images, $omitted];
    }

    private function logo(): ?string
    {
        $path = public_path(self::LOGO_PATH);

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    private function filename(ApparatusInspection $inspection): string
    {
        $base = Str::of((string) $inspection->inspection_reference)
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-.')
            ->toString();

        return ($base !== '' ? $base : 'inspection-'.$inspection->getKey()).'.pdf';
    }
}
