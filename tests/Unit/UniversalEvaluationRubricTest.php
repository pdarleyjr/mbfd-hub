<?php

namespace Tests\Unit;

use App\Support\Workgroups\UniversalEvaluationRubric;
use Tests\TestCase;

class UniversalEvaluationRubricTest extends TestCase
{
    /**
     * Test that rubric version is set correctly.
     */
    public function test_rubric_has_version(): void
    {
        $this->assertEquals('v1_universal_apparatus', UniversalEvaluationRubric::getVersion());
    }

    /**
     * Test category weights sum to 100%.
     */
    public function test_category_weights_sum_to_hundred(): void
    {
        $weights = UniversalEvaluationRubric::getCategoryWeights();
        
        $total = $weights['capability'] + $weights['usability'] + 
                 $weights['affordability'] + $weights['maintainability'] + 
                 $weights['deployability'];
        
        $this->assertEquals(1.0, $total, 'Category weights should sum to 1.0 (100%)');
    }

    /**
     * Test correct weights for each SAVER category.
     */
    public function test_category_weights_are_correct(): void
    {
        $weights = UniversalEvaluationRubric::getCategoryWeights();
        
        $this->assertEquals(0.30, $weights['capability']);
        $this->assertEquals(0.30, $weights['usability']);
        $this->assertEquals(0.20, $weights['affordability']);
        $this->assertEquals(0.15, $weights['maintainability']);
        $this->assertEquals(0.05, $weights['deployability']);
    }

    /**
     * Test rating options are complete.
     */
    public function test_rating_options_complete(): void
    {
        $options = UniversalEvaluationRubric::getRatingOptions();
        
        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey(5, $options);
        $this->assertArrayHasKey(4, $options);
        $this->assertArrayHasKey(3, $options);
        $this->assertArrayHasKey(2, $options);
        $this->assertArrayHasKey(1, $options);
        $this->assertArrayHasKey('n/a', $options);
    }

    /**
     * Test recommendation options are complete.
     */
    public function test_recommendation_options_complete(): void
    {
        $options = UniversalEvaluationRubric::getRecommendationOptions();
        
        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey('yes', $options);
        $this->assertArrayHasKey('maybe', $options);
        $this->assertArrayHasKey('no', $options);
    }

    /**
     * Test confidence options are complete.
     */
    public function test_confidence_options_complete(): void
    {
        $options = UniversalEvaluationRubric::getConfidenceOptions();
        
        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey('high', $options);
        $this->assertArrayHasKey('medium', $options);
        $this->assertArrayHasKey('low', $options);
    }

    /**
     * Test all assessment profiles are defined.
     */
    public function test_assessment_profiles_defined(): void
    {
        $profiles = UniversalEvaluationRubric::getAssessmentProfiles();
        
        $this->assertArrayHasKey('generic_apparatus', $profiles);
        $this->assertArrayHasKey('powered_tool', $profiles);
        $this->assertArrayHasKey('hand_tool_forcible', $profiles);
        $this->assertArrayHasKey('stabilization_support', $profiles);
        $this->assertArrayHasKey('water_flow_appliance', $profiles);
    }

    /**
     * Test core criteria are present for all buckets.
     */
    public function test_core_criteria_cover_all_buckets(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();
        
        $buckets = ['capability', 'usability', 'affordability', 'maintainability', 'deployability'];
        
        foreach ($buckets as $bucket) {
            $bucketCriteria = array_filter($criteria, fn($c) => $c['bucket'] === $bucket);
            $this->assertNotEmpty($bucketCriteria, "Bucket {$bucket} should have criteria");
        }
    }

    /**
     * Test category score calculation with all perfect scores.
     */
    public function test_category_score_perfect_scores(): void
    {
        // All 5s for capability criteria
        $ratings = [
            'cap_operational_effectiveness' => 5,
            'cap_safety_risk_control' => 5,
            'cap_durability_build_quality' => 5,
            'cap_standards_compliance' => 5,
            'cap_interoperability' => 5,
            'cap_versatility' => 5,
            'cap_environmental_suitability' => 5,
            'cap_accessory_support' => 5,
        ];
        
        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');
        
        $this->assertEquals(100.0, $score);
    }

    /**
     * Test category score calculation with mixed scores.
     */
    public function test_category_score_mixed_scores(): void
    {
        $ratings = [
            'cap_operational_effectiveness' => 4,
            'cap_safety_risk_control' => 5,
            'cap_durability_build_quality' => 3,
            'cap_standards_compliance' => 4,
            'cap_interoperability' => 5,
            'cap_versatility' => 3,
            'cap_environmental_suitability' => 4,
            'cap_accessory_support' => 5,
        ];
        
        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');
        
        // Calculate expected: sum(4*5 + 5*5 + 3*5 + 4*4 + 5*4 + 3*4 + 4*3 + 5*2) / (5*33) * 100
        // Weighted sum: 20 + 25 + 15 + 16 + 20 + 12 + 12 + 10 = 130
        // Max weight: 5*5 + 5*5 + 5*5 + 4*5 + 4*5 + 4*4 + 3*4 + 2*5 = 25+25+25+20+20+16+12+10 = 153
        // Expected: 130/153 * 100 ≈ 84.97
        $this->assertEquals(84.97, $score, 0.1);
    }

