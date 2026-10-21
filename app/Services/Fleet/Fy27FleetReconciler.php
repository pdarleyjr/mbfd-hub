<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reconciles a staged copy of the city's FY27 fleet list against the authoritative
 * unit_master_vehicles table.
 *
 * plan() is read-only. apply() performs only additive, audited enrichment (fills of
 * blank fields, more-specific make/model refinements, and new records for vehicles
 * missing from the Hub); every contradiction becomes a proposal for Fleet to decide.
 * Hub records are never deleted, retired or overwritten with conflicting values, and
 * names, drivers and assignments are never changed.
 */
final class Fy27FleetReconciler
{
    public const HEADER = [
        'source_row', 'row_hidden', 'Vehicle No', 'Assigned Name', 'Make', 'Model', 'Year', 'Color',
        'Years Old', 'VIN', 'Status', 'Class', 'Class Description', 'Dept', 'License No',
    ];

    public const CLASS_EXACT = 'Exact match';

    public const CLASS_DISCREPANCY = 'Same vehicle ID / data discrepancy';

    public const CLASS_VIN_MATCH = 'VIN match / ID discrepancy';

    public const CLASS_MISSING = 'Missing from Hub';

    public const CLASS_CONFLICT = 'Conflicting or duplicate';

    public const CLASS_VERIFY = 'Needs verification';

    public const CLASSES = [
        self::CLASS_EXACT, self::CLASS_DISCREPANCY, self::CLASS_VIN_MATCH,
        self::CLASS_MISSING, self::CLASS_CONFLICT, self::CLASS_VERIFY,
    ];

    public const KIND_FILL = 'fill';

    public const KIND_REFINEMENT = 'refinement';

    public const KIND_PROPOSAL = 'proposal';

    /** Fields whose disagreement makes a row "Same vehicle ID / data discrepancy". */
    private const LEGACY_FIELDS = [
        'make', 'model', 'year', 'tag_number', 'serial_number', 'dept_code', 'employee_or_vehicle_name',
    ];

    private const NEW_FIELDS = ['color', 'fleet_status_code', 'fleet_class_code', 'fleet_class_description'];

    /** Fields whose old/new values may appear in reports. Names are deliberately excluded. */
    private const REPORTABLE_VALUE_FIELDS = [
        'veh_number', 'make', 'model', 'year', 'tag_number', 'serial_number', 'dept_code',
    ];

    private const NAME_FIELD = 'employee_or_vehicle_name';

