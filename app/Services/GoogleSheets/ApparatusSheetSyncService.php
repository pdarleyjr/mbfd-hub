<?php

namespace App\Services\GoogleSheets;

use App\Models\Apparatus;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApparatusSheetSyncService
{
    private Sheets $sheetsService;

    private string $spreadsheetId;
    private string $tabTitle;
    private int $expectedSheetId;
    private int $retryMax;
    private int $retryBaseMs;

    public function __construct()
    {
        $this->spreadsheetId   = config('google_sheets.spreadsheet_id');
        $this->tabTitle        = config('google_sheets.tab_title');
        $this->expectedSheetId = (int) config('google_sheets.tab_sheet_id');
        $this->retryMax        = (int) config('google_sheets.retry_max_attempts', 5);
        $this->retryBaseMs     = (int) config('google_sheets.retry_base_delay_ms', 500);
    }

    /**
     * Run the full one-way sync: apparatuses -> Equipment Maintenance tab.
     */
    public function sync(bool $dryRun = false): array
    {
        $this->bootClient();
        $this->verifyMetadata();

        $rows = $this->buildRows();

        if ($dryRun) {
            Log::info('[ApparatusSheetSync] DRY RUN — would write ' . count($rows) . ' data rows');
            return ['dry_run' => true, 'rows' => count($rows), 'data' => $rows];
        }

        $headerRange = "'{$this->tabTitle}'!A1:F1";
        $bodyRange   = "'{$this->tabTitle}'!A2:F1000";

        // 1. Clear body range only (preserves formatting/data validation)
        $this->withRetry(fn () => $this->sheetsService->spreadsheets_values->clear(
            $this->spreadsheetId,
            $bodyRange,
            new \Google\Service\Sheets\ClearValuesRequest()
        ));

        // 2. Write header
        $this->withRetry(fn () => $this->sheetsService->spreadsheets_values->update(
            $this->spreadsheetId,
            $headerRange,
            new ValueRange(['values' => [['Designation', 'Vehicle#', 'Status', 'Location', 'Comments', 'Reported']]]),
            ['valueInputOption' => 'RAW']
        ));

        // 3. Write body if we have rows
        if (!empty($rows)) {
            $this->withRetry(fn () => $this->sheetsService->spreadsheets_values->update(
                $this->spreadsheetId,
                "'{$this->tabTitle}'!A2:F" . (count($rows) + 1),
                new ValueRange(['values' => $rows]),
                ['valueInputOption' => 'RAW']
            ));
        }

        Log::info('[ApparatusSheetSync] Sync complete — wrote ' . count($rows) . ' rows to ' . $this->tabTitle);

        return ['dry_run' => false, 'rows' => count($rows)];
    }

    /**
     * Build a row for each apparatus record.
     * Column mapping: A=Designation B=Vehicle# C=Status D=Location E=Comments F=Reported
     */
    private function buildRows(): array
    {
        return Apparatus::with('station')
            ->orderBy('designation')
            ->get()
            ->map(function (Apparatus $a) {
                return [
                    $a->designation ?? '',
                    $a->vehicle_number ?? '',
                    $a->status ?? '',
                    $this->buildLocation($a),
                    $a->notes ?? '',
                    $a->reported_at
                        ? Carbon::parse($a->reported_at)->format('n/j/Y')
                        : '',
                ];
            })
            ->toArray();
    }

    /**
     * Condense station, assignment, and current_location into a single
     * operationally useful Location string.
     */
    private function buildLocation(Apparatus $a): string
    {
        $stationLabel = $a->station ? 'Station ' . $a->station->station_number : null;
        $assignment   = trim($a->assignment ?? '');
        $currentLoc   = trim($a->current_location ?? '');

        if ($currentLoc && $currentLoc === $assignment) {
            $currentLoc = '';
        }

        if ($currentLoc && $assignment && $currentLoc !== $stationLabel) {
            return "{$assignment} -> {$currentLoc}";
        }

        return $currentLoc ?: $assignment ?: $stationLabel ?: '';
    }

    private function bootClient(): void
    {
        $keyPath = config('google_sheets.service_account_json');

        if (!file_exists($keyPath)) {
            throw new \RuntimeException(
                "[ApparatusSheetSync] Service account key not found at: {$keyPath}"
            );
        }

        $client = new Client();
        $client->setApplicationName('MBFD Hub Apparatus Sync');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig($keyPath);

        $this->sheetsService = new Sheets($client);
    }

    private function verifyMetadata(): void
    {
        $spreadsheet = $this->withRetry(
            fn () => $this->sheetsService->spreadsheets->get($this->spreadsheetId)
        );

        $found = false;
        foreach ($spreadsheet->getSheets() as $sheet) {
            $props = $sheet->getProperties();
            if ($props->getTitle() === $this->tabTitle) {
                $actualSheetId = (int) $props->getSheetId();
                if ($actualSheetId !== $this->expectedSheetId) {
                    throw new \RuntimeException(
                        "[ApparatusSheetSync] Tab '{$this->tabTitle}' sheetId mismatch: " .
                        "expected {$this->expectedSheetId}, got {$actualSheetId}. Aborting."
                    );
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new \RuntimeException(
                "[ApparatusSheetSync] Tab '{$this->tabTitle}' not found in spreadsheet {$this->spreadsheetId}. Aborting."
            );
        }

        Log::debug('[ApparatusSheetSync] Metadata verified — tab and sheetId match expected values.');
    }

    private function withRetry(callable $callback): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (\Google\Service\Exception $e) {
                $attempt++;
                $statusCode = $e->getCode();

                if (!in_array($statusCode, [429, 500, 502, 503, 504], true) || $attempt >= $this->retryMax) {
                    Log::error('[ApparatusSheetSync] Google API error (no retry): ' . $e->getMessage());
                    throw $e;
                }

                $delayMs = min($this->retryBaseMs * (2 ** ($attempt - 1)) + random_int(0, 200), 32000);
                Log::warning("[ApparatusSheetSync] Transient error (HTTP {$statusCode}), retry {$attempt}/{$this->retryMax} after {$delayMs}ms");
                usleep($delayMs * 1000);
            } catch (Throwable $e) {
                Log::error('[ApparatusSheetSync] Unexpected error: ' . $e->getMessage());
                throw $e;
            }
        }
    }
}
