<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Notifications\EquipmentDefectNotification;
use App\Services\SnipeItService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AuditEquipmentAfterInspection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 300;

    public function __construct(
        protected int $inspectionId,
        protected int $apparatusId,
    ) {}

    public function handle(SnipeItService $snipeIt): void
    {
        $inspection = ApparatusInspection::find($this->inspectionId);
        $apparatus = Apparatus::find($this->apparatusId);

        if (! $inspection || ! $apparatus || ! $apparatus->snipeit_asset_id) {
            Log::warning('[SnipeItAudit] Skipping — missing inspection, apparatus, or Snipe-IT mapping', [
                'inspection_id' => $this->inspectionId,
                'apparatus_id' => $this->apparatusId,
            ]);

            return;
        }

        $snipeitAssetId = $apparatus->snipeit_asset_id;
        $inspectionRef = $inspection->inspection_reference ?? ('INS-'.now()->format('Y-m-d').'-'.$inspection->id);

        // 1. Get all equipment checked out to this apparatus from Snipe-IT
        $equipment = $snipeIt->getAssetsCheckedOutTo($snipeitAssetId);
        if (empty($equipment)) {
            Log::info('[SnipeItAudit] No equipment found on apparatus', ['snipeit_asset_id' => $snipeitAssetId]);

            return;
        }

        // 2. Build a lookup of inspection results: item_name_lower => status
        $inspectionItems = $this->buildInspectionItemMap($inspection);

        // 3. Determine apparatus display info
        $apparatusName = $apparatus->designation ?? $apparatus->name ?? 'Unknown';
        $apparatusTag = $apparatus->snipeit_asset_tag ?? '';
        $stationName = $apparatus->current_location ?? $apparatus->assignment ?? 'Unknown Station';
        $operatorName = $inspection->operator_name ?? 'Unknown';
        $rank = $inspection->rank ?? '';
        $shift = $inspection->shift ?? '';
        $employeeId = '';
        if ($inspection->employee_id) {
            $employee = \App\Models\Employee::find($inspection->employee_id);
            $employeeId = $employee?->employee_id ?? '';
        }
        $inspectionDate = $inspection->completed_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i');

        $audited = 0;
        $damaged = 0;
        $missing = 0;
        $unmatched = 0;

        // 4. Process each piece of equipment
        foreach ($equipment as $asset) {
            $assetId = $asset['id'] ?? null;
            $assetTag = $asset['asset_tag'] ?? '';
            $assetName = html_entity_decode($asset['name'] ?? '', ENT_QUOTES, 'UTF-8');

            if (! $assetId) {
                continue;
            }

            // Match against inspection results
            $matchResult = $this->matchAssetToInspection($assetName, $assetTag, $inspectionItems);
            $status = $matchResult['status'];
            $compartment = $matchResult['compartment'] ?? 'General';
            $notes = $matchResult['notes'] ?? '';

            // An asset on the SnipeIT manifest is not proof that it was seen on
            // this inspection. Never certify unmatched equipment as inspected.
            if ($status === 'unmatched') {
                $unmatched++;
                Log::warning('[SnipeItAudit] Asset skipped because it was not on the submitted checklist', [
                    'inspection_ref' => $inspectionRef,
                    'asset_id' => $assetId,
                    'asset_tag' => $assetTag,
                    'asset_name' => $assetName,
                ]);

                continue;
            }

            // Rate limit: 100ms between API calls
            usleep(100_000);

            if ($status === 'Damaged') {
                $damaged++;
                $this->handleDamagedAsset(
                    $snipeIt, $assetId, $assetTag, $assetName, $compartment,
                    $apparatusName, $apparatusTag, $stationName,
                    $operatorName, $rank, $shift, $employeeId,
                    $inspectionDate, $inspectionRef, $notes
                );
            } elseif ($status === 'Missing') {
                $missing++;
                $this->handleMissingAsset(
                    $snipeIt, $assetId, $assetTag, $assetName, $compartment,
                    $apparatusName, $apparatusTag, $stationName,
                    $operatorName, $rank, $shift, $employeeId,
                    $inspectionDate, $inspectionRef
                );
            } else {
                $audited++;
                $this->handlePresentAsset(
                    $snipeIt, $assetTag, $assetName, $compartment,
                    $apparatusName, $apparatusTag, $stationName,
                    $operatorName, $rank, $shift, $employeeId,
                    $inspectionDate, $inspectionRef
                );
            }
        }

        Log::info('[SnipeItAudit] Completed', [
            'apparatus' => $apparatusName,
            'inspection_ref' => $inspectionRef,
            'audited' => $audited,
            'damaged' => $damaged,
            'missing' => $missing,
            'unmatched' => $unmatched,
            'total_equipment' => count($equipment),
        ]);
    }

    /**
     * Build a map of checklist item names to their inspection status.
     * Returns: [ 'lower_name' => ['status' => 'Present|Missing|Damaged', 'compartment' => '...', 'notes' => '...', 'snipeit_match' => '...'] ]
     */
    protected function buildInspectionItemMap(ApparatusInspection $inspection): array
    {
        $map = [];
        $results = $inspection->results ?? [];

        foreach ($results as $compartment) {
            $compTitle = $compartment['title'] ?? $compartment['id'] ?? 'Unknown';
            $items = $compartment['items'] ?? [];

            foreach ($items as $item) {
                $name = $item['name'] ?? '';
                $status = $item['status'] ?? 'Present';
                $snipeitMatch = $item['snipeit_match'] ?? null;
                $notes = $item['notes'] ?? '';

                $key = strtolower(trim($name));
                $map[$key] = [
                    'status' => $status,
                    'compartment' => $compTitle,
                    'notes' => $notes,
                    'snipeit_match' => $snipeitMatch,
                ];

                // Also index by snipeit_match name for faster lookup
                if ($snipeitMatch) {
                    $map[strtolower(trim($snipeitMatch))] = [
                        'status' => $status,
                        'compartment' => $compTitle,
                        'notes' => $notes,
                        'snipeit_match' => $snipeitMatch,
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * Match a Snipe-IT asset to an inspection checklist item.
     */
    protected function matchAssetToInspection(string $assetName, string $assetTag, array $inspectionItems): array
    {
        $lowerName = strtolower(trim($assetName));

        // Direct name match
        if (isset($inspectionItems[$lowerName])) {
            return $inspectionItems[$lowerName];
        }

        // Try matching by snipeit_match values
        foreach ($inspectionItems as $item) {
            if (isset($item['snipeit_match']) && strtolower(trim($item['snipeit_match'])) === $lowerName) {
                return $item;
            }
        }

        // Fuzzy match: check if asset name is contained in or contains a checklist item name
        foreach ($inspectionItems as $key => $item) {
            if (str_contains($key, $lowerName) || str_contains($lowerName, $key)) {
                return $item;
            }
        }

        // No match found. The caller must skip rather than infer physical presence.
        return ['status' => 'unmatched', 'compartment' => 'Manifest', 'notes' => ''];
    }

    protected function handlePresentAsset(
        SnipeItService $snipeIt, string $assetTag, string $assetName, string $compartment,
        string $apparatusName, string $apparatusTag, string $stationName,
        string $operatorName, string $rank, string $shift, string $employeeId,
        string $inspectionDate, string $inspectionRef
    ): void {
        $badgeInfo = $employeeId ? " (Badge #{$employeeId})" : '';
        $shiftInfo = $shift ? " | Shift: {$shift}" : '';

        $note = <<<EOT
DAILY APPARATUS INSPECTION — EQUIPMENT VERIFIED

Equipment: {$assetName} (Tag: {$assetTag})
Apparatus: {$apparatusName} ({$apparatusTag}) — {$stationName}
Compartment: {$compartment}

Inspected by: {$rank} {$operatorName}{$badgeInfo}
Date: {$inspectionDate}{$shiftInfo}

This equipment was physically located, visually inspected, and
confirmed present, accounted for, and in serviceable operating
condition during the daily apparatus checkout procedure conducted
in accordance with MBFD Standard Operating Guidelines.

All compartment contents were inventoried and cross-referenced
against the authorized equipment manifest for this apparatus.

Inspection Ref: {$inspectionRef}
EOT;

        $nextAuditDate = now()->addDays(2)->format('Y-m-d');

        $snipeIt->auditAsset($assetTag, trim($note), $nextAuditDate);
    }

    protected function handleDamagedAsset(
        SnipeItService $snipeIt, int $assetId, string $assetTag, string $assetName, string $compartment,
        string $apparatusName, string $apparatusTag, string $stationName,
        string $operatorName, string $rank, string $shift, string $employeeId,
        string $inspectionDate, string $inspectionRef, string $defectNotes
    ): void {
        $badgeInfo = $employeeId ? " (Badge #{$employeeId})" : '';
        $shiftInfo = $shift ? " | Shift: {$shift}" : '';
        $defectDetails = $defectNotes ? "\nOperator Notes: \"{$defectNotes}\"\n" : '';

        $note = <<<EOT
DAILY APPARATUS INSPECTION — EQUIPMENT DEFECT REPORTED

Equipment: {$assetName} (Tag: {$assetTag})
Apparatus: {$apparatusName} ({$apparatusTag}) — {$stationName}
Compartment: {$compartment}

Reported by: {$rank} {$operatorName}{$badgeInfo}
Date: {$inspectionDate}{$shiftInfo}

DEFECT: This equipment was found in a DAMAGED condition during
the daily apparatus checkout procedure. The item has been flagged
for immediate removal from service pending maintenance review.
{$defectDetails}
ACTION TAKEN:
- Equipment status changed to OUT FOR REPAIR
- Maintenance work order initiated
- Logistics notified for replacement assessment

Inspection Ref: {$inspectionRef}
EOT;

        // 1. Audit with damage note
        $snipeIt->auditAsset($assetTag, trim($note));

        // 2. Change status to the configured Snipe-IT Out for Repair label.
        $snipeIt->updateAssetStatus($assetId, (int) config('snipeit.status_ids.out_for_repair'));

        // 3. Create maintenance record
        $snipeIt->createMaintenanceRecord(
            $assetId,
            "Defect reported during daily inspection — {$apparatusName}",
            'Repair',
            "Reported by {$rank} {$operatorName} during daily apparatus checkout of {$apparatusName}.\n\nDefect details: ".($defectNotes ?: 'Damaged — see inspection report.')."\n\nInspection Ref: {$inspectionRef}",
            now()->format('Y-m-d')
        );

        // 4. Send notification
        $this->notifyAdmin('damaged', $assetName, $assetTag, $apparatusName, $operatorName, $defectNotes, $inspectionRef);
    }

    protected function handleMissingAsset(
        SnipeItService $snipeIt, int $assetId, string $assetTag, string $assetName, string $compartment,
        string $apparatusName, string $apparatusTag, string $stationName,
        string $operatorName, string $rank, string $shift, string $employeeId,
        string $inspectionDate, string $inspectionRef
    ): void {
        $badgeInfo = $employeeId ? " (Badge #{$employeeId})" : '';
        $shiftInfo = $shift ? " | Shift: {$shift}" : '';

        $note = <<<EOT
DAILY APPARATUS INSPECTION — EQUIPMENT NOT FOUND

Equipment: {$assetName} (Tag: {$assetTag})
Apparatus: {$apparatusName} ({$apparatusTag}) — {$stationName}
Compartment: {$compartment}

Reported by: {$rank} {$operatorName}{$badgeInfo}
Date: {$inspectionDate}{$shiftInfo}

ALERT: This equipment was NOT LOCATED in its designated
compartment during the daily apparatus checkout procedure.
The item has been flagged as missing from this apparatus.

ACTION TAKEN:
- Equipment status changed to MISSING
- Logistics notified for investigation and replacement

Inspection Ref: {$inspectionRef}
EOT;

        // 1. Audit with missing note
        $snipeIt->auditAsset($assetTag, trim($note));

        // 2. Change status to the configured Snipe-IT Missing label.
        $snipeIt->updateAssetStatus($assetId, (int) config('snipeit.status_ids.missing'));

        // 3. Send notification
        $this->notifyAdmin('missing', $assetName, $assetTag, $apparatusName, $operatorName, '', $inspectionRef);
    }

    protected function notifyAdmin(
        string $type, string $assetName, string $assetTag,
        string $apparatusName, string $operatorName, string $notes,
        string $inspectionRef
    ): void {
        try {
            $adminEmail = config('snipeit.admin_email', 'PeterDarley@miamibeachfl.gov');
            Notification::route('mail', $adminEmail)
                ->notify(new EquipmentDefectNotification(
                    $type, $assetName, $assetTag, $apparatusName, $operatorName, $notes, $inspectionRef
                ));
        } catch (\Throwable $e) {
            Log::warning('[SnipeItAudit] Failed to send notification', ['error' => $e->getMessage()]);
        }
    }
}
