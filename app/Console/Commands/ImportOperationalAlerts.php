<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Apparatus;
use App\Models\ApparatusOperationalAlert;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Fleet\ApparatusOperationalAlertService;
use App\Support\Fleet\VehicleNumber;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Loads alerts Fleet announced outside the Hub, each with its ticket. Idempotent by dedupe_key. A row is matched on
 * the truck's vehicle number and refused when that truck no longer carries the expected unit, so an alert can never
 * attach to the wrong truck. Nothing here changes an apparatus or sends a notification the file did not ask for.
 */
final class ImportOperationalAlerts extends Command
{
    protected $signature = 'fleet:import-operational-alerts
                            {--file= : Path to the alerts JSON file}
                            {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Import Fleet operational alerts and their service tickets from a JSON file (idempotent)';

    public function handle(ApparatusOperationalAlertService $alerts, ApparatusServiceTicketWorkflowService $tickets): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $this->readRows((string) $this->option('file'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $refused = 0;
        foreach ($rows as $row) {
            $key = (string) $row['dedupe_key'];
            if (ApparatusOperationalAlert::query()->where('dedupe_key', $key)->exists()) {
                $this->line("{$key}: already imported, nothing changed.");

                continue;
            }
            try {
                $alert = $this->commitOrRollBack($dryRun, fn (): ApparatusOperationalAlert => $this->importRow($row, $alerts, $tickets));
                $this->info($dryRun
                    ? "{$key}: would create an alert and ticket on truck {$alert->vehicle_number} ({$alert->unit_designation}). Dry run, nothing written."
                    : "{$key}: created alert {$alert->getKey()} and ticket {$alert->apparatus_service_ticket_id} on truck {$alert->vehicle_number} ({$alert->unit_designation}).");
            } catch (InvalidArgumentException $exception) {
                $refused++;
                $this->error("{$key}: refused. {$exception->getMessage()}");
            } catch (ValidationException $exception) {
                $refused++;
                $this->error("{$key}: refused. ".implode(' ', $exception->validator->errors()->all()));
            }
        }

        return $refused === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A dry run does the real work and rolls it back, so it reports exactly what a real run would do or refuse.
     *
     * @param  Closure(): ApparatusOperationalAlert  $work
     */
    private function commitOrRollBack(bool $dryRun, Closure $work): ApparatusOperationalAlert
    {
        if (! $dryRun) {
            return DB::transaction($work);
        }

        DB::beginTransaction();
        try {
            return $work();
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<string, mixed> $row */
    private function importRow(array $row, ApparatusOperationalAlertService $alerts, ApparatusServiceTicketWorkflowService $tickets): ApparatusOperationalAlert
    {
        $truck = $this->resolveTruck((string) $row['vehicle_number'], (string) $row['expected_unit']);
        $ticket = $tickets->recordFleetNotice($truck, $row['ticket'])->ticket;

        return $alerts->create([
            ...collect($row)->only([
                'dedupe_key', 'category', 'title', 'message', 'reason', 'scheduled_date', 'scheduled_window',
                'scheduled_at', 'source', 'send_notification',
            ])->all(),
            'apparatus_id' => $truck->getKey(),
            'apparatus_service_ticket_id' => $ticket->getKey(),
        ], null);
    }

    private function resolveTruck(string $vehicleNumber, string $expectedUnit): Apparatus
    {
        $matches = Apparatus::query()
            ->whereNotNull('vehicle_number')
            ->get()
            ->filter(fn (Apparatus $truck): bool => VehicleNumber::same($truck->vehicle_number, $vehicleNumber))
            ->values();
        if ($matches->isEmpty()) {
            throw new InvalidArgumentException("No truck carries vehicle number {$vehicleNumber}. Nothing was created for it.");
        }
        if ($matches->count() > 1) {
            throw new InvalidArgumentException("More than one truck carries vehicle number {$vehicleNumber}. Fleet needs to resolve that first.");
        }

        // Held for the rest of this transaction, so a swap cannot slip between this check and the alert.
        $truck = Apparatus::query()->lockForUpdate()->findOrFail($matches->first()->getKey());
        $expected = $this->identityToken($expectedUnit);
        $held = array_map($this->identityToken(...), [(string) $truck->designation, (string) $truck->getAttribute('unit_id'), (string) $truck->slug]);
        if (! in_array($expected, $held, true)) {
            $current = trim((string) ($truck->designation ?: $truck->getAttribute('unit_id'))) ?: 'no unit';
            throw new InvalidArgumentException("{$expectedUnit} is no longer on truck {$vehicleNumber}; that truck now holds {$current}. Check with Fleet and raise the alert on the unit's current truck.");
        }

        return $truck;
    }

    /** "R 44", "R44" and "r-44" are the same unit name. */
    private function identityToken(string $value): string
    {
        return strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /** @return list<array<string, mixed>> */
    private function readRows(string $file): array
    {
        $path = is_file($file) ? $file : base_path($file);
        if ($file === '' || ! is_file($path)) {
            throw new InvalidArgumentException($file === '' ? 'Pass the alerts file with --file.' : "The file {$file} was not found.");
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("The file {$file} is not valid JSON: {$exception->getMessage()}");
        }

        $validator = Validator::make(is_array($decoded) ? $decoded : [], [
            'alerts' => ['required', 'array', 'min:1'],
            'alerts.*.dedupe_key' => ['required', 'string', 'distinct'],
            'alerts.*.vehicle_number' => ['required', 'string'],
            'alerts.*.expected_unit' => ['required', 'string'],
            'alerts.*.ticket' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException("The file {$file} is missing required values: ".implode(' ', $validator->errors()->all()));
        }

        return array_values($decoded['alerts']);
    }
}
