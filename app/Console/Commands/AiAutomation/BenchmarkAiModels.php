<?php

declare(strict_types=1);

namespace App\Console\Commands\AiAutomation;

use App\Services\AiAutomation\Model\AiBudgetGuard;
use App\Services\AiAutomation\Model\BenchmarkScoring;
use App\Services\AiAutomation\Model\WorkersAiClient;
use App\Services\AiAutomation\ModelPrompt;
use App\Services\AiAutomation\ReplyValidator;
use Illuminate\Console\Command;

/**
 * Runs the anonymized sample set through one or more Workers AI models and prints validity, groundedness, length,
 * latency, tokens and cost per model. It goes through the same WorkersAiClient and budget guard as production, so
 * every call is metered, counts against the daily and monthly limits, and an unpriced model is refused.
 *
 * Safe by default: without --run it only validates the sample file and prints the worst-case spend.
 */
final class BenchmarkAiModels extends Command
{
    protected $signature = 'ai-automation:benchmark
        {--model=* : Model id to test, repeatable (default: the configured model)}
        {--samples= : Path to a samples JSON file (default: resources/ai/benchmark/support-services-samples.json)}
        {--limit= : Run only the first N samples}
        {--reasoning= : Reasoning effort to send (none|low|medium|high|max) for this run}
        {--run : Actually call Cloudflare. Without it nothing is sent}
        {--json : Print the results as JSON}';

    protected $description = 'Compare Workers AI models on the anonymized Support Services sample set (dry run unless --run)';

