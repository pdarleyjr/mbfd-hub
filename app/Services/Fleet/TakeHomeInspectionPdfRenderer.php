<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the printable Take Home Vehicle Inspection report (DomPDF, letter).
 * Every stored photo is embedded, downscaled to a print-friendly size so the
 * PDF stays small; a photo that cannot be read is listed, never silently dropped.
 */
final class TakeHomeInspectionPdfRenderer
{
    public const LOGO_PATH = 'images/mbfd_logo-256.png';

    private const PRINT_EDGE = 1400;

    public function render(TakeHomeVehicleInspection $inspection): \Barryvdh\DomPDF\PDF
    {
        $inspection->loadMissing(['photos', 'vehicle']);
        $disk = Storage::disk(TakeHomeInspectionPhotoStore::disk());

        $standard = [];
        foreach (TakeHomeVehicleInspection::PHOTO_ANGLES as $angle => $label) {
            $photo = $inspection->photos->firstWhere('angle', $angle);
            $standard[] = ['label' => $label, 'provided' => $photo !== null, 'src' => $photo ? $this->printable($disk, $photo) : null];
        }
        $issues = $inspection->photos
            ->where('angle', TakeHomeVehicleInspection::ISSUE_ANGLE)
            ->map(fn (TakeHomeVehicleInspectionPhoto $photo): array => ['label' => $photo->label(), 'src' => $this->printable($disk, $photo)])
            ->values()
            ->all();

        $signature = $disk->exists($inspection->signature_path)
            ? 'data:image/png;base64,'.base64_encode((string) $disk->get($inspection->signature_path))
            : null;

        $pdf = Pdf::loadView('pdf.take-home-vehicle-inspection', [
            'inspection' => $inspection,
            'snapshot' => $inspection->vehicle_snapshot ?? [],
            'standardPhotos' => $standard,
            'issuePhotos' => $issues,
            'signature' => $signature,
            'logo' => $this->logo(),
            'generatedAt' => now('America/New_York'),
        ]);
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 150,
            $canvas->get_height() - 28,
            $inspection->reference.'  ·  Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7.5,
            [0.33, 0.39, 0.47],
        );

        return $pdf;
    }

    private function printable($disk, TakeHomeVehicleInspectionPhoto $photo): ?string
    {
        if (! $disk->exists($photo->path)) {
            return null;
        }
        $bytes = (string) $disk->get($photo->path);
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest > self::PRINT_EDGE) {
            $scale = self::PRINT_EDGE / $longest;
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return $jpeg === '' ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function logo(): ?string
    {
        $path = public_path(self::LOGO_PATH);

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }
}
