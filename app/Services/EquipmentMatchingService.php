<?php

namespace App\Services;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Models\EquipmentItem;
use App\Models\AdminAlertEvent;
use Illuminate\Support\Facades\DB;

class EquipmentMatchingService
{
    /**
     * Generate equipment recommendations for a defect
     */
    public function generateRecommendationForDefect(ApparatusDefect $defect): ?ApparatusDefectRecommendation
    {
        // Skip if defect is already resolved
        if ($defect->resolved) {
            return null;
        }
        
        // Skip if recommendation already exists
        $existing = ApparatusDefectRecommendation::where('apparatus_defect_id', $defect->id)
            ->where('status', '!=', 'dismissed')
            ->first();
            
        if ($existing) {
            return $existing;
        }
        
        // Try to match defect item to equipment
        $matchResult = $this->findBestMatch($defect->item);
        
        if (!$matchResult) {
            // No match found - create recommendation without equipment_item_id
            return $this->createRecommendation($defect, null, 'manual', 0.0, 
                'No automatic match found. Manual assignment required.');
        }
        
        // Create recommendation with matched item
        $recommendation = $this->createRecommendation(
            $defect,
            $matchResult['item'],
            $matchResult['method'],
            $matchResult['confidence'],
            $matchResult['reasoning']
        );
        
        // Check if matched item has low stock
        if ($matchResult['item'] && $matchResult['item']->isLowStock()) {
            $this->createLowStockAlert($matchResult['item']);
        }
        
        return $recommendation;
    }
    
    /**
     * Find best matching equipment item for a defect item name
     */
    protected function findBestMatch(string $defectItemName): ?array
    {
        $normalizedDefect = EquipmentItem::normalizeName($defectItemName);
        
        // Strategy 1: Exact normalized match
        $exactMatch = EquipmentItem::where('normalized_name', $normalizedDefect)
            ->where('is_active', true)
            ->first();
            
        if ($exactMatch) {
            return [
                'item' => $exactMatch,
                'method' => 'exact',
                'confidence' => 1.0000,
                'reasoning' => 'Exact match on normalized item name',
            ];
        }
        
        // Strategy 2: PostgreSQL trigram similarity (if pg_trgm extension available)
        if ($this->isTrigramAvailable()) {
            $trigramMatch = $this->trigramMatch($normalizedDefect);
            if ($trigramMatch) {
                return $trigramMatch;
            }
        }
        
        // Strategy 3: PHP fuzzy matching (Levenshtein distance)
        $fuzzyMatch = $this->fuzzyMatch($normalizedDefect);
        if ($fuzzyMatch) {
            return $fuzzyMatch;
        }
        
        return null;
    }
    
    /**
     * Check if PostgreSQL pg_trgm extension is available
     */
    protected function isTrigramAvailable(): bool
    {
        try {
            $result = DB::select("SELECT 'hello' % 'hello' as test");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Match using PostgreSQL trigram similarity
     */
    protected function trigramMatch(string $normalized): ?array
    {
        try {
            $results = DB::select("
                SELECT id, normalized_name, 
                       similarity(normalized_name, ?) as score
                FROM equipment_items
                WHERE is_active = true
                  AND normalized_name % ?
                ORDER BY score DESC
                LIMIT 1
            ", [$normalized, $normalized]);
            
            if (empty($results) || $results[0]->score < 0.5) {
                return null;
            }
            
            $item = EquipmentItem::find($results[0]->id);
            
            return [
                'item' => $item,
                'method' => 'trigram',
                'confidence' => round($results[0]->score, 4),
                'reasoning' => "Trigram similarity match (score: " . round($results[0]->score * 100, 2) . "%)",
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Match using PHP Levenshtein distance
     */
    protected function fuzzyMatch(string $normalized): ?array
    {
        $items = EquipmentItem::where('is_active', true)
            ->get(['id', 'normalized_name', 'name']);
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($items as $item) {
            $distance = levenshtein($normalized, $item->normalized_name);
            $maxLen = max(strlen($normalized), strlen($item->normalized_name));
            
            // Convert distance to similarity (0 to 1)
            $similarity = 1 - ($distance / $maxLen);
            
            if ($similarity > $bestScore && $similarity >= 0.6) {
                $bestScore = $similarity;
                $bestMatch = $item;
            }
        }
        
        if (!$bestMatch) {
            return null;
        }
        
        return [
            'item' => $bestMatch,
            'method' => 'fuzzy',
            'confidence' => round($bestScore, 4),
            'reasoning' => "Fuzzy match using Levenshtein distance (score: " . round($bestScore * 100, 2) . "%)",
        ];
    }
    
    /**
     * Create recommendation record
     */
    protected function createRecommendation(
        ApparatusDefect $defect,
        ?EquipmentItem $item,
        string $method,
        float $confidence,
        string $reasoning
    ): ApparatusDefectRecommendation {
        return ApparatusDefectRecommendation::create([
            'apparatus_defect_id' => $defect->id,
            'equipment_item_id' => $item?->id,
            'match_method' => $method,
            'match_confidence' => $confidence,
            'recommended_qty' => 1, // Default to 1, can be adjusted manually
            'reasoning' => $reasoning,
            'status' => 'pending',
        ]);
    }
    
    /**
     * Create low stock alert
     */
    protected function createLowStockAlert(EquipmentItem $item): void
    {
        // Check if alert already exists for this item in last 24 hours
        $recentAlert = AdminAlertEvent::where('type', 'low_stock')
            ->where('related_type', 'equipment_item')
            ->where('related_id', $item->id)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
            
        if ($recentAlert) {
            return; // Don't spam alerts
        }
        
        AdminAlertEvent::create([
            'type' => 'low_stock',
            'severity' => $item->stock == 0 ? 'critical' : 'warning',
            'message' => $item->stock == 0
                ? "Critical: {$item->name} is OUT OF STOCK (needed for recent defect)"
                : "Warning: Low stock for {$item->name} (current: {$item->stock}, min: {$item->reorder_min})",
            'related_type' => 'equipment_item',
            'related_id' => $item->id,
        ]);
    }
}
