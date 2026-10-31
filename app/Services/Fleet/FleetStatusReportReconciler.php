<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Services\Display\DisplaySnapshotService;
use App\Support\Fleet\VehicleNumber;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Reconciles a dated Fleet status report against the apparatus rows it names.
 *
 * plan() is read-only. apply() writes the plan in one transaction, compare-and-set against the
 * values the plan saw, through normal model saves so the status ledger and master sync still run.
 * The report is partial: a truck it does not name is never read for a decision or written. Rows
 * are matched on vehicle number only and are never created, renumbered or deleted. An identity
 * disagreement is listed for Fleet, because moving a unit takes a real changeout.
 * See docs/apparatus-changeouts.md section 8.
 */
final class FleetStatusReportReconciler
{
    public const SOURCE = 'fleet_status_report';

    public const OUTCOME_CHANGE = 'change';

    public const OUTCOME_DISCREPANCY = 'discrepancy';

    public const OUTCOME_NO_CHANGE = 'no-change';

    public const SAVE_DIRECTORY = 'app/fleet-status-reports';

    /** What the plan saw on a row. Meters and timestamps are deliberately absent: they are not evidence. */
    private const SNAPSHOT_FIELDS = [
        'vehicle_number', 'unit_master_vehicle_id', 'unit_id', 'slug', 'designation', 'name', 'assignment',
        'status', 'current_location', 'station_id', 'notes', 'daily_checkout_requirement', 'daily_checkout_template',
    ];

    /** The only columns this reconciler (and a saved revert file) may write. */
    private const WRITABLE_FIELDS = [
        'status', 'current_location', 'station_id', 'notes', 'designation', 'name',
        'daily_checkout_requirement', 'daily_checkout_template',
    ];

    private const STATUSES = ['In Service', 'Available', 'Out of Service', 'Maintenance', 'Reserve'];

    private const RESERVE = 'Reserve';

    private const FIELD_LABELS = [
        'status' => 'status', 'current_location' => 'location', 'station_id' => 'station id', 'notes' => 'notes',
        'designation' => 'designation', 'name' => 'name', 'daily_checkout_requirement' => 'Daily Checkout requirement',
        'daily_checkout_template' => 'Daily Checkout template',
    ];

    public function __construct(private readonly UnitMasterVehicleChangeRecorder $recorder) {}

    /**
     * @return array{report: string, report_date: string, rows: list<array{vehicle_number: string, designation: ?string, assignment: string, location: string, status: string, note: ?string}>}
     *
     * @throws InvalidArgumentException when the file is unreadable or a row is malformed
     */
    public function parse(string $path): array
    {
        $data = $this->readJson($path, 'Fleet status report');
        $date = $data['report_date'] ?? null;
        if (! is_string($date) || ! $this->isCalendarDate($date)) {
            throw new InvalidArgumentException('Fleet status report needs a report_date as YYYY-MM-DD.');
        }
        if (! is_array($data['rows'] ?? null) || $data['rows'] === []) {
            throw new InvalidArgumentException('Fleet status report has no rows.');
        }

        return [
            'report' => is_string($data['report'] ?? null) ? $data['report'] : 'Fleet status report',
            'report_date' => $date,
            'rows' => array_map(fn (mixed $row, int $index): array => $this->parseRow($row, $index + 1), array_values($data['rows']), array_keys(array_values($data['rows']))),
        ];
    }

