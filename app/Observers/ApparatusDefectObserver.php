<?php

namespace App\Observers;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Models\AdminAlertEvent;
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
            
            // Generate equipment recommendation (only for Missing/Damaged status)
            if (in_array($defect->status, ['Missing', 'Damaged'])) {
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
     * Create alert for new defect
     */
    protected function createDefectAlert(ApparatusDefect $defect): void
    {
        $severity = $defect->status === 'Missing' ? 'warning' : 'info';
        
        AdminAlertEvent::create([
            'type' => 'defect_created',
            'severity' => $severity,
            'message' => "Defect reported: {$defect->item} ({$defect->status}) on {$defect->apparatus->unit_id} - {$defect->compartment}",
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
