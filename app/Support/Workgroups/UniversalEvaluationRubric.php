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
     * Condensed version - merged similar criteria for a streamlined evaluation experience
     */
    public static function getCoreCriteria(): array
    {
        return [
            // CAPABILITY (5 criteria - condensed from 8)
            'cap_effectiveness' => [
                'id' => 'cap_effectiveness',
                'name' => 'Operational Effectiveness & Safety',
                'description' => 'How well does this tool perform its primary function? Does it enhance responder safety?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'capability',
            ],
            'cap_durability' => [
                'id' => 'cap_durability',
                'name' => 'Durability & Build Quality',
                'description' => 'Is the construction robust enough for repeated field use? Standards compliance?',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'capability',
            ],
            'cap_versatility' => [
                'id' => 'cap_versatility',
                'name' => 'Versatility & Compatibility',
                'description' => 'Can it handle multiple scenarios? Works with existing equipment and accessories?',
                'weight' => 4,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'capability',
            ],

            // USABILITY (4 criteria - condensed from 6)
            'use_ergonomics' => [
                'id' => 'use_ergonomics',
                'name' => 'Ergonomics & Ease of Use',
                'description' => 'Well-balanced, intuitive, and comfortable to handle? Easy to learn?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],
            'use_ppe' => [
                'id' => 'use_ppe',
                'name' => 'PPE / Gloves & Tight-Space Use',
                'description' => 'Can it be operated with turnout gloves and full PPE in confined spaces?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'usability',
            ],
            'use_portability' => [
                'id' => 'use_portability',
                'name' => 'Portability & Scene Mobility',
                'description' => 'How easy is it to transport, position, and control at the scene?',
                'weight' => 4,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'usability',
            ],

            // AFFORDABILITY (3 criteria - condensed from 4)
            'aff_value' => [
                'id' => 'aff_value',
                'name' => 'Overall Value & Cost',
                'description' => 'Does the price justify the features? Including acquisition and lifecycle costs.',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'affordability',
            ],
            'aff_commonality' => [
                'id' => 'aff_commonality',
                'name' => 'Fleet Commonality & Savings',
                'description' => 'Can it share batteries, chargers, or accessories with existing fleet?',
                'weight' => 3,
                'source' => self::SOURCE_SPECIFICATION,
                'bucket' => 'affordability',
            ],

            // MAINTAINABILITY (3 criteria - condensed from 5)
            'maint_support' => [
                'id' => 'maint_support',
                'name' => 'Service Support & Parts Availability',
                'description' => 'Reliable vendor support? Parts readily available? Good warranty?',
                'weight' => 5,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'maintainability',
            ],
            'maint_training' => [
                'id' => 'maint_training',
                'name' => 'Training & In-House Maintenance',
                'description' => 'Easy to train on? Can routine maintenance be done in-house?',
                'weight' => 4,
                'source' => self::SOURCE_BOTH,
                'bucket' => 'maintainability',
            ],

            // DEPLOYABILITY (2 criteria - condensed from 3)
            'dep_readiness' => [
                'id' => 'dep_readiness',
                'name' => 'Ready-to-Use & Startup Time',
                'description' => 'How quickly can it be deployed from storage to operation?',
                'weight' => 5,
                'source' => self::SOURCE_OPERATIONAL,
                'bucket' => 'deployability',
            ],
            'dep_storage' => [
                'id' => 'dep_storage',
                'name' => 'Storage & Apparatus Logistics',
                'description' => 'Space requirements for mounting/storage? Impact on apparatus loading?',
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
                'pwr_performance' => [
                    'id' => 'pwr_performance',
                    'name' => 'Power & Runtime Performance',
                    'description' => 'Power under load, runtime endurance, charge behavior?',
                    'weight' => 5,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
                'pwr_battery' => [
                    'id' => 'pwr_battery',
                    'name' => 'Battery Commonality & Diagnostics',
                    'description' => 'Interchangeable batteries? Status indicators? Swap speed?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'affordability',
                ],
                'pwr_safety' => [
                    'id' => 'pwr_safety',
                    'name' => 'Fail-Safe, Lighting & Recovery',
                    'description' => 'Jam recovery? Emergency release? Integrated lighting?',
                    'weight' => 3,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
            ],

            self::PROFILE_HAND_TOOL => [
                'hnd_effectiveness' => [
                    'id' => 'hnd_effectiveness',
                    'name' => 'Breaching & Striking Effectiveness',
                    'description' => 'How effective at primary forcible entry tasks?',
                    'weight' => 5,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'capability',
                ],
                'hnd_durability' => [
                    'id' => 'hnd_durability',
                    'name' => 'Mechanical Simplicity & Wear Retention',
                    'description' => 'Few failure points? Maintains edge/tip effectiveness?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'maintainability',
                ],
            ],

            self::PROFILE_STABILIZATION => [
                'stb_strength' => [
                    'id' => 'stb_strength',
                    'name' => 'Holding Strength & Adaptability',
                    'description' => 'Reliable stabilization? Adapts to various vehicles?',
                    'weight' => 5,
                    'source' => self::SOURCE_OPERATIONAL,
                    'bucket' => 'capability',
                ],
                'stb_safety' => [
                    'id' => 'stb_safety',
                    'name' => 'Surface Grip & Secondary Safety',
                    'description' => 'Secure grip? Backup retention features?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
            ],

            self::PROFILE_WATER_FLOW => [
                'wtr_flow' => [
                    'id' => 'wtr_flow',
                    'name' => 'Flow Capacity & Compatibility',
                    'description' => 'Meets GPM requirements? Standard coupling compatibility?',
                    'weight' => 5,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'capability',
                ],
                'wtr_control' => [
                    'id' => 'wtr_control',
                    'name' => 'Control & Maintenance',
                    'description' => 'Controllable during operation? Easy post-use maintenance?',
                    'weight' => 4,
                    'source' => self::SOURCE_BOTH,
                    'bucket' => 'usability',
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
