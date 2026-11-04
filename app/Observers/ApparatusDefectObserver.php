<?php

namespace App\Observers;

use App\Models\AdminAlertEvent;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Services\EquipmentMatchingService;
use Illuminate\Support\Facades\Log;

class ApparatusDefectObserver
{
    protected EquipmentMatchingService $matchingService;

    public function __construct(EquipmentMatchingService $matchingService)
    {
        $this->matchingService = $matchingService;
    }

    /**
     * Handle the ApparatusDefect "created" event
     */
    public function created(ApparatusDefect $defect): void
    {
        try {
            // Create admin alert for new defect
            $this->createDefectAlert($defect);

            // Generate equipment recommendations for missing or damaged findings.
            if (in_array($defect->issue_type, ['missing', 'damaged'], true)) {
                $recommendation = $this->matchingService->generateRecommendationForDefect($defect);

                if ($recommendation && $recommendation->equipment_item_id) {
                    $this->createRecommendationAlert($recommendation);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't break defect creation
            Log::error('Failed to generate equipment recommendation', [
                'defect_id' => $defect->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * A staff or member flag (severity, operational impact) changed: refresh the Low/Medium/High triage level the
     * flag feeds into. Staff overrides are kept; nothing is sent; a problem here never fails the save.
     */
    public function updated(ApparatusDefect $defect): void
    {
        if (! $defect->wasChanged(['severity', 'operational_impact'])) {
            return;
        }
        $this->refreshTriage($defect);
    }

    private function refreshTriage(ApparatusDefect $defect): void
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(static fn () => app(\App\Services\SupportTriage\TriageProjector::class)->refreshDefect($defect));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Create alert for new defect
     */
    protected function createDefectAlert(ApparatusDefect $defect): void
    {
        $severity = $defect->issue_type === 'missing' ? 'warning' : 'info';

        AdminAlertEvent::create([
            'type' => 'defect_created',
            'severity' => $severity,
            'message' => "Defect reported: {$defect->item} ({$defect->issue_type}) on {$defect->apparatus->unit_id} - {$defect->compartment}",
            'related_type' => 'apparatus_defect',
            'related_id' => $defect->id,
        ]);
    }

    /**
     * Create alert for new recommendation
     */
    protected function createRecommendationAlert(ApparatusDefectRecommendation $recommendation): void
    {
        AdminAlertEvent::create([
            'type' => 'recommendation_created',
            'severity' => 'info',
            'message' => "Replacement recommendation: {$recommendation->equipmentItem->name} for {$recommendation->defect->item} on {$recommendation->defect->apparatus->unit_id}",
            'related_type' => 'apparatus_defect_recommendation',
            'related_id' => $recommendation->id,
        ]);
    }
}