    public function __construct(private readonly UnitMasterVehicleChangeRecorder $recorder) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException when the file is unreadable or the header is wrong
     */
    public function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("FY27 CSV not found or unreadable: {$path}");
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("Could not open FY27 CSV: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw new InvalidArgumentException('FY27 CSV is empty.');
            }
            $header = array_map(fn (?string $cell): string => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell) ?? ''), $header);
            if ($header !== self::HEADER) {
                throw new InvalidArgumentException('FY27 CSV header does not match. Expected: '.implode(',', self::HEADER));
            }

            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                    continue;
                }
                $rows[] = $this->rowFromCells($cells, $line);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Read-only comparison. Safe to call at any time.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, absent_from_fy27: list<array<string, mixed>>, totals: array<string, int>, counts: array<string, int>, hidden_rows: int, csv_rows: int}
     */
    public function plan(array $rows): array
    {
        $hub = UnitMasterVehicle::query()->orderBy('id')->get();
        $byVeh = [];
        $byVin = [];
        foreach ($hub as $vehicle) {
            $vehKey = $this->vehKey($vehicle->veh_number);
            if ($vehKey !== '') {
                $byVeh[$vehKey][] = $vehicle;
            }
            $vinKey = $this->vinKey($vehicle->serial_number);
            if ($vinKey !== '') {
                $byVin[$vinKey][] = $vehicle;
            }
        }

        $pending = [];
        foreach (UnitMasterVehicleChange::query()->pending()->get(['unit_master_vehicle_id', 'field', 'new_value']) as $change) {
            $pending[$change->unit_master_vehicle_id.'|'.$change->field.'|'.$change->new_value] = true;
        }

        $csvVehCounts = [];
        foreach ($rows as $row) {
            $key = $this->vehKey($row['veh'] ?? '');
            if ($key !== '') {
                $csvVehCounts[$key] = ($csvVehCounts[$key] ?? 0) + 1;
            }
        }

        $planned = [];
        $seenVeh = [];
        $seenVin = [];
        foreach ($rows as $row) {
            $planned[] = $this->planRow($row, $byVeh, $byVin, $csvVehCounts, $pending);
            $vehKey = $this->vehKey($row['veh'] ?? '');
            if ($vehKey !== '') {
                $seenVeh[$vehKey] = true;
            }
            $vinKey = $this->vinKey($row['vin'] ?? '');
            if ($vinKey !== '') {
                $seenVin[$vinKey] = true;
            }
        }

        $absent = [];
        foreach ($hub as $vehicle) {
            $vehKey = $this->vehKey($vehicle->veh_number);
            $vinKey = $this->vinKey($vehicle->serial_number);
            if (($vehKey !== '' && isset($seenVeh[$vehKey])) || ($vinKey !== '' && isset($seenVin[$vinKey]))) {
                continue;
            }
            $absent[] = ['id' => $vehicle->id, 'veh_number' => $vehicle->veh_number];
        }

        return [
            'csv_rows' => count($rows),
            'rows' => $planned,
            'absent_from_fy27' => $absent,
            'totals' => $this->totals($planned),
            'counts' => $this->counts($planned),
            'hidden_rows' => count(array_filter($planned, fn (array $row): bool => $row['hidden'])),
        ];
    }

    /**
     * Write the plan inside one transaction through the audited recorder.
     *
     * @param  array<string, mixed>  $plan
     * @return array{applied: int, proposed: int, created: int, skipped_creates: int}
     */
    public function apply(array $plan): array
    {
        return DB::transaction(function () use ($plan): array {
            $result = ['applied' => 0, 'proposed' => 0, 'created' => 0, 'skipped_creates' => 0];

            foreach ($plan['rows'] as $row) {
                $context = ['source_reference' => 'FY27 row '.$row['source_row']];

                if ($row['create'] !== null) {
                    $this->createMissing($row, $context, $result);

                    continue;
                }
                if ($row['changes'] === [] || $row['hub_ids'] === []) {
                    continue;
                }

                $vehicle = UnitMasterVehicle::query()->lockForUpdate()->find($row['hub_ids'][0]);
                if ($vehicle === null) {
                    continue;
                }
                foreach ($row['changes'] as $change) {
                    $changeContext = $context + ['note' => $change['note']];
                    if ($change['kind'] === self::KIND_PROPOSAL) {
                        $recorded = $this->recorder->propose($vehicle, $change['field'], $change['new'], UnitMasterVehicleChange::SOURCE_FY27, $changeContext);
                        $result['proposed'] += $recorded === null ? 0 : 1;
                    } else {
                        $recorded = $this->recorder->apply($vehicle, $change['field'], $change['new'], UnitMasterVehicleChange::SOURCE_FY27, $changeContext);
                        $result['applied'] += $recorded === null ? 0 : 1;
                    }
                }
            }

            return $result;
        });
    }

    /**
     * Report-safe view of a plan: assigned names are never included.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, int>|null  $applyResult
     * @return array<string, mixed>
     */
    public function report(array $plan, ?array $applyResult, string $csvPath): array
    {
        $rows = array_map(function (array $row): array {
            $changes = array_map(function (array $change): array {
                $safe = ['field' => $change['field'], 'kind' => $change['kind'], 'note' => $change['note'], 'already_pending' => $change['already_pending']];
                if (in_array($change['field'], self::REPORTABLE_VALUE_FIELDS, true)) {
                    $safe['old'] = $change['old'];
                    $safe['new'] = $change['new'];
                } elseif ($change['field'] === self::NAME_FIELD) {
                    $safe['name_differs'] = true;
                }

                return $safe;
            }, $row['changes']);

            $create = $row['create'];
            if ($create !== null) {
                $create['assigned_name_present'] = ($create[self::NAME_FIELD] ?? null) !== null;
                unset($create[self::NAME_FIELD]);
            }

            return [
                'source_row' => $row['source_row'],
                'vehicle_no' => $row['vehicle_no'],
                'hidden' => $row['hidden'],
                'class' => $row['class'],
                'hub_ids' => $row['hub_ids'],
                'notes' => $row['notes'],
                'kept_hub_values' => $row['keeps'],
                'changes' => $changes,
                'create' => $create,
            ];
        }, $plan['rows']);

        return [
            'mode' => $applyResult === null ? 'dry-run' : 'applied',
            'csv' => basename($csvPath),
            'generated_at' => now()->toIso8601String(),
            'csv_rows' => $plan['csv_rows'],
            'totals' => $plan['totals'],
            'totals_sum' => array_sum($plan['totals']),
            'hidden_rows' => $plan['hidden_rows'],
            'hidden_rows_included' => true,
            'counts' => $plan['counts'],
            'apply_result' => $applyResult,
            'rows' => $rows,
            'absent_from_fy27' => $plan['absent_from_fy27'],
        ];
    }

    // ---------------------------------------------------------------- parsing

    /** @param array<int, ?string> $cells */
    private function rowFromCells(array $cells, int $line): array
    {
        $cells = array_map(fn (?string $cell): string => $this->clean($this->toUtf8((string) $cell)), $cells);
        $sourceRow = isset($cells[0]) && ctype_digit($cells[0]) ? (int) $cells[0] : $line;

        if (count($cells) !== count(self::HEADER)) {
            return ['source_row' => $sourceRow, 'malformed' => true, 'hidden' => false, 'veh' => ''];
        }

        return [
            'source_row' => $sourceRow,
            'malformed' => false,
            'hidden' => $cells[1] === '1',
            'veh' => $cells[2],
            'assigned' => $cells[3],
            'make' => $cells[4],
            'model' => $cells[5],
            'year' => $cells[6],
            'color' => $cells[7],
            'vin' => $cells[9],
            'status' => $cells[10],
            'class' => $cells[11],
            'class_desc' => $cells[12],
            'dept' => $cells[13],
            'license' => $cells[14],
        ];
    }

    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    // --------------------------------------------------------------- planning

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, list<UnitMasterVehicle>>  $byVeh
     * @param  array<string, list<UnitMasterVehicle>>  $byVin
     * @param  array<string, int>  $csvVehCounts
     * @param  array<string, bool>  $pending
     * @return array<string, mixed>
     */
    private function planRow(array $row, array $byVeh, array $byVin, array $csvVehCounts, array $pending): array
    {
        $plan = [
            'source_row' => $row['source_row'],
            'vehicle_no' => $row['veh'] ?? '',
            'hidden' => (bool) $row['hidden'],
            'class' => self::CLASS_VERIFY,
            'hub_ids' => [],
            'notes' => [],
            'keeps' => [],
            'changes' => [],
            'create' => null,
        ];

        $vehKey = $this->vehKey($row['veh'] ?? '');
        if (($row['malformed'] ?? false) || $vehKey === '') {
            $plan['notes'][] = ($row['malformed'] ?? false) ? 'Row has an unexpected column count.' : 'Vehicle No is empty.';

            return $plan;
        }

        if (($csvVehCounts[$vehKey] ?? 0) > 1) {
            $plan['class'] = self::CLASS_CONFLICT;
            $plan['notes'][] = 'Vehicle No appears more than once in the FY27 list.';

            return $plan;
        }

        $vehMatches = $byVeh[$vehKey] ?? [];
        if (count($vehMatches) > 1) {
            $plan['class'] = self::CLASS_CONFLICT;
            $plan['hub_ids'] = array_map(fn (UnitMasterVehicle $v): int => $v->id, $vehMatches);
            $plan['notes'][] = 'Duplicate Hub records share this vehicle number.';

            return $plan;
        }

        if (count($vehMatches) === 1) {
            return $this->planMatched($plan, $row, $vehMatches[0], $byVin, $pending);
        }

        $vinKey = $this->vinKey($row['vin']);
        $vinMatches = $vinKey === '' ? [] : ($byVin[$vinKey] ?? []);
        if (count($vinMatches) > 1) {
            $plan['class'] = self::CLASS_CONFLICT;
            $plan['hub_ids'] = array_map(fn (UnitMasterVehicle $v): int => $v->id, $vinMatches);
            $plan['notes'][] = 'Multiple Hub records carry this VIN.';

            return $plan;
        }
        if (count($vinMatches) === 1) {
            $hub = $vinMatches[0];
            $plan['class'] = self::CLASS_VIN_MATCH;
            $plan['hub_ids'] = [$hub->id];
            $plan['changes'][] = $this->change($hub, 'veh_number', self::KIND_PROPOSAL, $this->clean((string) $hub->veh_number), $row['veh'], 'Same VIN, different vehicle number in FY27 list', $pending);

            return $plan;
        }

        $plan['class'] = self::CLASS_MISSING;
        $plan['create'] = $this->createAttributes($row);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $row
     * @param  array<string, list<UnitMasterVehicle>>  $byVin
     * @param  array<string, bool>  $pending
     * @return array<string, mixed>
     */
    private function planMatched(array $plan, array $row, UnitMasterVehicle $hub, array $byVin, array $pending): array
    {
        $plan['hub_ids'] = [$hub->id];

        $hubVin = $this->vinKey($hub->serial_number);
        $fyVin = $this->vinKey($row['vin']);
        if ($hubVin !== '' && $fyVin !== '' && $hubVin !== $fyVin) {
            $plan['class'] = self::CLASS_CONFLICT;
            $plan['changes'][] = $this->change($hub, 'serial_number', self::KIND_PROPOSAL, $this->clean((string) $hub->serial_number), $row['vin'], 'VIN conflict for same vehicle number', $pending);

            return $plan;
        }

        $department = $this->deptCode($row['dept']);
        if ($row['dept'] !== '' && $department === '') {
            $plan['notes'][] = 'Department has no numeric code; not compared.';
        }
        $wanted = [
            'make' => $row['make'],
            'model' => $row['model'],
            'year' => $row['year'],
            'tag_number' => $this->isNotApplicable($row['license']) ? '' : $row['license'],
            'serial_number' => $row['vin'],
            'dept_code' => $department,
            self::NAME_FIELD => $row['assigned'],
            'color' => $row['color'],
            'fleet_status_code' => $row['status'],
            'fleet_class_code' => $row['class'],
            'fleet_class_description' => $row['class_desc'],
        ];

        $discrepancy = false;
        foreach (array_merge(self::LEGACY_FIELDS, self::NEW_FIELDS) as $field) {
            $current = $this->clean((string) $hub->getAttribute($field));
            $decision = $this->decide($field, $current, $wanted[$field]);

            if ($decision === 'fill' && $field === 'serial_number' && $this->vinOnOtherRecord($byVin, $fyVin, $hub->id)) {
                $decision = 'conflict';
                $plan['notes'][] = 'FY27 VIN is already on another Hub record.';
            }

            switch ($decision) {
                case 'fill':
                    $plan['changes'][] = $this->change($hub, $field, self::KIND_FILL, $current, $wanted[$field], null, $pending);
                    break;
                case 'refinement':
                    $plan['changes'][] = $this->change($hub, $field, self::KIND_REFINEMENT, $current, $wanted[$field], 'FY27 value is more specific', $pending);
                    $discrepancy = true;
                    break;
                case 'conflict':
                    $plan['changes'][] = $this->change($hub, $field, self::KIND_PROPOSAL, $current, $wanted[$field], 'FY27 value differs from Hub', $pending);
                    $discrepancy = $discrepancy || in_array($field, self::LEGACY_FIELDS, true);
                    break;
                case 'keep':
                    $plan['keeps'][] = $field;
                    break;
            }
        }

        if ($this->clean((string) $hub->asset_category) === '' && $row['class_desc'] !== '') {
            $plan['changes'][] = $this->change($hub, 'asset_category', self::KIND_FILL, '', UnitMasterVehicle::categoryFromClassDescription($row['class_desc']), null, $pending);
        }

        $plan['class'] = $discrepancy ? self::CLASS_DISCREPANCY : self::CLASS_EXACT;

        return $plan;
    }

    /** @return 'skip'|'fill'|'same'|'refinement'|'keep'|'conflict' */
    private function decide(string $field, string $current, string $wanted): string
    {
        if ($wanted === '') {
            return 'skip';
        }
        if ($this->isBlankForField($field, $current)) {
            return 'fill';
        }
        if ($field === self::NAME_FIELD) {
            return $this->nameMatches($current, $wanted) ? 'same' : 'conflict';
        }

        $hubKey = $this->comparisonKey($field, $current);
        $fyKey = $this->comparisonKey($field, $wanted);
        if ($hubKey === $fyKey) {
            return 'same';
        }
        if ($field === 'make' || $field === 'model') {
            if (str_starts_with($fyKey, $hubKey)) {
                return 'refinement';
            }
            if (str_starts_with($hubKey, $fyKey)) {
                return 'keep';
            }
        }

        return 'conflict';
    }

    /** Every meaningful FY27 token must appear in the Hub name; names are never overwritten. */
    private function nameMatches(string $hubName, string $fyName): bool
    {
        $fyTokens = $this->nameTokens($fyName);
        if ($fyTokens === []) {
            return false;
        }

        return array_diff($fyTokens, $this->nameTokens($hubName)) === [];
    }

    /** @return list<string> */
    private function nameTokens(string $name): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, fn (string $token): bool => mb_strlen($token) > 1));
    }

    private function isBlankForField(string $field, string $current): bool
    {
        if ($field === 'tag_number' && $this->isNotApplicable($current)) {
            return true;
        }

        return $this->comparisonKey($field, $current) === '';
    }

    private function comparisonKey(string $field, string $value): string
    {
        return match ($field) {
            'serial_number' => $this->vinKey($value),
            'dept_code' => $this->deptCode($value) ?: $this->alnum($value),
            default => $this->alnum($value),
        };
    }

    /** @param array<string, list<UnitMasterVehicle>> $byVin */
    private function vinOnOtherRecord(array $byVin, string $vinKey, int $hubId): bool
    {
        foreach ($byVin[$vinKey] ?? [] as $other) {
            if ($other->id !== $hubId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $pending
     * @return array<string, mixed>
     */
    private function change(UnitMasterVehicle $hub, string $field, string $kind, string $old, string $new, ?string $note, array $pending): array
    {
        return [
            'field' => $field,
            'kind' => $kind,
            'old' => $old === '' ? null : $old,
            'new' => $new,
            'note' => $note,
            'already_pending' => $kind === self::KIND_PROPOSAL && isset($pending[$hub->id.'|'.$field.'|'.$new]),
        ];
    }

    /** @return array<string, mixed> */
    private function createAttributes(array $row): array
    {
        $blank = fn (string $value): ?string => $value === '' ? null : $value;

        return [
            'veh_number' => $row['veh'],
            'make' => $blank($row['make']),
            'model' => $blank($row['model']),
            'year' => $blank($row['year']),
            'color' => $blank($row['color']),
            'tag_number' => $this->isNotApplicable($row['license']) ? null : $blank($row['license']),
            'serial_number' => $blank($row['vin']),
            'dept_code' => $blank($this->deptCode($row['dept'])),
            self::NAME_FIELD => $blank($row['assigned']),
            'fleet_status_code' => $blank($row['status']),
            'fleet_class_code' => $blank($row['class']),
            'fleet_class_description' => $blank($row['class_desc']),
            'asset_category' => UnitMasterVehicle::categoryFromClassDescription($row['class_desc']),
            'record_source' => UnitMasterVehicleChange::SOURCE_FY27,
            'needs_fleet_review' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $context
     * @param  array<string, int>  $result
     */
    private function createMissing(array $row, array $context, array &$result): void
    {
        $vehKey = $this->vehKey($row['vehicle_no']);
        $exists = UnitMasterVehicle::query()->whereRaw('UPPER(TRIM(veh_number)) = ?', [$vehKey])->exists();
        if ($exists) {
            $result['skipped_creates']++;

            return;
        }

        $vehicle = UnitMasterVehicle::query()->create($row['create']);
        $this->recorder->recordCreated($vehicle, UnitMasterVehicleChange::SOURCE_FY27, $context);
        $result['created']++;
    }

    // ----------------------------------------------------------- aggregation

    /**
     * @param  list<array<string, mixed>>  $planned
     * @return array<string, int>
     */
    private function totals(array $planned): array
    {
        $totals = array_fill_keys(self::CLASSES, 0);
        foreach ($planned as $row) {
            $totals[$row['class']]++;
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $planned
     * @return array<string, int>
     */
    private function counts(array $planned): array
    {
        $counts = ['fills' => 0, 'refinements' => 0, 'proposals_new' => 0, 'proposals_already_pending' => 0, 'creates' => 0];
        foreach ($planned as $row) {
            $counts['creates'] += $row['create'] === null ? 0 : 1;
            foreach ($row['changes'] as $change) {
                if ($change['kind'] === self::KIND_FILL) {
                    $counts['fills']++;
                } elseif ($change['kind'] === self::KIND_REFINEMENT) {
                    $counts['refinements']++;
                } elseif ($change['already_pending']) {
                    $counts['proposals_already_pending']++;
                } else {
                    $counts['proposals_new']++;
                }
            }
        }

        return $counts;
    }

    // --------------------------------------------------------- normalisation

    private function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function vehKey(?string $value): string
    {
        return mb_strtoupper($this->clean($value));
    }

    private function vinKey(?string $value): string
    {
        return preg_replace('/[\s\-]+/u', '', mb_strtoupper((string) $value)) ?? '';
    }

    private function alnum(?string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper((string) $value)) ?? '';
    }

    private function isNotApplicable(?string $value): bool
    {
        return in_array(mb_strtoupper($this->clean($value)), ['N/A', 'NA'], true);
    }

    /** Numeric department code without leading zeros, from "0325 - EMERGENCY MANAGEMENT" or "325". */
    private function deptCode(?string $value): string
    {
        if (preg_match('/^\s*(\d+)/', (string) $value, $matches) !== 1) {
            return '';
        }

        return ltrim($matches[1], '0') === '' ? '0' : ltrim($matches[1], '0');
    }
}
