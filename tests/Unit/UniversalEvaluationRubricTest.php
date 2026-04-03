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
     * Test core criteria cover all five SAVER categories.
     */
    public function test_criteria_covers_all_categories(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();

        $buckets = array_unique(array_column($criteria, 'bucket'));
        sort($buckets);

        $expected = ['affordability', 'capability', 'deployability', 'maintainability', 'usability'];
        $this->assertEquals($expected, $buckets);
    }

    /**
     * Test each criterion has required keys.
     */
    public function test_criteria_have_required_keys(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();

        foreach ($criteria as $id => $criterion) {
            $this->assertArrayHasKey('id', $criterion, "Criterion $id missing 'id'");
            $this->assertArrayHasKey('name', $criterion, "Criterion $id missing 'name'");
            $this->assertArrayHasKey('description', $criterion, "Criterion $id missing 'description'");
            $this->assertArrayHasKey('weight', $criterion, "Criterion $id missing 'weight'");
            $this->assertArrayHasKey('bucket', $criterion, "Criterion $id missing 'bucket'");
            $this->assertEquals($id, $criterion['id']);
        }
    }

    /**
     * Test category score with perfect scores (all 5s).
     */
    public function test_category_score_perfect_scores(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();
        $capCriteria = array_filter($criteria, fn($c) => $c['bucket'] === 'capability');

        $ratings = [];
        foreach ($capCriteria as $id => $c) {
            $ratings[$id] = 5;
        }

        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');

        $this->assertEquals(100.0, $score);
    }

    /**
     * Test category score calculation with mixed scores.
     */
    public function test_category_score_mixed_scores(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();
        $capCriteria = array_filter($criteria, fn($c) => $c['bucket'] === 'capability');

        // Assign varying scores
        $ratings = [];
        $scoreValues = [4, 3, 5]; // Rotate through these
        $i = 0;
        $weightedSum = 0;
        $maxWeight = 0;
        foreach ($capCriteria as $id => $c) {
            $rating = $scoreValues[$i % count($scoreValues)];
            $ratings[$id] = $rating;
            $weightedSum += $rating * $c['weight'];
            $maxWeight += 5 * $c['weight'];
            $i++;
        }

        $expected = round(($weightedSum / $maxWeight) * 100, 2);
        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');

        $this->assertEquals($expected, $score);
    }

    /**
     * Test N/A ratings are excluded from calculation.
     */
    public function test_na_ratings_excluded(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();
        $capCriteria = array_filter($criteria, fn($c) => $c['bucket'] === 'capability');
        $capKeys = array_keys($capCriteria);

        // Give all 5s except mark last one as N/A
        $ratings = [];
        foreach ($capKeys as $idx => $id) {
            if ($idx === count($capKeys) - 1) {
                $ratings[$id] = 'n/a';
            } else {
                $ratings[$id] = 5;
            }
        }

        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');

        // All rated items are 5s, so score should be 100
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
     * Test full score calculation from criterion ratings (all 5s = 100%).
     */
    public function test_full_score_calculation(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();

        // Give every criterion a 5
        $ratings = [];
        foreach ($criteria as $id => $c) {
            $ratings[$id] = 5;
        }

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
        $profileCriteria = UniversalEvaluationRubric::getProfileCriteria(
            UniversalEvaluationRubric::PROFILE_POWERED_TOOL
        );

        $this->assertNotEmpty($profileCriteria, 'Powered tool profile should have criteria');

        foreach ($profileCriteria as $id => $criterion) {
            $this->assertArrayHasKey('id', $criterion);
            $this->assertArrayHasKey('name', $criterion);
            $this->assertArrayHasKey('weight', $criterion);
        }
    }

    /**
     * Test zero scores when no matching criteria found.
     */
    public function test_empty_ratings_return_zero(): void
    {
        $score = UniversalEvaluationRubric::calculateCategoryScore([], 'capability');
        $this->assertEquals(0.0, $score);
    }

    /**
     * Test all N/A ratings return zero.
     */
    public function test_all_na_returns_zero(): void
    {
        $criteria = UniversalEvaluationRubric::getCoreCriteria();
        $capCriteria = array_filter($criteria, fn($c) => $c['bucket'] === 'capability');

        $ratings = [];
        foreach ($capCriteria as $id => $c) {
            $ratings[$id] = 'n/a';
        }

        $score = UniversalEvaluationRubric::calculateCategoryScore($ratings, 'capability');
        $this->assertEquals(0.0, $score);
    }
}
