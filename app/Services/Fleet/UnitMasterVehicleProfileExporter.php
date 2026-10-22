<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Support\Fleet\VehicleDocumentAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

final class UnitMasterVehicleProfileExporter
{
    public const MAX_DOCUMENTS = 100;

    public const MAX_BYTES = 250 * 1024 * 1024;

    private const HISTORY_LIMIT = 100;

    /** @param list<mixed> $selectedDocumentIds */
    public function download(UnitMasterVehicle $vehicle, User $actor, string $format, array $selectedDocumentIds = []): StreamedResponse
    {
        abort_unless(VehicleDocumentAccess::canExport($actor, $vehicle), 403);
        if (! in_array($format, ['pdf', 'xlsx'], true)) {
            throw ValidationException::withMessages(['format' => 'Choose PDF or Excel.']);
        }
        $documents = $this->selectedDocuments($vehicle, $actor, $selectedDocumentIds);
        $vehicle->loadMissing('currentDriver');
        $generatedAt = now(UnitMasterRosterExporter::TIMEZONE);
        $inspections = $vehicle->takeHomeInspections()->limit(self::HISTORY_LIMIT)->get();
        $changes = $vehicle->changes()->limit(self::HISTORY_LIMIT)->get();
        $profile = $this->profile($vehicle);
        $directory = storage_path('app/private/vehicle-exports/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $profileName = 'Vehicle_'.$this->safeName((string) ($vehicle->veh_number ?: $vehicle->getKey())).'_Profile.'.$format;

        try {
            $manifest = [
                'generated_at' => $generatedAt->toIso8601String(),
                'vehicle_id' => $vehicle->getKey(),
                'vehicle_number' => (string) $vehicle->veh_number,
                'format' => $format,
                'scope' => $documents->isEmpty() ? 'Vehicle profile only' : 'Vehicle profile and selected original documents',
                'documents' => [],
            ];
            foreach ($documents as $document) {
                $entry = 'Documents/'.$document->getKey().'-'.$this->safeName((string) $document->original_filename);
                $manifest['documents'][] = [
                    'id' => $document->getKey(),
                    'title' => (string) $document->title,
                    'category' => $document->categoryLabel(),
                    'document_date' => $document->document_date?->format('Y-m-d'),
                    'original_filename' => (string) $document->original_filename,
                    'path' => $entry,
                    'sha256' => (string) $document->sha256,
                    'size_bytes' => (int) $document->size_bytes,
                    'review_status' => (string) $document->review_status,
                    'report_number' => (string) $document->report_number,
                ];
            }

            $profilePath = $directory.'/'.$profileName;
            if ($format === 'pdf') {
                $this->pdf($vehicle, $profile, $inspections, $changes, $manifest)->save($profilePath);
            } else {
                $this->xlsx($profilePath, $profile, $inspections, $changes, $manifest);
            }
            if (! is_file($profilePath) || filesize($profilePath) === 0) {
                throw new RuntimeException('The vehicle profile could not be generated. Please try again.');
            }
            @chmod($profilePath, 0600);
            $manifest['profile'] = [
                'path' => $profileName,
                'sha256' => hash_file('sha256', $profilePath),
                'size_bytes' => filesize($profilePath),
            ];

            $path = $profilePath;
            $name = $profileName;
            $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            if ($documents->isNotEmpty()) {
                $name = 'Vehicle_'.$this->safeName((string) ($vehicle->veh_number ?: $vehicle->getKey())).'_Export.zip';
                $path = $directory.'/'.$name;
                $this->package($path, $profilePath, $documents, $manifest, $directory);
                $mime = 'application/zip';
                foreach ($documents as $document) {
                    app(VehicleDocumentLibrary::class)->recordEvent($document, $actor, 'export', [
                        'vehicle_id' => $vehicle->getKey(), 'format' => $format, 'count' => $documents->count(),
                    ]);
                }
            }

            app()->terminating(static fn () => File::deleteDirectory($directory));

            return response()->streamDownload(static function () use ($path, $directory): void {
                try {
                    $stream = fopen($path, 'rb');
                    if ($stream === false) {
                        throw new RuntimeException('The vehicle export could not be read. Please generate it again.');
                    }
                    try {
                        if (fpassthru($stream) !== filesize($path)) {
                            throw new RuntimeException('The vehicle export could not be delivered. Please generate it again.');
                        }
                    } finally {
                        fclose($stream);
                    }
                } finally {
                    File::deleteDirectory($directory);
                }
            }, $name, [
                'Content-Type' => $mime,
                'Content-Length' => (string) filesize($path),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    /**
     * @param  list<mixed>  $ids
     * @return Collection<int, VehicleDocument>
     */
    private function selectedDocuments(UnitMasterVehicle $vehicle, User $actor, array $ids): Collection
    {
        if (count($ids) > self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['documents' => 'Choose up to 100 documents per export.']);
        }
        foreach ($ids as $id) {
            abort_unless((is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]*$/', (string) $id), 404);
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $documents = $vehicle->documents()->whereKey($ids)->orderBy('vehicle_documents.id')->get();
        abort_unless($documents->count() === count($ids), 404);
        foreach ($documents as $document) {
            abort_unless(VehicleDocumentAccess::canViewDocument($actor, $document, $vehicle), 403);
        }
        if ($documents->sum('size_bytes') > self::MAX_BYTES) {
            throw ValidationException::withMessages(['documents' => 'This selection exceeds 250 MB. Export a smaller group of documents.']);
        }

        return $documents;
    }

    /** @return list<array{0: string, 1: string}> */
    private function profile(UnitMasterVehicle $vehicle): array
    {
        $rows = [];
        foreach (UnitMasterVehicle::FIELD_LABELS as $field => $label) {
            if ($field === 'record') {
                continue;
            }
            $value = match ($field) {
                'current_driver_employee_id' => $vehicle->currentDriver?->name,
                'asset_category' => $vehicle->categoryLabel(),
                default => $vehicle->{$field},
            };
            $rows[] = [$label, (string) $value];
        }
        $rows[] = ['Needs Fleet review', $vehicle->needs_fleet_review ? 'Yes' : 'No'];

        return $rows;
    }

    private function pdf(UnitMasterVehicle $vehicle, array $profile, Collection $inspections, Collection $changes, array $manifest): \Barryvdh\DomPDF\PDF
    {
        $logoPath = public_path('images/mbfd-logo-vehicle-reports.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;

        return Pdf::loadView('exports.unit-master-vehicle-profile', compact('vehicle', 'profile', 'inspections', 'changes', 'manifest', 'logo'))
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->setPaper('letter', 'portrait');
    }

    private function xlsx(string $path, array $profile, Collection $inspections, Collection $changes, array $manifest): void
    {
        $spreadsheet = new Spreadsheet;
        try {
            $this->sheet($spreadsheet->getActiveSheet()->setTitle('Vehicle Profile'), ['Field', 'Value'], $profile, [28, 75]);
            $this->sheet($spreadsheet->createSheet()->setTitle('Vehicle Documents'), [
                'Document title', 'Category', 'Document date', 'Original filename', 'Review status', 'Package path', 'SHA-256', 'Report number',
            ], array_map(static fn (array $document): array => [
                $document['title'], $document['category'], $document['document_date'] ?? '', $document['original_filename'],
                $document['review_status'], $document['path'], $document['sha256'], $document['report_number'],
            ], $manifest['documents']), [45, 25, 18, 40, 20, 60, 68, 24]);
            foreach ($manifest['documents'] as $index => $document) {
                $spreadsheet->getSheetByName('Vehicle Documents')->getCell('F'.($index + 2))->getHyperlink()->setUrl($document['path']);
            }

            $inspectionRows = $inspections->map(static fn ($inspection): array => [
                (string) $inspection->reference,
                $inspection->submitted_at?->clone()->timezone(UnitMasterRosterExporter::TIMEZONE)->format('Y-m-d g:i A T') ?? '',
                $inspection->acceptableLabel(),
                $inspection->has_issues ? 'Yes' : 'No',
                $inspection->odometer === null ? '' : (string) $inspection->odometer,
            ])->all();
            $this->sheet($spreadsheet->createSheet()->setTitle('Inspections'), [
                'Reference', 'Submitted (Eastern)', 'Acceptable for use', 'Issues reported', 'Odometer',
            ], $inspectionRows, [24, 30, 22, 20, 18]);

            $this->sheet($spreadsheet->createSheet()->setTitle('Vehicle History'), [
                'Recorded (Eastern)', 'Field', 'Previous value', 'Proposed / new value', 'Status',
            ], $changes->map(static fn ($change): array => [
                $change->created_at?->clone()->timezone(UnitMasterRosterExporter::TIMEZONE)->format('Y-m-d g:i A T') ?? '',
                $change->fieldLabel(), (string) $change->old_value, (string) $change->new_value, (string) $change->status,
            ])->all(), [30, 28, 40, 40, 18]);
            $this->sheet($spreadsheet->createSheet()->setTitle('Export Information'), ['Field', 'Value'], [
                ['Title', 'MBFD Master Vehicle Inventory — Vehicle Profile'],
                ['Generated (Eastern)', $manifest['generated_at']],
                ['Scope', $manifest['scope']],
                ['Original documents included', (string) count($manifest['documents'])],
                ['Inspection and history coverage', 'Most recent '.self::HISTORY_LIMIT.' records of each type.'],
                ['Original documents', 'Selected originals are delivered unchanged in the companion Documents folder.'],
            ], [32, 100]);
            $spreadsheet->setActiveSheetIndex(0);
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function sheet(Worksheet $sheet, array $headers, array $rows, array $widths): void
    {
        foreach ([$headers, ...$rows] as $rowIndex => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $rowIndex + 1], (string) $value, DataType::TYPE_STRING);
            }
        }
        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '012766']],
        ]);
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimensionByColumn($column + 1)->setWidth($width);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, count($rows) + 1));
    }