    /**
     * Test N/A ratings are excluded from calculation.
     */
    public function test_na_ratings_excluded(): void
    {
        $ratings = [
            'cap_operational_effectiveness' => 5,
            'cap_safety_risk_control' => 5,
            'cap_durability_build_quality' => 'n/a',  // N/A should be excluded
            'cap_standards_compliance' => 4,
        ];
        
        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');
        
        // Should only calculate based on non-N/A items
        // Weighted sum: 5*5 + 5*5 + 4*5 = 25 + 25 + 20 = 70
        // Max weight: 5*5 + 5*5 + 4*5 = 25 + 25 + 20 = 70
        // Expected: 70/70 * 100 = 100
        $this->assertEquals(100.0, $score);
    }

    /**
     * Test overall score calculation.
     */
    public function test_overall_score_calculation(): void
    {
        $categoryScores = [
            'capability' => 80.0,
            'usability' => 90.0,
            'affordability' => 70.0,
            'maintainability' => 85.0,
            'deployability' => 95.0,
        ];
        
        $overall = UniversalEvaluationRubric::calculateOverallScore($categoryScores);
        
        // 80*0.30 + 90*0.30 + 70*0.20 + 85*0.15 + 95*0.05
        // = 24 + 27 + 14 + 12.75 + 4.75 = 82.5
        $this->assertEquals(82.5, $overall);
    }

    /**
     * Test full score calculation from criterion ratings.
     */
    public function test_full_score_calculation(): void
    {
        // All 5s for all criteria
        $ratings = [
            // Capability
            'cap_operational_effectiveness' => 5,
            'cap_safety_risk_control' => 5,
            'cap_durability_build_quality' => 5,
            'cap_standards_compliance' => 5,
            'cap_interoperability' => 5,
            'cap_versatility' => 5,
            'cap_environmental_suitability' => 5,
            'cap_accessory_support' => 5,
            // Usability
            'use_ergonomics_balance' => 5,
            'use_ease_of_use' => 5,
            'use_ppe_gloves' => 5,
            'use_portability' => 5,
            'use_tight_space' => 5,
            'use_control_feedback' => 5,
            // Affordability
            'aff_lifecycle_cost' => 5,
            'aff_value_capability' => 5,
            'aff_acquisition_cost' => 5,
            'aff_commonality_savings' => 5,
            // Maintainability
            'maint_training_burden' => 5,
            'maint_vendor_support' => 5,
            'maint_in_house' => 5,
            'maint_parts_availability' => 5,
            'maint_warranty' => 5,
            // Deployability
            'dep_ready_time' => 5,
            'dep_storage_footprint' => 5,
            'dep_logistics' => 5,
        ];
        
        $scores = UniversalEvaluationRubric::calculateAllScores($ratings);
        
        $this->assertEquals(100.0, $scores['overall_score']);
        $this->assertEquals(100.0, $scores['capability_score']);
        $this->assertEquals(100.0, $scores['usability_score']);
        $this->assertEquals(100.0, $scores['affordability_score']);
        $this->assertEquals(100.0, $scores['maintainability_score']);
        $this->assertEquals(100.0, $scores['deployability_score']);
    }

    /**
     * Test powered tool profile criteria are included.
     */
    public function test_powered_tool_profile_includes_adaptive_criteria(): void
    {
        $criteria = UniversalEvaluationRubric::getAllCriteriaForProfile('powered_tool');
        
        // Should include both core and adaptive criteria
        $this->assertArrayHasKey('cap_operational_effectiveness', $criteria);
        $this->assertArrayHasKey('pwr_source_performance', $criteria);
        $this->assertArrayHasKey('pwr_runtime_endurance', $criteria);
    }

    /**
     * Test profile detection from category name.
     */
    public function test_profile_detection_from_category_name(): void
    {
        $this->assertEquals(
            'powered_tool',
            UniversalEvaluationRubric::getProfileForCategory('Battery-Operated Extrication Tools')
        );
        
        $this->assertEquals(
            'hand_tool_forcible',
            UniversalEvaluationRubric::getProfileForCategory('Forcible Entry Tools')
        );
        
        $this->assertEquals(
            'stabilization_support',
            UniversalEvaluationRubric::getProfileForCategory('Vehicle Stabilization')
        );
        
        $this->assertEquals(
            'water_flow_appliance',
            UniversalEvaluationRubric::getProfileForCategory('Water Flow Appliances')
        );
    }

    /**
     * Test source badge colors are correct.
     */
    public function test_source_badge_colors(): void
    {
        $this->assertEquals('success', UniversalEvaluationRubric::getSourceBadgeColor('operational'));
        $this->assertEquals('info', UniversalEvaluationRubric::getSourceBadgeColor('specification'));
        $this->assertEquals('warning', UniversalEvaluationRubric::getSourceBadgeColor('both'));
    }

    /**
     * Test source labels are correct.
     */
    public function test_source_labels(): void
    {
        $this->assertEquals('Operational', UniversalEvaluationRubric::getSourceLabel('operational'));
        $this->assertEquals('Specification', UniversalEvaluationRubric::getSourceLabel('specification'));
        $this->assertEquals('Operational + Spec', UniversalEvaluationRubric::getSourceLabel('both'));
    }

    /**
     * Test evaluator instructions are not empty.
     */
    public function test_evaluator_instructions_not_empty(): void
    {
        $instructions = UniversalEvaluationRubric::getEvaluatorInstructions();
        
        $this->assertNotEmpty($instructions);
        $this->assertStringContainsString('Evaluator Briefing', $instructions);
        $this->assertStringContainsString('Rating Guidelines', $instructions);
    }
}
