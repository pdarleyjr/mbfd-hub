<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiModelCall;
use App\Services\AiAutomation\ItemNamer;
use App\Services\AiAutomation\Model\BenchmarkScoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The model benchmark: sample file integrity, safe-by-default dry run, metered real run (faked), scoring. */
final class BenchmarkAiModelsTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLES = __DIR__.'/../../../resources/ai/benchmark/support-services-samples.json';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai_automation.model.enabled' => true,
            'ai_automation.model.account_id' => str_repeat('a1', 16),
            'ai_automation.model.api_token' => 'test-token',
            'ai_automation.model.daily_call_limit' => 500,
            'ai_automation.model.monthly_budget_usd' => 10,
        ]);
    }

    public function test_the_sample_file_is_complete_and_anonymous(): void
    {
        $set = json_decode((string) file_get_contents(self::SAMPLES), true);

        $this->assertIsArray($set);
        $this->assertGreaterThanOrEqual(25, count($set['samples']));
        $types = array_count_values(array_column($set['samples'], 'type'));
        $this->assertGreaterThan(10, $types['question']);
        $this->assertGreaterThan(2, $types['statement']);
        $this->assertGreaterThan(4, $types['no_answer']);

        $ids = [];
        foreach ($set['samples'] as $sample) {
            $this->assertArrayNotHasKey($sample['id'], $ids, 'duplicate id');
            $ids[$sample['id']] = true;
            foreach ($sample['articles'] as $article) {
                $this->assertArrayHasKey($article, $set['articles'], "{$sample['id']} names an unknown article");
            }
            if ($sample['type'] !== 'no_answer') {
                $this->assertNotEmpty($sample['must_include'], "{$sample['id']} needs facts to check");
            }
        }

        $text = (string) file_get_contents(self::SAMPLES);
        $this->assertDoesNotMatchRegularExpression('/\d{3}[-.\s]\d{3}[-.\s]\d{4}|@[a-z0-9.-]+\.[a-z]{2,}|https?:\/\//i', $text, 'no phone numbers, emails or links');
    }

    public function test_without_run_nothing_is_sent(): void
    {
        Http::fake();

        $this->artisan('ai-automation:benchmark', ['--model' => ['@cf/openai/gpt-oss-120b', '@cf/openai/gpt-oss-20b']])
            ->expectsOutputToContain('Dry run: nothing is sent')
            ->expectsOutputToContain('@cf/openai/gpt-oss-20b')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AiModelCall::query()->count());
    }

    public function test_an_unpriced_model_is_refused(): void
    {
        $this->artisan('ai-automation:benchmark', ['--model' => ['@cf/vendor/not-priced-yet'], '--run' => true])
            ->expectsOutputToContain('has no price')
            ->assertFailed();
    }

    public function test_a_run_goes_through_the_metered_client_for_each_model(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Mark it Missing on the daily checkout, then check the station for extra first.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 600, 'completion_tokens' => 30],
            ],
        ])]);

        $this->artisan('ai-automation:benchmark', ['--model' => ['@cf/openai/gpt-oss-120b', '@cf/openai/gpt-oss-20b'], '--limit' => 3, '--run' => true, '--json' => true])
            ->assertSuccessful();

        $this->assertSame(6, AiModelCall::query()->count());
        $this->assertSame(3, AiModelCall::query()->where('model', '@cf/openai/gpt-oss-20b')->count());
        // 600 x 0.35 + 30 x 0.75 = 232.5 -> 233 micro-USD on the larger model; 600 x 0.20 + 30 x 0.30 = 129 on the smaller.
        $this->assertSame(233, (int) AiModelCall::query()->where('model', '@cf/openai/gpt-oss-120b')->value('cost_micro_usd'));
        $this->assertSame(129, (int) AiModelCall::query()->where('model', '@cf/openai/gpt-oss-20b')->value('cost_micro_usd'));
    }

    public function test_a_run_respects_the_daily_call_limit(): void
    {
        config(['ai_automation.model.daily_call_limit' => 2]);
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Check the station for extra first.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]],
        ])]);

        $this->artisan('ai-automation:benchmark', ['--limit' => 5, '--run' => true, '--json' => true])->assertSuccessful();

        $this->assertSame(2, AiModelCall::query()->count(), 'the budget guard stopped the run at the daily limit');
    }

    public function test_scoring_rewards_grounded_answers_and_refusals(): void
    {
        $question = ['type' => 'question', 'must_include' => [['mark', 'missing'], ['checkout']]];
        $this->assertSame(['valid' => true, 'grounded' => true, 'correct' => true], BenchmarkScoring::scoreSample($question, 'Mark it Missing on the daily checkout.'));
        $this->assertSame(['valid' => true, 'grounded' => false, 'correct' => false], BenchmarkScoring::scoreSample($question, 'Mark it Missing.'));
        $this->assertSame(['valid' => false, 'grounded' => false, 'correct' => false], BenchmarkScoring::scoreSample($question, null));

        $none = ['type' => 'no_answer', 'must_include' => []];
        $this->assertTrue(BenchmarkScoring::scoreSample($none, null)['correct'], 'refusing is right');
        $this->assertFalse(BenchmarkScoring::scoreSample($none, 'Sure, it is in the second drawer.')['correct'], 'a confident answer is wrong');
        $this->assertSame(0, BenchmarkScoring::percentile([], 95));
        $this->assertSame(40, BenchmarkScoring::percentile([10, 20, 30, 40], 95));
        $this->assertSame(20, BenchmarkScoring::percentile([10, 20, 30, 40], 50));
    }

    public function test_item_names_are_readable(): void
    {
        $this->assertSame('Spanner Wrench', ItemNamer::label('Spanner Wrench (rear compartment)'));
        $this->assertSame('Kitty Litter', ItemNamer::label('KITTY LITTER'));
        $this->assertSame('SCBA Cylinder', ItemNamer::label('SCBA CYLINDER'));
        $this->assertSame('DeWalt 20V Battery', ItemNamer::label('DeWalt 20V Battery'));
        $this->assertSame('K-Tool', ItemNamer::label('K-Tool'));
        $this->assertSame('TIC Mount', ItemNamer::label('TIC Mount [rear]'));
        $this->assertSame('A', ItemNamer::join(['A']));
        $this->assertSame('A and B', ItemNamer::join(['A', 'a (x)', 'B']));
        $this->assertSame('A, B and C', ItemNamer::join(['A', 'B', 'C']));
        $this->assertSame('A, B, C and other items', ItemNamer::join(['A', 'B', 'C', 'D']));
        $this->assertSame('item', ItemNamer::join(['  ']));
        $this->assertLessThanOrEqual(60, mb_strlen(ItemNamer::label(str_repeat('Long Item Name ', 10))));
    }
}
