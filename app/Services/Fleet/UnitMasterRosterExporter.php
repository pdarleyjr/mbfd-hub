<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\TakeHomeVehicleInspection;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Builds the Master Unit Vehicle Inventory exports (XLSX and PDF) from a
 * collection of vehicles. Query-agnostic: the caller decides which vehicles
 * (all, or the admin table's current filters and search) are exported.
 */
final class UnitMasterRosterExporter
{
    public const TIMEZONE = 'America/New_York';

    public const TITLE = 'MBFD Master Unit Vehicle Inventory';

    private const LOGO_PATH = 'images/mbfd_logo-256.png';

    /** Header => column width (characters). */
    private const COLUMNS = [
        'Vehicle #' => 12,
        'Year' => 8,
        'Make' => 16,
        'Model' => 18,
        'Color' => 12,
        'License plate / Tag' => 16,
        'VIN / Serial' => 22,
        'Category' => 14,
        'Fleet class' => 12,
        'Class description' => 28,
        'FY27 status' => 12,
        'Dept' => 10,
        'Section' => 16,
        'Assigned name / vehicle name' => 28,
        'Assignment' => 22,
        'Current driver' => 24,
        'Driver employee ID' => 16,
        'Location' => 20,
        'Last take-home inspection (ET)' => 24,
        'Last inspection acceptable' => 14,
        'Last inspection reference' => 20,
        'Pending corrections' => 12,
        'Needs review' => 10,
    ];

    /** Indexes (0-based) of columns written as numbers. Everything else is text. */
    private const NUMERIC_COLUMNS = [21];

    /** Load everything the exports read, without disturbing already-loaded data. */
    public function hydrate(Collection $vehicles): Collection
    {
        $vehicles->loadMissing(['currentDriver', 'latestTakeHomeInspection']);
        $vehicles->loadCount([
            'changes as pending_changes_count' => fn ($query) => $query->where('status', UnitMasterVehicleChange::STATUS_PROPOSED),
        ]);

        return $vehicles;
    }

    public function filename(string $extension, CarbonInterface $generatedAt): string
    {
        return 'MBFD - Master Unit Vehicle Inventory - '.$generatedAt->format('Y-m-d').'.'.$extension;
    }

    /**
     * @param  Collection<int, UnitMasterVehicle>  $vehicles
     * @return list<list<string|int>> One row of cells per vehicle, in COLUMNS order.
     */
    public function rows(Collection $vehicles): array
    {
        return $vehicles->map(fn (UnitMasterVehicle $vehicle): array => $this->row($vehicle))->values()->all();
    }

    /** @param  Collection<int, UnitMasterVehicle>  $vehicles */
    public function xlsx(Collection $vehicles, string $scope, string $filters, CarbonInterface $generatedAt): string
    {
        $this->hydrate($vehicles);
        $rows = $this->rows($vehicles);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vehicles');

        foreach (array_keys(self::COLUMNS) as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $index => $value) {
                $coordinate = [$index + 1, $rowIndex + 2];
                if (in_array($index, self::NUMERIC_COLUMNS, true)) {
                    $sheet->setCellValueExplicit($coordinate, (int) $value, DataType::TYPE_NUMERIC);
                } else {
                    // Always text, so IDs such as "002-12" or a plate "0123" keep their form.
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, count($rows) + 1));
        foreach (array_values(self::COLUMNS) as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }

        $about = $spreadsheet->createSheet();
        $about->setTitle('About this export');
        $facts = [
            ['Title', self::TITLE],
            ['Generated (ET)', $generatedAt->copy()->timezone(self::TIMEZONE)->format('M j, Y g:i A T')],
            ['Scope', $scope],
            ['Filters / search', $filters],
            ['Record count', (string) count($rows)],
        ];
        foreach ($facts as $index => [$label, $value]) {
            $about->setCellValueExplicit([1, $index + 1], $label, DataType::TYPE_STRING);
            $about->setCellValueExplicit([2, $index + 1], $value, DataType::TYPE_STRING);
        }
        $about->getStyle('A1:A'.count($facts))->getFont()->setBold(true);
        $about->getColumnDimension('A')->setWidth(18);
        $about->getColumnDimension('B')->setWidth(80);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->write($spreadsheet);
    }

    /** @param  Collection<int, UnitMasterVehicle>  $vehicles */
    public function pdf(Collection $vehicles, string $scope, string $filters, CarbonInterface $generatedAt): string
    {
        $this->hydrate($vehicles);
        $generated = $generatedAt->copy()->timezone(self::TIMEZONE);

        $pdf = Pdf::loadView('pdf.unit-master-roster', [
            'logo' => $this->logo(),
            'title' => 'Master Unit Vehicle Inventory',
            'generatedAt' => $generated->format('M j, Y g:i A T'),
            'scope' => $scope,
            'filters' => $filters,
            'count' => $vehicles->count(),
            'rows' => $vehicles->map(fn (UnitMasterVehicle $vehicle): array => $this->pdfRow($vehicle))->values()->all(),
        ]);
        $pdf->setPaper('letter', 'landscape');

        // Render first so "Page X of Y" can be drawn on every page.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(680, 590, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.35]);

        return $pdf->output();
    }

    /** @return list<string|int> */
    private function row(UnitMasterVehicle $vehicle): array
    {
        $inspection = $vehicle->latestTakeHomeInspection;
        $driver = $vehicle->currentDriver;

        return [
            (string) $vehicle->veh_number,
            (string) $vehicle->year,
            (string) $vehicle->make,
            (string) $vehicle->model,
            (string) $vehicle->color,
            (string) $vehicle->tag_number,
            (string) $vehicle->serial_number,
            $vehicle->categoryLabel(),
            (string) $vehicle->fleet_class_code,
            (string) $vehicle->fleet_class_description,
            (string) $vehicle->fleet_status_code,
            (string) $vehicle->dept_code,
            (string) $vehicle->section,
            (string) $vehicle->employee_or_vehicle_name,
            (string) $vehicle->assignment,
            (string) ($driver->name ?? ''),
            (string) ($driver->employee_id ?? ''),
            (string) $vehicle->location,
            $this->inspectionDate($inspection),
            $inspection === null ? '' : ($inspection->acceptable_for_use ? 'Yes' : 'No'),
            (string) ($inspection->reference ?? ''),
            (int) ($vehicle->pending_changes_count ?? 0),
            $vehicle->needs_fleet_review ? 'Yes' : 'No',
        ];
    }

    /** @return array<string, string> */
    private function pdfRow(UnitMasterVehicle $vehicle): array
    {
        $inspection = $vehicle->latestTakeHomeInspection;
        $driver = $vehicle->currentDriver;
        $join = static fn (array $parts, string $glue): string => implode($glue, array_filter($parts, static fn ($part): bool => filled($part)));

        $inspectionText = $inspection === null
            ? 'None'
            : $this->inspectionDate($inspection).' ('.($inspection->acceptable_for_use ? 'acceptable' : 'NOT acceptable').')';

        return [
            'veh' => (string) $vehicle->veh_number,
            'ymm' => $join([$vehicle->year, $vehicle->make, $vehicle->model], ' '),
            'color' => (string) $vehicle->color,
            'plate' => (string) $vehicle->tag_number,
            'vin' => (string) $vehicle->serial_number,
            'category' => $join([$vehicle->categoryLabel(), $vehicle->fleet_class_description ?: $vehicle->fleet_class_code], ' / '),
            'status' => (string) $vehicle->fleet_status_code,
            'dept' => (string) $vehicle->dept_code,
            'assigned' => $join([$vehicle->employee_or_vehicle_name, $driver?->name ? 'Driver: '.$driver->name : null], ' | '),
            'inspection' => $inspectionText,
        ];
    }

    private function inspectionDate(?TakeHomeVehicleInspection $inspection): string
    {
        return $inspection?->submitted_at
            ? $inspection->submitted_at->clone()->timezone(self::TIMEZONE)->format('Y-m-d g:i A')
            : '';
    }

    private function logo(): ?string
    {
        $path = public_path(self::LOGO_PATH);

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    private function write(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-roster-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file for the roster export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }
    }
}