    /**
     * Read-only comparison. Safe to call at any time.
     *
     * @param  array{report: string, report_date: string, rows: list<array<string, mixed>>}  $report
     * @return array{report_date: string, newer_since_utc: string, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function plan(array $report): array
    {
        $trucks = Apparatus::query()->orderBy('id')->get();
        $stations = Station::query()->pluck('id', 'station_number')->all();
        $since = $this->newerSince($report['report_date']);

        $rows = array_map(
            fn (array $row): array => $this->planRow($row, $report, $trucks, $stations, $since),
            $report['rows'],
        );

        return [
            'report_date' => $report['report_date'],
            'newer_since_utc' => $since,
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Write the plan in one transaction. Rows lock in id order; a row that changed since the plan,
     * or that gained newer evidence, is skipped and listed. Before values reach disk inside the
     * transaction, so a failed save rolls the writes back and nothing is applied without its undo.
     *
     * @param  array{report_date: string, newer_since_utc: string, rows: list<array<string, mixed>>}  $plan
     * @return array{rows_written: int, fields_written: int, history_recorded: int, skipped: list<array{vehicle_number: string, reason: string}>, saved_to: ?string}
     */
    public function apply(array $plan, ?string $sourceFile = null): array
    {
        $planned = collect($plan['rows'])
            ->filter(static fn (array $row): bool => $row['outcome'] === self::OUTCOME_CHANGE)
            ->keyBy('apparatus_id')
            ->sortKeys();
        if ($planned->isEmpty()) {
            return ['rows_written' => 0, 'fields_written' => 0, 'history_recorded' => 0, 'skipped' => [], 'saved_to' => null];
        }

        return DB::transaction(function () use ($plan, $planned, $sourceFile): array {
            $locked = Apparatus::query()->whereIn('id', $planned->keys()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $result = ['rows_written' => 0, 'fields_written' => 0, 'history_recorded' => 0, 'skipped' => [], 'saved_to' => null];
            $saved = [];

            foreach ($planned as $id => $row) {
                $truck = $locked->get($id);
                $reason = $this->staleReason($truck, $row, $plan['newer_since_utc']);
                if ($reason !== null) {
                    $result['skipped'][] = ['vehicle_number' => $row['vehicle_number'], 'reason' => $reason];

                    continue;
                }

                $truck->forceFill(array_map(static fn (array $change): mixed => $change['new'], $row['changes']))->save();
                $result['rows_written']++;
                $result['fields_written'] += count($row['changes']);
                $result['history_recorded'] += $this->recordHistory(
                    $truck,
                    'updated from Fleet status report '.$plan['report_date'],
                    'Fleet status report '.$plan['report_date'],
                    $row['changes'],
                ) ? 1 : 0;
                $saved[] = [
                    'apparatus_id' => (int) $id,
                    'vehicle_number' => $row['vehicle_number'],
                    'before' => array_map(static fn (array $change): mixed => $change['old'], $row['changes']),
                    'after' => array_map(static fn (array $change): mixed => $change['new'], $row['changes']),
                ];
            }

            if ($saved !== []) {
                $result['saved_to'] = $this->writeBeforeValues($plan['report_date'], $sourceFile, $saved);
                $this->forgetDisplayCachesAfterCommit();
            }

            return $result;
        });
    }

    /**
     * Restore saved before values with the same compare-and-set: a field goes back only while it
     * still holds the value the apply wrote. Everything else is listed and left alone.
     *
     * @return array{report_date: string, rows_written: int, fields_written: int, history_recorded: int, already_restored: int, skipped: list<array{vehicle_number: string, field: ?string, reason: string}>}
     *
     * @throws InvalidArgumentException when the saved file is unreadable or malformed
     */
    public function revert(string $path): array
    {
        $saved = $this->parseSaved($path);
        $entries = collect($saved['rows'])->keyBy('apparatus_id')->sortKeys();

        return DB::transaction(function () use ($saved, $entries): array {
            $locked = Apparatus::query()->whereIn('id', $entries->keys()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $result = ['report_date' => $saved['report_date'], 'rows_written' => 0, 'fields_written' => 0, 'history_recorded' => 0, 'already_restored' => 0, 'skipped' => []];

            foreach ($entries as $id => $entry) {
                $truck = $locked->get($id);
                if ($truck === null || ! VehicleNumber::same($truck->getRawOriginal('vehicle_number'), $entry['vehicle_number'])) {
                    $result['skipped'][] = ['vehicle_number' => $entry['vehicle_number'], 'field' => null, 'reason' => 'The row no longer carries this vehicle number.'];

                    continue;
                }

                $changes = $this->revertChanges($truck, $entry, $result);
                if ($changes === []) {
                    continue;
                }

                $truck->forceFill(array_map(static fn (array $change): mixed => $change['new'], $changes))->save();
                $result['rows_written']++;
                $result['fields_written'] += count($changes);
                $result['history_recorded'] += $this->recordHistory(
                    $truck,
                    'restored by reverting Fleet status report '.$saved['report_date'],
                    'Fleet status report '.$saved['report_date'].' revert',
                    $changes,
                ) ? 1 : 0;
            }

            if ($result['rows_written'] > 0) {
                $this->forgetDisplayCachesAfterCommit();
            }

            return $result;
        });
    }

    /**
     * Rows as they ended: a row the apply skipped reads as a discrepancy, with the reason.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $applyResult
     * @return array<string, mixed>
     */
    public function report(array $plan, ?array $applyResult, string $file): array
    {
        $skipped = collect($applyResult['skipped'] ?? [])->keyBy('vehicle_number');
        $rows = array_map(function (array $row) use ($skipped): array {
            unset($row['expected']);
            $skip = $skipped->get($row['vehicle_number']);
            if ($skip !== null) {
                $row['outcome'] = self::OUTCOME_DISCREPANCY;
                $row['changes'] = [];
                $row['discrepancies'][] = 'Skipped at write: '.$skip['reason'];
            }

            return $row;
        }, $plan['rows']);

        return [
            'mode' => $applyResult === null ? 'dry-run' : 'applied',
            'file' => basename($file),
            'report_date' => $plan['report_date'],
            'generated_at' => now()->toIso8601String(),
            'totals' => $this->totals($rows),
            'apply_result' => $applyResult,
            'rows' => $rows,
        ];
    }

    /** @param array<string, array<string, mixed>> $changes */
    public static function describeChanges(array $changes): string
    {
        $parts = [];
        foreach ($changes as $field => $change) {
            $label = self::FIELD_LABELS[$field] ?? $field;
            if ($field === 'notes') {
                $parts[] = isset($change['added']) ? $label.': added "'.$change['added'].'"' : $label.': restored';

                continue;
            }
            $parts[] = sprintf('%s: %s -> %s', $label, self::display($change['old']), self::display($change['new']));
        }

        return implode('; ', $parts);
    }

    // --------------------------------------------------------------- planning

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $report
     * @param  Collection<int, Apparatus>  $trucks
     * @param  array<int|string, int>  $stations
     * @return array<string, mixed>
     */
    private function planRow(array $row, array $report, Collection $trucks, array $stations, string $since): array
    {
        $plan = [
            'vehicle_number' => $row['vehicle_number'],
            'report_designation' => $row['designation'],
            'apparatus_id' => null,
            'unit' => null,
            'linked' => false,
            'outcome' => self::OUTCOME_DISCREPANCY,
            'changes' => [],
            'discrepancies' => [],
            'expected' => [],
        ];

        $inFile = array_filter($report['rows'], static fn (array $other): bool => VehicleNumber::same($other['vehicle_number'], $row['vehicle_number']));
        if (count($inFile) > 1) {
            $plan['discrepancies'][] = 'This vehicle number appears more than once in the report.';

            return $plan;
        }

        $holders = $trucks->filter(static fn (Apparatus $truck): bool => VehicleNumber::same($truck->getRawOriginal('vehicle_number'), $row['vehicle_number']));
        if ($holders->isEmpty()) {
            $plan['discrepancies'][] = 'No apparatus row carries this vehicle number.';

            return $plan;
        }
        if ($holders->count() > 1) {
            $plan['discrepancies'][] = 'More than one apparatus row carries this vehicle number (ids '.$holders->pluck('id')->implode(', ').').';

            return $plan;
        }

        return $this->planMatched($plan, $row, $report['report_date'], $holders->first(), $stations, $since);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $row
     * @param  array<int|string, int>  $stations
     * @return array<string, mixed>
     */
    private function planMatched(array $plan, array $row, string $reportDate, Apparatus $truck, array $stations, string $since): array
    {
        $plan['apparatus_id'] = (int) $truck->getKey();
        $plan['unit'] = $truck->getRawOriginal('designation') ?? $truck->getRawOriginal('name');
        $plan['linked'] = $truck->getRawOriginal('unit_master_vehicle_id') !== null;
        $plan['expected'] = $this->snapshot($truck);

        $identity = $this->identityDecision($truck, $row);
        [$operational, $notes] = $this->operationalChanges($truck, $row, $reportDate, $stations);
        $changes = [...$identity['changes'], ...$operational];

        if ($identity['problem'] === null && $changes === []) {
            $plan['outcome'] = $notes === [] ? self::OUTCOME_NO_CHANGE : self::OUTCOME_DISCREPANCY;
            $plan['discrepancies'] = $notes;

            return $plan;
        }

        // Newer evidence comes first: a later changeout is the likely cause of an identity mismatch.
        $newer = $this->newerEvidence((int) $truck->getKey(), $since);
        if ($newer !== null) {
            $plan['discrepancies'] = [$newer, ...$notes];

            return $plan;
        }
        if ($identity['problem'] !== null) {
            $plan['discrepancies'] = [$identity['problem'], ...$notes];

            return $plan;
        }

        $plan['outcome'] = self::OUTCOME_CHANGE;
        $plan['changes'] = $changes;
        $plan['discrepancies'] = $notes;

        return $plan;
    }

    /**
     * The report's designation against the row's. A blank report designation invents nothing; the one
     * identity write is a temporary "Replacement" reserve going back to its plain reserve identity.
     *
     * @param  array<string, mixed>  $row
     * @return array{changes: array<string, array<string, mixed>>, problem: ?string}
     */
    private function identityDecision(Apparatus $truck, array $row): array
    {
        $current = $this->clean($truck->getRawOriginal('designation'));
        $reported = $row['designation'];
        $mismatch = static fn (string $problem): array => ['changes' => [], 'problem' => $problem.' An identity mismatch needs a real changeout.'];

        if ($reported !== null) {
            return $this->alnum($current) === $this->alnum($reported)
                ? ['changes' => [], 'problem' => null]
                : $mismatch(sprintf('Report says %s; the row says %s.', $reported, $current === '' ? '(blank)' : $current));
        }
        if ($current === '' || preg_match('/^reserve\b/i', $current) === 1) {
            return ['changes' => [], 'problem' => null];
        }
        if (! $this->isReserve($row['assignment']) || stripos($current, 'replacement') === false) {
            return $mismatch(sprintf('Report lists no designation; the row says %s.', $current));
        }

        return $this->reserveNormalization($truck, $mismatch);
    }

    /**
     * @param  callable(string): array{changes: array<string, array<string, mixed>>, problem: ?string}  $mismatch
     * @return array{changes: array<string, array<string, mixed>>, problem: ?string}
     */
    private function reserveNormalization(Apparatus $truck, callable $mismatch): array
    {
        $number = (string) $truck->getRawOriginal('vehicle_number');
        $name = $this->clean(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $truck->getRawOriginal('name')));
        $numberKey = $this->alnum($number);
        $identityFits = $numberKey !== ''
            && str_contains($this->alnum($truck->getRawOriginal('unit_id')), $numberKey)
            && str_contains($this->alnum($truck->getRawOriginal('slug')), $numberKey)
            && str_ends_with(mb_strtoupper($name), ' '.mb_strtoupper($number));
        if (! $identityFits) {
            return $mismatch('The row\'s unit ID, slug or name do not fit a plain reserve identity for this number.');
        }

        $wanted = [
            'designation' => self::RESERVE,
            'name' => $name,
            'daily_checkout_requirement' => DailyCheckoutRequirement::Reserve->value,
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Pending->value,
        ];
        $changes = [];
        foreach ($wanted as $field => $new) {
            $old = $truck->getRawOriginal($field);
            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return ['changes' => $changes, 'problem' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int|string, int>  $stations
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private function operationalChanges(Apparatus $truck, array $row, string $reportDate, array $stations): array
    {
        $changes = [];
        $notes = [];

        $status = $truck->getRawOriginal('status');
        if ($this->statusKey($status) !== $this->statusKey($row['status'])) {
            $changes['status'] = ['old' => $status, 'new' => $row['status']];
        }

        $location = $truck->getRawOriginal('current_location');
        if (mb_strtoupper($this->clean($location)) !== mb_strtoupper($row['location'])) {
            $changes['current_location'] = ['old' => $location, 'new' => $row['location']];
        }

        if (preg_match('/^Station\s+(\d+)$/i', $row['location'], $match) === 1) {
            $this->planStation($truck, $row, (string) (int) $match[1], $stations, $changes, $notes);
        }

        if ($row['note'] !== null) {
            $this->planNote($truck, $row['note'], $reportDate, $changes);
        }

        $assignment = $this->clean($truck->getRawOriginal('assignment'));
        if (mb_strtoupper($assignment) !== mb_strtoupper($row['assignment'])) {
            $notes[] = sprintf('Report assignment %s; the row says %s. Not changed.', $row['assignment'], $assignment === '' ? '(blank)' : $assignment);
        }

        return [$changes, $notes];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int|string, int>  $stations
     * @param  array<string, array<string, mixed>>  $changes
     * @param  list<string>  $notes
     */
    private function planStation(Apparatus $truck, array $row, string $stationNumber, array $stations, array &$changes, array &$notes): void
    {
        $stationId = $stations[$stationNumber] ?? null;
        if ($stationId === null) {
            $notes[] = "No station has number {$stationNumber}. Station not changed.";

            return;
        }

        $current = $truck->getRawOriginal('station_id');
        if ($this->sameValue($current, $stationId)) {
            return;
        }
        if ($this->isReserve($row['assignment'])) {
            $changes['station_id'] = ['old' => $current, 'new' => (int) $stationId];

            return;
        }
        $notes[] = "Report location is Station {$stationNumber} but the row's station id is ".self::display($current).'. A frontline station moves only with a changeout.';
    }

    /** @param array<string, array<string, mixed>> $changes */
    private function planNote(Apparatus $truck, string $note, string $reportDate, array &$changes): void
    {
        $text = sprintf('%s (Fleet status report %s)', $note, $reportDate);
        $current = $truck->getRawOriginal('notes');
        if (is_string($current) && str_contains($current, $text)) {
            return;
        }
        $changes['notes'] = [
            'old' => $current,
            'new' => trim((string) $current) === '' ? $text : rtrim((string) $current)."\n".$text,
            'added' => $text,
        ];
    }

    /**
     * The start of the report date in Fleet's time zone, in UTC at the stored second precision. The report
     * carries a date and no time, so anything from that day on may be newer than the report and is listed.
     */
    private function newerSince(string $reportDate): string
    {
        return CarbonImmutable::parse($reportDate, HubTimezone::NAME)->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    private function newerEvidence(int $apparatusId, string $since): ?string
    {
        return $this->newerStatusEvent($apparatusId, $since)
            ?? $this->newerSwapDecision($apparatusId, $since)
            ?? $this->newerChangeoutDecision($apparatusId, $since);
    }

    private function newerStatusEvent(int $apparatusId, string $since): ?string
    {
        if (! Schema::hasTable('apparatus_operational_status_events')) {
            return null;
        }
        $event = ApparatusOperationalStatusEvent::query()
            ->where('apparatus_id', $apparatusId)
            ->where('changed_at', '>=', $since)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        return $event === null ? null : sprintf('Newer data: status changed to %s at %s UTC, on or after the report date.', self::display($event->status), $event->getRawOriginal('changed_at'));
    }

    private function newerSwapDecision(int $apparatusId, string $since): ?string
    {
        if (! Schema::hasTable('apparatus_vehicle_swap_requests')) {
            return null;
        }
        $swap = ApparatusVehicleSwapRequest::query()
            ->where(static function ($query) use ($apparatusId): void {
                $query->where('apparatus_id', $apparatusId)
                    ->orWhere('reported_apparatus_id', $apparatusId)
                    ->orWhere('confirmed_apparatus_id', $apparatusId);
            })
            ->where('decided_at', '>=', $since)
            ->orderByDesc('decided_at')
            ->first();

        return $swap === null ? null : sprintf('Newer data: vehicle swap report %d was decided at %s UTC, on or after the report date.', $swap->getKey(), $swap->getRawOriginal('decided_at'));
    }

    private function newerChangeoutDecision(int $apparatusId, string $since): ?string
    {
        if (! Schema::hasTable('apparatus_changeouts')) {
            return null;
        }
        $changeout = ApparatusChangeout::query()
            ->where(static function ($query) use ($apparatusId): void {
                $query->where('from_apparatus_id', $apparatusId)->orWhere('to_apparatus_id', $apparatusId);
            })
            ->where(static function ($query) use ($since): void {
                $query->where('decided_at', '>=', $since)->orWhere('effective_at', '>=', $since);
            })
            ->orderByDesc('id')
            ->first();
        if ($changeout === null) {
            return null;
        }
        $when = $changeout->getRawOriginal('effective_at') ?? $changeout->getRawOriginal('decided_at');

        return sprintf('Newer data: changeout %d was recorded at %s UTC, on or after the report date.', $changeout->getKey(), $when);
    }

    // ---------------------------------------------------------------- writing

    /** @param array<string, mixed> $row */
    private function staleReason(?Apparatus $truck, array $row, string $since): ?string
    {
        if ($truck === null) {
            return 'The row no longer exists.';
        }
        foreach ($this->snapshot($truck) as $field => $value) {
            if (! $this->sameValue($value, $row['expected'][$field] ?? null)) {
                return sprintf('%s changed after the plan was built.', self::FIELD_LABELS[$field] ?? $field);
            }
        }

        return $this->newerEvidence((int) $truck->getKey(), $since);
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function recordHistory(Apparatus $truck, string $event, string $reference, array $changes): bool
    {
        $masterId = $truck->getRawOriginal('unit_master_vehicle_id');
        $vehicle = $masterId === null ? null : UnitMasterVehicle::query()->find($masterId);
        if ($vehicle === null) {
            return false;
        }

        $this->recorder->recordEvent($vehicle, $event, self::SOURCE, [
            'source_reference' => $reference,
            'note' => $reference.': '.self::describeChanges($changes),
        ]);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws RuntimeException when the before values cannot be saved; the transaction rolls back
     */
    private function writeBeforeValues(string $reportDate, ?string $sourceFile, array $rows): string
    {
        $directory = storage_path(self::SAVE_DIRECTORY);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        $path = $directory.DIRECTORY_SEPARATOR.now('UTC')->format('Ymd\THis_u\Z').'.json';
        try {
            $json = json_encode([
                'source' => self::SOURCE,
                'report_date' => $reportDate,
                'report_file' => $sourceFile === null ? null : basename($sourceFile),
                'applied_at' => now('UTC')->toIso8601String(),
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode the before values: '.$exception->getMessage(), 0, $exception);
        }
        if (file_put_contents($path, $json.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        return $path;
    }

    private function forgetDisplayCachesAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
            Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        });
    }

    // ---------------------------------------------------------------- reverting

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $result
     * @return array<string, array<string, mixed>>
     */
    private function revertChanges(Apparatus $truck, array $entry, array &$result): array
    {
        $changes = [];
        foreach ($entry['after'] as $field => $applied) {
            $current = $truck->getRawOriginal($field);
            $before = $entry['before'][$field] ?? null;
            if ($this->sameValue($current, $applied)) {
                $changes[$field] = ['old' => $current, 'new' => $before];
            } elseif ($this->sameValue($current, $before)) {
                $result['already_restored']++;
            } else {
                $result['skipped'][] = ['vehicle_number' => $entry['vehicle_number'], 'field' => $field, 'reason' => 'The field no longer holds the applied value.'];
            }
        }

        return $changes;
    }

    /**
     * @return array{report_date: string, rows: list<array{apparatus_id: int, vehicle_number: string, before: array<string, mixed>, after: array<string, mixed>}>}
     */
    private function parseSaved(string $path): array
    {
        $data = $this->readJson($path, 'Saved before-values file');
        if (($data['source'] ?? null) !== self::SOURCE || ! is_string($data['report_date'] ?? null) || ! is_array($data['rows'] ?? null)) {
            throw new InvalidArgumentException('This is not a saved Fleet status report apply.');
        }

        foreach ($data['rows'] as $row) {
            $valid = is_array($row) && is_int($row['apparatus_id'] ?? null) && is_string($row['vehicle_number'] ?? null)
                && is_array($row['before'] ?? null) && is_array($row['after'] ?? null)
                && array_keys($row['before']) === array_keys($row['after'])
                && array_diff(array_keys($row['after']), self::WRITABLE_FIELDS) === [];
            if (! $valid) {
                throw new InvalidArgumentException('The saved file has a malformed row or names a field this command may not write.');
            }
        }

        return ['report_date' => $data['report_date'], 'rows' => array_values($data['rows'])];
    }

    // ---------------------------------------------------------------- parsing

    /** @return array<string, mixed> */
    private function readJson(string $path, string $label): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$label} not found or unreadable: {$path}");
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException("{$label} is not valid JSON: ".$exception->getMessage(), 0, $exception);
        }
        if (! is_array($data)) {
            throw new InvalidArgumentException("{$label} must be a JSON object.");
        }

        return $data;
    }

    /** @return array{vehicle_number: string, designation: ?string, assignment: string, location: string, status: string, note: ?string} */
    private function parseRow(mixed $row, int $position): array
    {
        if (! is_array($row)) {
            throw new InvalidArgumentException("Report row {$position} is not an object.");
        }
        // A JSON number would already have lost its zeros and dashes: the number must arrive as a string.
        $number = is_string($row['vehicle_number'] ?? null) ? VehicleNumber::normalize($row['vehicle_number']) : null;
        if (! VehicleNumber::isWellFormed($number)) {
            throw new InvalidArgumentException("Report row {$position} needs vehicle_number as a string.");
        }

        $parsed = ['vehicle_number' => (string) $number];
        foreach (['assignment', 'location', 'status'] as $field) {
            $value = is_string($row[$field] ?? null) ? $this->clean($row[$field]) : '';
            if ($value === '') {
                throw new InvalidArgumentException("Report row {$position} ({$number}) needs {$field}.");
            }
            $parsed[$field] = $value;
        }
        if (! in_array($parsed['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException("Report row {$position} ({$number}) has unknown status {$parsed['status']}.");
        }
        foreach (['designation', 'note'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException("Report row {$position} ({$number}) needs {$field} as text or null.");
            }
            $clean = $this->clean($value);
            $parsed[$field] = in_array($clean, ['', '-'], true) ? null : $clean;
        }

        return $parsed;
    }

    private function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function snapshot(Apparatus $truck): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $truck->getRawOriginal($field);
        }

        return $snapshot;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function totals(array $rows): array
    {
        $totals = [self::OUTCOME_CHANGE => 0, self::OUTCOME_DISCREPANCY => 0, self::OUTCOME_NO_CHANGE => 0];
        foreach ($rows as $row) {
            $totals[$row['outcome']]++;
        }

        return $totals;
    }

    private function sameValue(mixed $first, mixed $second): bool
    {
        return $first === null || $second === null ? $first === $second : (string) $first === (string) $second;
    }

    private function isReserve(?string $value): bool
    {
        return strcasecmp($this->clean($value), self::RESERVE) === 0;
    }

    /** Legacy rows may hold in_service for In Service; the two mean the same and never cause a write. */
    private function statusKey(mixed $value): string
    {
        return mb_strtoupper(str_replace('_', ' ', $this->clean(is_string($value) ? $value : null)));
    }

    private function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function alnum(?string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper((string) $value)) ?? '';
    }

    private static function display(mixed $value): string
    {
        return $value === null || $value === '' ? '(blank)' : (string) $value;
    }
}
