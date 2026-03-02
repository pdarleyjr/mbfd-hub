<?php

namespace App\Support\Workgroups;

/**
 * Universal Workgroup Evaluation Rubric
 * 
 * Implements the SAVER (Systematic Assessment of Vehicle Extrication Rescue) methodology
 * for evaluating fire apparatus equipment. This rubric provides a standardized evaluation
 * framework across all product categories with adaptive profile packs for different tool types.
 * 
 * SAVER Category Weights:
 * - Capability: 30%
 * - Usability: 30%
 * - Affordability: 20%
 * - Maintainability: 15%
 * - Deployability: 5%
 * 
 * Rating Scale:
 * - 5: Outstanding / best-in-class / fully supports purchase
 * - 4: Strong / above expectations
 * - 3: Acceptable / serviceable
 * - 2: Below expectations / significant concerns
 * - 1: Unacceptable / should not move forward
 * - N/A: Not applicable
 */
class UniversalEvaluationRubric
{
    /**
     * Current rubric version
     */
    const VERSION = 'v1_universal_apparatus';

    /**
     * Rating constants
     */
    const RATING_OUTSTANDING = 5;
    const RATING_STRONG = 4;
    const RATING_ACCEPTABLE = 3;
    const RATING_BELOW_EXPECTATIONS = 2;
    const RATING_UNACCEPTABLE = 1;
    const RATING_NA = 'n/a';

    /**
     * Assessment profile types
     */
    const PROFILE_GENERIC = 'generic_apparatus';
    const PROFILE_POWERED_TOOL = 'powered_tool';
    const PROFILE_HAND_TOOL = 'hand_tool_forcible';
    const PROFILE_STABILIZATION = 'stabilization_support';
    const PROFILE_WATER_FLOW = 'water_flow_appliance';

    /**
     * SAVER category weights
     */
    const WEIGHT_CAPABILITY = 0.30;
    const WEIGHT_USABILITY = 0.30;
    const WEIGHT_AFFORDABILITY = 0.20;
    const WEIGHT_MAINTAINABILITY = 0.15;
    const WEIGHT_DEPLOYABILITY = 0.05;

    /**
     * Source mode types
     */
    const SOURCE_OPERATIONAL = 'operational';
    const SOURCE_SPECIFICATION = 'specification';
    const SOURCE_BOTH = 'both';

    /**
     * Recommendation options
     */
    const RECOMMEND_YES = 'yes';
    const RECOMMEND_MAYBE = 'maybe';
    const RECOMMEND_NO = 'no';

    /**
     * Confidence levels
     */
    const CONFIDENCE_LOW = 'low';
    const CONFIDENCE_MEDIUM = 'medium';
    const CONFIDENCE_HIGH = 'high';

    /**
     * Get all assessment profiles
     */
    public static function getAssessmentProfiles(): array
    {
        return [
            self::PROFILE_GENERIC => 'Generic Apparatus',
            self::PROFILE_POWERED_TOOL => 'Powered Tool',
            self::PROFILE_HAND_TOOL => 'Hand Tool / Forcible Entry',
            self::PROFILE_STABILIZATION => 'Stabilization / Support',
            self::PROFILE_WATER_FLOW => 'Water / Flow Appliance',
        ];
    }

    /**
     * Get profile by category name (heuristic matching)
     */
    public static function getProfileForCategory(string $categoryName): string
    {
        $name = strtolower($categoryName);
        
        if (str_contains($name, 'battery') || str_contains($name, 'extrication') || str_contains($name, 'rescue tool')) {
            return self::PROFILE_POWERED_TOOL;
        }
        
        if (str_contains($name, 'forcible') || str_contains($name, 'entry') || str_contains($name, 'breaching')) {
            return self::PROFILE_HAND_TOOL;
        }
        
        if (str_contains($name, 'stabiliz') || str_contains($name, 'support') || str_contains($name, 'chock')) {
            return self::PROFILE_STABILIZATION;
        }
        
        if (str_contains($name, 'water') || str_contains($name, 'flow') || str_contains($name, 'nozzle') || str_contains($name, 'appliance')) {
            return self::PROFILE_WATER_FLOW;
        }

        return self::PROFILE_GENERIC;
    }