    private function package(string $path, string $profilePath, Collection $documents, array $manifest, string $directory): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('The document package could not be created. Please try again.');
        }
        try {
            if (! $zip->addFile($profilePath, basename($profilePath))) {
                throw new RuntimeException('The vehicle profile could not be added to the package.');
            }
            foreach ($documents->values() as $index => $document) {
                $stream = app(VehicleDocumentLibrary::class)->verifiedStream($document);
                $copyPath = $directory.'/original-'.$document->getKey();
                $target = null;
                try {
                    $target = fopen($copyPath, 'xb');
                    if ($target === false || stream_copy_to_stream($stream, $target, (int) $document->size_bytes + 1) !== (int) $document->size_bytes) {
                        throw new RuntimeException('An original document could not be copied intact. No package was delivered.');
                    }
                } finally {
                    fclose($stream);
                    if (is_resource($target)) {
                        fclose($target);
                    }
                }
                @chmod($copyPath, 0600);
                if (! hash_equals((string) $document->sha256, (string) hash_file('sha256', $copyPath))
                    || ! $zip->addFile($copyPath, $manifest['documents'][$index]['path'])) {
                    throw new RuntimeException('An original document could not be verified. No package was delivered.');
                }
            }
            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('The export manifest could not be included.');
            }
        } catch (Throwable $exception) {
            $zip->close();
            throw $exception;
        }
        if (! $zip->close()) {
            throw new RuntimeException('The document package could not be completed. Please try again.');
        }
        @chmod($path, 0600);
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($name)) ?? '';
        $name = trim(str_replace('..', '_', $name), '._-');

        $name = $name !== '' ? $name : 'document';
        if (strlen($name) > 150) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $suffix = $extension !== '' && strlen($extension) < 10 ? '.'.$extension : '';
            $name = substr($name, 0, 150 - strlen($suffix)).$suffix;
        }

        return $name;
    }
}