    public function handle(WorkersAiClient $client, ReplyValidator $validator, AiBudgetGuard $guard): int
    {
        $set = $this->loadSamples();
        if ($set === null) {
            return self::FAILURE;
        }
        [$articles, $samples] = $set;

        $models = array_values(array_unique(array_filter(array_map('strval', (array) $this->option('model')))));
        $models = $models === [] ? [(string) config('ai_automation.model.model')] : $models;

        foreach ($models as $model) {
            if ($guard->priceFor($model) === null) {
                $this->error("{$model} has no price in config ai_automation.model.prices. Add it from the Workers AI pricing page first.");

                return self::FAILURE;
            }
        }
        $reasoning = $this->option('reasoning');
        if (is_string($reasoning) && $reasoning !== '') {
            config(['ai_automation.model.reasoning_effort' => $reasoning]);
        }

        if (! $this->option('run')) {
            return $this->dryRun($models, $samples, $articles, $guard);
        }
        if (! $client->available()) {
            $this->error('Model calls are not available: the model is disabled, the account or token is missing, or the model is unpriced.');

            return self::FAILURE;
        }

        $results = [];
        foreach ($models as $model) {
            config(['ai_automation.model.model' => $model]);
            $results[] = $this->runModel($model, $samples, $articles, $client, $validator, $guard);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Model', 'Calls', 'Valid %', 'Grounded %', 'Correct %', 'Avg chars', 'p50 ms', 'p95 ms', 'Avg in', 'Avg out', 'Cost USD', 'USD / 1,000 replies', 'No reply'],
            array_map(static fn (array $r): array => [
                $r['model'], $r['calls'], $r['valid_pct'], $r['grounded_pct'], $r['correct_pct'], $r['avg_chars'], $r['p50_ms'], $r['p95_ms'],
                $r['avg_in'], $r['avg_out'], number_format($r['cost_usd'], 5), number_format($r['usd_per_1000'], 2), $r['no_reply'],
            ], $results),
        );
        $this->line('Correct = a grounded answer to a question the articles cover, or a refusal on one they do not. No reply = budget refusal, timeout or error.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $models
     * @param  list<array<string, mixed>>  $samples
     * @param  array<string, array<string, mixed>>  $articles
     */
    private function dryRun(array $models, array $samples, array $articles, AiBudgetGuard $guard): int
    {
        $this->info(count($samples).' samples, '.count($articles).' articles. Dry run: nothing is sent. Add --run to call Cloudflare.');
        $rows = [];
        foreach ($models as $model) {
            $price = (array) $guard->priceFor($model);
            $worst = 0;
            foreach ($samples as $sample) {
                $prompt = ModelPrompt::system().ModelPrompt::user((string) $sample['text'], $this->guidance($sample, $articles));
                $worst += $guard->costMicroUsd(AiBudgetGuard::estimateTokens($prompt) + 32, $guard->maxOutputTokens(), $model);
            }
            $rows[] = [$model, $price['in'], $price['out'], count($samples), number_format($worst / 1_000_000, 4)];
        }
        $this->table(['Model', 'USD / M in', 'USD / M out', 'Calls', 'Worst-case USD'], $rows);
        $this->line('Daily call limit '.(int) config('ai_automation.model.daily_call_limit').' and monthly budget USD '.(float) config('ai_automation.model.monthly_budget_usd').' still apply to a real run.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @param  array<string, array<string, mixed>>  $articles
     * @return array<string, mixed>
     */
    private function runModel(string $model, array $samples, array $articles, WorkersAiClient $client, ReplyValidator $validator, AiBudgetGuard $guard): array
    {
        $calls = $valid = $grounded = $correct = $noReply = $chars = $in = $out = $cost = 0;
        $latencies = [];
        foreach ($samples as $sample) {
            $guidance = $this->guidance($sample, $articles);
            $started = hrtime(true);
            $reply = $client->compose(ModelPrompt::system(), ModelPrompt::user((string) $sample['text'], $guidance));
            $latencies[] = (int) round((hrtime(true) - $started) / 1_000_000);
            $calls++;

            if ($reply === null) {
                $noReply++; // budget refusal, timeout or error: nothing to score

                continue;
            }

            $accepted = $validator->validateModelAnswer($reply->text, $guidance, (string) $sample['text']);
            $score = BenchmarkScoring::scoreSample($sample, $accepted);
            $valid += (int) $score['valid'];
            $grounded += (int) $score['grounded'];
            $correct += (int) $score['correct'];
            $chars += $accepted !== null ? mb_strlen($accepted) : 0;
            $in += $reply->inputTokens;
            $out += $reply->outputTokens;
            $cost += $guard->costMicroUsd($reply->inputTokens, $reply->outputTokens, $model);
        }

        $answered = max(1, $calls - $noReply);
        $pct = static fn (int $n): float => $calls > 0 ? round($n / $calls * 100, 1) : 0.0;

        return [
            'model' => $model,
            'calls' => $calls,
            'valid_pct' => $pct($valid),
            'grounded_pct' => $pct($grounded),
            'correct_pct' => $pct($correct),
            'avg_chars' => $valid > 0 ? (int) round($chars / $valid) : 0,
            'p50_ms' => BenchmarkScoring::percentile($latencies, 50),
            'p95_ms' => BenchmarkScoring::percentile($latencies, 95),
            'avg_in' => (int) round($in / $answered),
            'avg_out' => (int) round($out / $answered),
            'cost_usd' => $cost / 1_000_000,
            'usd_per_1000' => $calls > 0 ? $cost / 1_000_000 / $calls * 1000 : 0.0,
            'no_reply' => $noReply,
        ];
    }

    /**
     * The guidance text the composer would build: standing articles first, then the sample's own.
     *
     * @param  array<string, mixed>  $sample
     * @param  array<string, array<string, mixed>>  $articles
     */
    private function guidance(array $sample, array $articles): string
    {
        $parts = [];
        foreach ($articles as $id => $article) {
            if (($article['standing'] ?? false) === true || in_array($id, (array) ($sample['articles'] ?? []), true)) {
                $parts[$id] = '### '.$article['title']."\n".$article['body'];
            }
        }

        return implode("\n\n", $parts);
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>}|null */
    private function loadSamples(): ?array
    {
        $path = (string) ($this->option('samples') ?: resource_path('ai/benchmark/support-services-samples.json'));
        $json = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($json) || ! is_array($json['articles'] ?? null) || ! is_array($json['samples'] ?? null)) {
            $this->error("Could not read a samples file at {$path}.");

            return null;
        }

        $samples = [];
        foreach ($json['samples'] as $sample) {
            if (is_array($sample) && is_string($sample['text'] ?? null) && trim($sample['text']) !== '') {
                $samples[] = $sample;
            }
        }
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $samples = array_slice($samples, 0, $limit);
        }
        if ($samples === []) {
            $this->error('The samples file has no usable samples.');

            return null;
        }

        return [$json['articles'], $samples];
    }
}