    /**
     * Get rubric version
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Get SAVER category weights
     */
    public static function getCategoryWeights(): array
    {
        return [
            'capability' => self::WEIGHT_CAPABILITY,
            'usability' => self::WEIGHT_USABILITY,
            'affordability' => self::WEIGHT_AFFORDABILITY,
            'maintainability' => self::WEIGHT_MAINTAINABILITY,
            'deployability' => self::WEIGHT_DEPLOYABILITY,
        ];
    }

    /**
     * Get rating options for forms
     */
    public static function getRatingOptions(): array
    {
        return [
            '' => 'Select a rating...',
            5 => '5 - Outstanding',
            4 => '4 - Strong',
            3 => '3 - Acceptable',
            2 => '2 - Below Expectations',
            1 => '1 - Unacceptable',
            'n/a' => 'N/A - Not Applicable',
        ];
    }

    /**
     * Get recommendation options
     */
    public static function getRecommendationOptions(): array
    {
        return [
            '' => 'Select recommendation...',
            self::RECOMMEND_YES => 'Yes - Advance to finalist consideration',
            self::RECOMMEND_MAYBE => 'Maybe - Needs further review',
            self::RECOMMEND_NO => 'No - Do not move forward',
        ];
    }

    /**
     * Get confidence level options
     */
    public static function getConfidenceOptions(): array
    {
        return [
            '' => 'Select confidence level...',
            self::CONFIDENCE_HIGH => 'High - Confident in this evaluation',
            self::CONFIDENCE_MEDIUM => 'Medium - Reasonably confident',
            self::CONFIDENCE_LOW => 'Low - Need more information or experience',
        ];
    }

    /**
     * Get all core criteria (always present regardless of profile)
     */
    public static function getCoreCriteria(): array
    {
        return [
            // CAPABILITY (8 criteria)
            'capability' => [
                'id' => 'cap_operational_effectiveness',
                'name' => 'Operational Effectiveness',
                'description' => 'How well does this tool perform its primary function in real rescue scenarios?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_safety_risk_control',
                'name' => 'Safety / Risk Control',
                'description' => 'Does this tool enhance responder safety or reduce risks during operations?',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_durability_build_quality',
                'name' => 'Durability / Build Quality',
                'description' => 'Is the construction robust enough for repeated field use?',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_standards_compliance',
                'name' => 'Standards / Compliance / Verified Performance',
                'description' => 'Does it meet applicable NFPA, ANSI, or other standards?',
                'weight' => 5,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_interoperability',
                'name' => 'Interoperability / Compatibility',
                'description' => 'Does it work with existing apparatus equipment and standard accessories?',
                'weight' => 4,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_versatility',
                'name' => 'Versatility / Range of Use',
                'description' => 'Can this tool handle multiple scenarios or is it single-purpose?',
                'weight' => 4,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_environmental_suitability',
                'name' => 'Environmental Suitability',
                'description' => 'Is it rated for extreme temperatures, weather, or hazardous environments?',
                'weight' => 3,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'capability',
            ],
            [
                'id' => 'cap_accessory_support',
                'name' => 'Accessory / Expansion Support',
                'description' => 'Are there compatible accessories or attachments available?',
                'weight' => 2,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'capability',
            ],

            // USABILITY (6 criteria)
            'usability' => [
                'id' => 'use_ergonomics_balance',
                'name' => 'Ergonomics / Balance',
                'description' => 'Is it well-balanced and comfortable to handle during use?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],
            [
                'id' => 'use_ease_of_use',
                'name' => 'Ease of Use / Intuitiveness',
                'description' => 'Can operators quickly learn and effectively use this tool with minimal training?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],
            [
                'id' => 'use_ppe_gloves',
                'name' => 'Use with PPE / Gloves',
                'description' => 'Can it be effectively operated while wearing turnout gloves and full PPE?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],
            [
                'id' => 'use_portability',
                'name' => 'Portability / Carry Burden / Scene Mobility',
                'description' => 'How easy is it to transport and position at the scene?',
                'weight' => 4,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'usability',
            ],
            [
                'id' => 'use_tight_space',
                'name' => 'Tight-Space / Awkward-Access Handling',
                'description' => 'Can it operate effectively in confined spaces typical of vehicle rescue?',
                'weight' => 4,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],
            [
                'id' => 'use_control_feedback',
                'name' => 'Control Clarity / Visual-Tactile Feedback',
                'description' => 'Does the operator have clear feedback on tool performance?',
                'weight' => 2,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],

            // AFFORDABILITY (4 criteria)
            'affordability' => [
                'id' => 'aff_lifecycle_cost',
                'name' => 'Lifecycle / Consumable Cost',
                'description' => 'What are the ongoing operational costs over expected lifespan?',
                'weight' => 5,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'affordability',
            ],
            [
                'id' => 'aff_value_capability',
                'name' => 'Value vs Capability',
                'description' => 'Does the price justify the features and performance offered?',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'affordability',
            ],
            [
                'id' => 'aff_acquisition_cost',
                'name' => 'Acquisition Cost',
                'description' => 'Initial purchase price including any required accessories.',
                'weight' => 4,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'affordability',
            ],
            [
                'id' => 'aff_commonality_savings',
                'name' => 'Commonality / Compatibility Savings',
                'description' => 'Can it share batteries, chargers, or accessories with existing fleet?',
                'weight' => 3,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'affordability',
            ],

            // MAINTAINABILITY (5 criteria)
            'maintainability' => [
                'id' => 'maint_training_burden',
                'name' => 'Training Burden / Documentation Quality',
                'description' => 'Is training straightforward? Are manuals and guides clear?',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'maintainability',
            ],
            [
                'id' => 'maint_vendor_support',
                'name' => 'Vendor / Service Support',
                'description' => 'Is there reliable local vendor support for repairs and parts?',
                'weight' => 4,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'maintainability',
            ],
            [
                'id' => 'maint_in_house',
                'name' => 'In-House Maintenance / Inspectability',
                'description' => 'Can routine inspection and basic maintenance be done in-house?',
                'weight' => 4,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'maintainability',
            ],
            [
                'id' => 'maint_parts_availability',
                'name' => 'Parts / Consumables / Availability',
                'description' => 'Are replacement parts and consumables readily available?',
                'weight' => 4,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'maintainability',
            ],
            [
                'id' => 'maint_warranty',
                'name' => 'Warranty / Service Plan',
                'description' => 'What warranty coverage is included?',
                'weight' => 3,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'maintainability',
            ],

            // DEPLOYABILITY (3 criteria)
            'deployability' => [
                'id' => 'dep_ready_time',
                'name' => 'Ready-to-Use / Startup Time',
                'description' => 'How quickly can it be deployed from storage to operation?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'deployability',
            ],
            [
                'id' => 'dep_storage_footprint',
                'name' => 'Storage / Mounting Footprint',
                'description' => 'What space requirements for apparatus mounting or station storage?',
                'weight' => 3,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'deployability',
            ],
            [
                'id' => 'dep_logistics',
                'name' => 'Apparatus / Scene Deployment Logistics',
                'description' => 'How does it affect apparatus loading and scene setup?',
                'weight' => 3,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'deployability',
            ],
        ];
    }

    /**
     * Get adaptive profile pack criteria
     */
    public static function getProfileCriteria(string $profile): array
    {
        $packs = [
            self::PROFILE_POWERED_TOOL => [
                // Powered Tool pack (6 criteria)
                [
                    'id' => 'pwr_source_performance',
                    'name' => 'Power Source Performance / Charge Behavior',
                    'description' => 'How does it perform under load? Is there voltage sag or power drop?',
                    'weight' => 5,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'pwr_runtime_endurance',
                    'name' => 'Runtime / Endurance Under Load',
                    'description' => 'How long does it operate under realistic rescue conditions?',
                    'weight' => 5,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'pwr_battery_commonality',
                    'name' => 'Battery / Power Commonality & Swap Speed',
                    'description' => 'Are batteries interchangeable with existing fleet tools?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'affordability',
                ],
                [
                    'id' => 'pwr_status_indicators',
                    'name' => 'Status Indicators / Diagnostics',
                    'description' => 'Are charge level, fault codes, and diagnostics clear?',
                    'weight' => 2,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'usability',
                ],
                [
                    'id' => 'pwr_fail_safe',
                    'name' => 'Fail-Safe / Jam Recovery / Emergency Release',
                    'description' => 'Can it be quickly safed or recovered if stuck?',
                    'weight' => 2,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'pwr_integrated_lighting',
                    'name' => 'Integrated Lighting',
                    'description' => 'Does it have built-in work lights for low-light operations?',
                    'weight' => 1,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'usability',
                ],
            ],

            self::PROFILE_HAND_TOOL => [
                // Hand Tool / Forcible Entry pack (4 criteria)
                [
                    'id' => 'hnd_breaching_effectiveness',
                    'name' => 'Breaching / Prying / Striking Effectiveness',
                    'description' => 'How effective is it at primary rescue tasks?',
                    'weight' => 5,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'hnd_mechanical_simplicity',
                    'name' => 'Mechanical Simplicity / Low Failure Risk',
                    'description' => 'Fewer moving parts means fewer failure points in the field.',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'hnd_edge_retention',
                    'name' => 'Edge / Tip / Wear Retention',
                    'description' => 'How long does it maintain effectiveness before requiring replacement?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'maintainability',
                ],
                [
                    'id' => 'hnd_tight_space_access',
                    'name' => 'Tight-Space Access / Control',
                    'description' => 'Can it be effectively controlled in confined rescue scenarios?',
                    'weight' => 4,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'usability',
                ],
            ],

            self::PROFILE_STABILIZATION => [
                // Stabilization / Support pack (4 criteria)
                [
                    'id' => 'stb_holding_strength',
                    'name' => 'Holding Strength / Stability Confidence',
                    'description' => 'Does it provide reliable stabilization you can trust?',
                    'weight' => 5,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'stb_adjustment_range',
                    'name' => 'Adjustment Range / Adaptability',
                    'description' => 'Can it adapt to various vehicle sizes and positions?',
                    'weight' => 4,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'stb_surface_bite',
                    'name' => 'Surface Bite / Anchor Confidence',
                    'description' => 'Does it grip securely without damaging vehicle surfaces?',
                    'weight' => 4,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'stb_secondary_safety',
                    'name' => 'Secondary Safety / Load Retention',
                    'description' => 'Are there backup retention features if primary fails?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
            ],

            self::PROFILE_WATER_FLOW => [
                // Water / Flow Appliance pack (5 criteria)
                [
                    'id' => 'wtr_flow_capacity',
                    'name' => 'Flow Capacity / Hydraulic Efficiency',
                    'description' => 'Does it meet required GPM for fire suppression?',
                    'weight' => 5,
                    'source' => self::SOURCE_SPECIFICATION,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'wtr_coupling_compat',
                    'name' => 'Coupling / Interface Compatibility',
                    'description' => 'Does it connect to standard hose threads and apparatus?',
                    'weight' => 5,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'deployability',
                ],
                [
                    'id' => 'wtr_control_stability',
                    'name' => 'Control / Reaction / Stability',
                    'description' => 'Is it controllable during operation?',
                    'weight' => 4,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'usability',
                ],
                [
                    'id' => 'wtr_friction_loss',
                    'name' => 'Restriction / Friction-Loss Profile',
                    'description' => 'What is the friction loss through the device?',
                    'weight' => 4,
                    'source' => self::SOURCE_SPECIFICATION,
                    'bucket' => 'capability',
                ],
                [
                    'id' => 'wtr_maintenance_simplicity',
                    'name' => 'Flush / Drain / Maintenance Simplicity',
                    'description' => 'How easy is post-use maintenance and winterization?',
                    'weight' => 2,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'maintainability',
                ],
            ],
        ];

        return $packs[$profile] ?? [];
    }

    /**
     * Get all criteria for a given profile (core + adaptive)
     */
    public static function getAllCriteriaForProfile(string $profile): array
    {
        $core = self::getCoreCriteria();
        $adaptive = self::getProfileCriteria($profile);
        
        // Merge, with adaptive criteria potentially overriding core
        return array_merge($core, $adaptive);
    }

    /**
     * Get criteria grouped by SAVER bucket
     */
    public static function getCriteriaByBucket(string $profile): array
    {
        $criteria = self::getAllCriteriaForProfile($profile);
        
        $buckets = [
            'capability' => [],
            'usability' => [],
            'affordability' => [],
            'maintainability' => [],
            'deployability' => [],
        ];

        foreach ($criteria as $id => $criterion) {
            $bucket = $criterion['bucket'];
            if (isset($buckets[$bucket])) {
                $buckets[$bucket][$id] = $criterion;
            }
        }

        return $buckets;
    }

    /**
     * Calculate SAVER category score
     * 
     * Formula: category_score = sum(rating * criterion_weight) / sum(5 * criterion_weight for applicable criteria) * 100
     */
    public static function calculateCategoryScore(array $criterionScores, string $bucket): float
    {
        $criteria = self::getCoreCriteria();
        
        // Get criteria for this bucket
        $bucketCriteria = array_filter($criteria, fn($c) => $c['bucket'] === $bucket);
        
        $weightedSum = 0;
        $maxWeight = 0;

        foreach ($bucketCriteria as $id => $criterion) {
            $rating = $criterionScores[$id] ?? null;
            
            // Skip N/A or null ratings
            if ($rating === null || $rating === 'n/a') {
                continue;
            }
            
            $weight = $criterion['weight'];
            $weightedSum += $rating * $weight;
            $maxWeight += 5 * $weight;
        }

        if ($maxWeight === 0) {
            return 0;
        }

        return round(($weightedSum / $maxWeight) * 100, 2);
    }

    /**
     * Calculate overall weighted score from category scores
     */
    public static function calculateOverallScore(array $categoryScores): float
    {
        $weights = self::getCategoryWeights();
        
        $overall = 
            ($categoryScores['capability'] ?? 0) * $weights['capability'] +
            ($categoryScores['usability'] ?? 0) * $weights['usability'] +
            ($categoryScores['affordability'] ?? 0) * $weights['affordability'] +
            ($categoryScores['maintainability'] ?? 0) * $weights['maintainability'] +
            ($categoryScores['deployability'] ?? 0) * $weights['deployability'];

        return round($overall, 2);
    }

    /**
     * Calculate all category scores and overall from criterion ratings
     */
    public static function calculateAllScores(array $criterionScores): array
    {
        $buckets = ['capability', 'usability', 'affordability', 'maintainability', 'deployability'];
        
        $categoryScores = [];
        foreach ($buckets as $bucket) {
            $categoryScores[$bucket] = self::calculateCategoryScore($criterionScores, $bucket);
        }

        $overallScore = self::calculateOverallScore($categoryScores);

        return [
            'overall_score' => $overallScore,
            'capability_score' => $categoryScores['capability'],
            'usability_score' => $categoryScores['usability'],
            'affordability_score' => $categoryScores['affordability'],
            'maintainability_score' => $categoryScores['maintainability'],
            'deployability_score' => $categoryScores['deployability'],
        ];
    }

    /**
     * Get evaluator instructions
     */
    public static function getEvaluatorInstructions(): string
    {
        return <<<INSTRUCTIONS
## Evaluator Briefing

Before you begin this evaluation, please:

1. **Review the product** - Understand what the tool is designed for and its intended use on the ladder truck
2. **Hands-on evaluation preferred** - Whenever possible, physically handle and operate the equipment
3. **Use with PPE** - If evaluating extrication or forcible entry tools, evaluate while wearing turnout gloves
4. **Reference specifications appropriately** - Clearly mark which scores are based on vendor data vs. hands-on use
5. **Note deal-breakers** - Document any safety concerns, mounting/storage issues, or training burden that would prevent adoption
6. **Be evidence-based** - Keep comments relevant to procurement decisions

### Rating Guidelines

- **5 - Outstanding**: Best-in-class, strongly recommend for purchase
- **4 - Strong**: Exceeds expectations, would recommend
- **3 - Acceptable**: Meets minimum requirements, serviceable
- **2 - Below Expectations**: Significant concerns, needs improvement
- **1 - Unacceptable**: Should not move forward
- **N/A**: Not applicable to this product type

INSTRUCTIONS;
    }

    /**
     * Get source badge color
     */
    public static function getSourceBadgeColor(string $source): string
    {
        return match($source) {
            self::SOURCE_OPERATIONAL => 'success',
            self::SOURCE_SPECIFICATION => 'info',
            self::SOURCE_BOTH => 'warning',
            default => 'gray',
        };
    }

    /**
     * Get source label
     */
    public static function getSourceLabel(string $source): string
    {
        return match($source) {
            self::SOURCE_OPERATIONAL => 'Operational',
            self::SOURCE_SPECIFICATION => 'Specification',
            self::SOURCE_BOTH => 'Operational + Spec',
            default => $source,
        };
    }
}
