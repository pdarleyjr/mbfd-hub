<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiAutomationSetting;
use App\Models\AiAutomation\AiModelCall;
use App\Services\AiAutomation\Model\AiBudgetGuard;
use App\Services\AiAutomation\Model\ModelReply;
use App\Services\AiAutomation\Model\WorkersAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** The optional Workers AI client and its budget guard. Every request here is faked; nothing leaves the process. */
final class WorkersAiClientTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'abcdef0123456789abcdef0123456789';

    private const TOKEN = 'test-token-not-real-7731';

    private const SYSTEM = 'SYSTEM-PROMPT-MARKER rephrase the guidance only.';

    private const USER = 'USER-CONTENT-MARKER member asked about the spare battery.';

    private const REPLY = 'Please check the spare compartment first.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('America/New_York')->setDate(2026, 11, 3)->setTime(12, 0));

        config()->set('ai_automation.timezone', 'America/New_York');
        config()->set('ai_automation.hard_disabled', false);
        config()->set('ai_automation.model', [
            'enabled' => true,
            'account_id' => self::ACCOUNT,
            'api_token' => self::TOKEN,
            'model' => '@cf/openai/gpt-oss-120b',
            'timeout_seconds' => 5,
            'max_output_tokens' => 300,
            'daily_call_limit' => 100,
            'monthly_budget_usd' => 10,
            'prices' => [
                '@cf/openai/gpt-oss-120b' => ['in' => 0.35, 'out' => 0.75],
                '@cf/openai/gpt-oss-20b' => ['in' => 0.20, 'out' => 0.30],
            ],
        ]);
    }

    private function client(): WorkersAiClient
    {
        return $this->app->make(WorkersAiClient::class);
    }

    /** @param  array<string, mixed>  $message */
    private function chatResult(array $message = [], array $usage = ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160], string $finish = 'stop'): array
    {
        $result = [
            'choices' => [[
                'index' => 0,
                'message' => array_merge(['role' => 'assistant', 'content' => self::REPLY], $message),
                'finish_reason' => $finish,
            ]],
        ];
        if ($usage !== []) {
            $result['usage'] = $usage;
        }

        return ['result' => $result, 'success' => true, 'errors' => [], 'messages' => []];
    }

    /**
     * Answer successive requests in order. Http::fake() keeps the first matching stub, so re-faking inside a
     * loop would silently replay the first response. A Throwable entry is thrown instead of answered.
     *
     * @param  list<array{0: mixed, 1: int}|\Throwable>  $items
     */
    private function fakeQueue(array $items): void
    {
        $queue = $items;
        Http::fake(function () use (&$queue) {
            $item = array_shift($queue) ?? throw new \LogicException('The test made more requests than it faked.');
            if ($item instanceof \Throwable) {
                throw $item;
            }

            return Http::response($item[0], $item[1]);
        });
    }

    private function seedCall(string $date, string $state, int $costMicro): AiModelCall
    {
        return AiModelCall::query()->create([
            'call_date' => $date,
            'model' => '@cf/openai/gpt-oss-120b',
            'state' => $state,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micro_usd' => $costMicro,
        ]);
    }

    public function test_disabled_makes_no_request(): void
    {
        Http::fake();
        config()->set('ai_automation.model.enabled', false);

        $this->assertFalse($this->client()->available());
        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));

        Http::assertNothingSent();
        $this->assertSame(0, AiModelCall::query()->count());
    }

    public function test_hard_disabled_makes_no_request(): void
    {
        Http::fake();
        config()->set('ai_automation.hard_disabled', true);

        $this->assertFalse($this->client()->available());
        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));
        Http::assertNothingSent();
    }

    public function test_missing_token_or_account_makes_no_request(): void
    {
        Http::fake();

        foreach ([['api_token', null], ['api_token', ''], ['account_id', null], ['account_id', 'not-a-real-account-id']] as [$key, $value]) {
            config()->set('ai_automation.model.account_id', self::ACCOUNT);
            config()->set('ai_automation.model.api_token', self::TOKEN);
            config()->set("ai_automation.model.$key", $value);

            $this->assertFalse($this->client()->available(), "$key=".var_export($value, true));
            $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiModelCall::query()->count());
    }

    public function test_success_returns_text_and_records_completed_call_with_cost(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult(), 200)]);

        $reply = $this->client()->compose(self::SYSTEM, self::USER);

        $this->assertInstanceOf(ModelReply::class, $reply);
        $this->assertSame(self::REPLY, $reply->text);
        $this->assertSame(120, $reply->inputTokens);
        $this->assertSame(40, $reply->outputTokens);

        $call = AiModelCall::query()->findOrFail($reply->modelCallId);
        $this->assertSame(AiModelCall::STATE_COMPLETED, $call->state);
        $this->assertSame('@cf/openai/gpt-oss-120b', $call->model);
        $this->assertSame(120, (int) $call->input_tokens);
        $this->assertSame(40, (int) $call->output_tokens);
        // 120 x 0.35 + 40 x 0.75 = 72 micro-USD.
        $this->assertSame(72, (int) $call->cost_micro_usd);
        $this->assertNotNull($call->latency_ms);
        $this->assertNull($call->error_class);
        $this->assertSame('2026-11-03', $call->call_date->toDateString());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT.'/ai/run/@cf/openai/gpt-oss-120b'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request['messages'][0] === ['role' => 'system', 'content' => self::SYSTEM]
                && $request['messages'][1] === ['role' => 'user', 'content' => self::USER]
                && $request['max_tokens'] === 300
                && $request['temperature'] <= 0.3
                && $request['stream'] === false
                && ! isset($request['tools'])
                && ! isset($request['reasoning_effort']);
        });
    }

    public function test_reasoning_effort_is_sent_only_when_configured_to_a_known_level(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult(), 200)]);
        config()->set('ai_automation.model.reasoning_effort', 'low');
        $this->client()->compose(self::SYSTEM, self::USER);
        Http::assertSent(fn (Request $request): bool => $request['reasoning_effort'] === 'low');

        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult(), 200)]);
        config()->set('ai_automation.model.reasoning_effort', 'bogus');
        $this->client()->compose(self::SYSTEM, self::USER);
        Http::assertSent(fn (Request $request): bool => ! isset($request['reasoning_effort']));
    }

    public function test_max_tokens_is_bounded_by_the_hard_ceiling(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult(), 200)]);
        config()->set('ai_automation.model.max_output_tokens', 999999);

        $this->client()->compose(self::SYSTEM, self::USER);

        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === AiBudgetGuard::OUTPUT_TOKEN_CEILING);
    }

    public function test_responses_style_output_keeps_only_the_final_message(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'result' => [
                'output' => [
                    ['type' => 'reasoning', 'content' => [['type' => 'reasoning_text', 'text' => 'HIDDEN-CHAIN-OF-THOUGHT']]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => self::REPLY]]],
                ],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ],
            'success' => true,
        ], 200)]);

        $reply = $this->client()->compose(self::SYSTEM, self::USER);

        $this->assertSame(self::REPLY, $reply?->text);
        $this->assertSame(50, $reply?->inputTokens);
        $this->assertSame(20, $reply?->outputTokens);
    }

    public function test_legacy_response_string_is_accepted(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['result' => ['response' => self::REPLY], 'success' => true], 200)]);

        $this->assertSame(self::REPLY, $this->client()->compose(self::SYSTEM, self::USER)?->text);
    }

    public function test_missing_usage_is_estimated_from_character_counts(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult([], []), 200)]);

        $reply = $this->client()->compose(self::SYSTEM, self::USER);

        $this->assertNotNull($reply);
        $this->assertSame(AiBudgetGuard::estimateTokens(self::SYSTEM.self::USER) + 32, $reply->inputTokens);
        $this->assertSame(AiBudgetGuard::estimateTokens(self::REPLY), $reply->outputTokens);
        $this->assertGreaterThan(0, (int) AiModelCall::query()->findOrFail($reply->modelCallId)->cost_micro_usd);
    }

    public function test_reasoning_content_is_stripped_from_the_output(): void
    {
        $leaky = [
            '<think>HIDDEN-CHAIN-OF-THOUGHT</think>'.self::REPLY,
            "<thinking>HIDDEN-CHAIN-OF-THOUGHT\nmore</thinking>\n".self::REPLY,
            '<|channel|>analysis<|message|>HIDDEN-CHAIN-OF-THOUGHT<|end|><|start|>assistant<|channel|>final<|message|>'.self::REPLY.'<|return|>',
        ];

        $this->fakeQueue(array_map(fn (string $content): array => [$this->chatResult([
            'content' => $content,
            'reasoning_content' => 'HIDDEN-CHAIN-OF-THOUGHT',
            'reasoning' => 'HIDDEN-CHAIN-OF-THOUGHT',
        ]), 200], $leaky));

        foreach ($leaky as $content) {
            $reply = $this->client()->compose(self::SYSTEM, self::USER);

            $this->assertSame(self::REPLY, $reply?->text);
            $this->assertStringNotContainsString('HIDDEN-CHAIN-OF-THOUGHT', (string) $reply?->text);
        }
    }

    public function test_reasoning_only_or_unclosed_or_truncated_output_is_a_failure(): void
    {
        $this->fakeQueue([
            [$this->chatResult(['content' => '', 'reasoning_content' => 'all budget spent thinking']), 200],
            [$this->chatResult(['content' => '<think>never closed and no answer']), 200],
            [$this->chatResult(['content' => 'Half an ans'], ['prompt_tokens' => 10, 'completion_tokens' => 300], 'length'), 200],
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));
        }

        $rows = AiModelCall::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['empty', 'empty', 'truncated'], $rows->pluck('error_class')->all());
        $this->assertSame([AiModelCall::STATE_FAILED], $rows->pluck('state')->unique()->values()->all());
    }

    public function test_http_500_returns_null_marks_failed_keeps_estimate_and_does_not_retry(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['errors' => [['message' => 'boom']], 'success' => false], 500)]);

        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));

        Http::assertSentCount(1);
        $call = AiModelCall::query()->sole();
        $this->assertSame(AiModelCall::STATE_FAILED, $call->state);
        $this->assertSame('http_5xx', $call->error_class);
        $this->assertGreaterThan(0, (int) $call->cost_micro_usd, 'a 5xx may have been processed, so the estimate stays booked');
    }

    public function test_client_errors_release_the_estimate(): void
    {
        $this->fakeQueue([
            [['success' => false, 'errors' => []], 401],
            [['success' => false, 'errors' => []], 429],
        ]);
        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));
        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));

        $rows = AiModelCall::query()->orderBy('id')->get();
        $this->assertSame(['http_4xx', 'http_429'], $rows->pluck('error_class')->all());
        $this->assertSame([0], $rows->pluck('cost_micro_usd')->map(fn ($v) => (int) $v)->unique()->values()->all());
    }

    public function test_timeout_returns_null_and_marks_failed(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: timed out https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT);
        });

        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));

        $call = AiModelCall::query()->sole();
        $this->assertSame(AiModelCall::STATE_FAILED, $call->state);
        $this->assertSame('timeout', $call->error_class);
        $this->assertNotNull($call->latency_ms);
    }

    public function test_malformed_json_returns_null_and_marks_failed(): void
    {
        $bodies = ['this is not json {', '"just a string"', json_encode(['result' => 'nope', 'success' => true]), json_encode(['success' => false, 'errors' => [['code' => 1]]])];
        $this->fakeQueue(array_map(fn (string $body): array => [$body, 200], $bodies));
        foreach ($bodies as $body) {
            $this->assertNull($this->client()->compose(self::SYSTEM, self::USER), $body);
        }

        $rows = AiModelCall::query()->get();
        $this->assertCount(4, $rows);
        $this->assertSame([AiModelCall::STATE_FAILED], $rows->pluck('state')->unique()->values()->all());
        $this->assertSame(['malformed'], $rows->pluck('error_class')->unique()->values()->all());
    }

    public function test_daily_cap_refuses_without_a_request(): void
    {
        Http::fake();
        config()->set('ai_automation.model.daily_call_limit', 3);
        $this->seedCall('2026-11-03', AiModelCall::STATE_COMPLETED, 10);
        $this->seedCall('2026-11-03', AiModelCall::STATE_RESERVED, 10);
        $this->seedCall('2026-11-03', AiModelCall::STATE_FAILED, 0);

        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));

        Http::assertNothingSent();
        $this->assertSame(3, AiModelCall::query()->count());
    }

    public function test_daily_cap_counts_the_new_york_date_and_ignores_other_days(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult(), 200)]);
        config()->set('ai_automation.model.daily_call_limit', 1);
        // 03:30 UTC on Nov 3 is still the evening of Nov 2 in New York.
        $this->travelTo(now('UTC')->setDate(2026, 11, 3)->setTime(3, 30));

        $this->seedCall('2026-11-03', AiModelCall::STATE_COMPLETED, 10);
        $this->seedCall('2026-11-01', AiModelCall::STATE_COMPLETED, 10);
        $this->assertNotNull($this->client()->compose(self::SYSTEM, self::USER), 'only Nov 2 counts as today; no calls yet');

        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER), 'the Nov 2 call used the single daily slot');
        Http::assertSentCount(1);
        $this->assertSame('2026-11-02', AiModelCall::query()->orderByDesc('id')->firstOrFail()->call_date->toDateString());
    }

    public function test_monthly_cap_refuses_without_a_request(): void
    {
        Http::fake();
        config()->set('ai_automation.model.monthly_budget_usd', 0.001); // 1000 micro-USD
        $this->seedCall('2026-11-01', AiModelCall::STATE_COMPLETED, 800);

        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER), '800 spent + worst-case estimate exceeds 1000');

        Http::assertNothingSent();
        $this->assertSame(1, AiModelCall::query()->count());
    }

    public function test_monthly_cap_allows_a_call_that_fits_and_ignores_last_month(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult(), 200)]);
        config()->set('ai_automation.model.monthly_budget_usd', 0.001);
        $this->seedCall('2026-11-01', AiModelCall::STATE_COMPLETED, 600);
        $this->seedCall('2026-10-31', AiModelCall::STATE_COMPLETED, 5_000_000);

        $this->assertNotNull($this->client()->compose(self::SYSTEM, self::USER));
    }

    public function test_guard_fails_closed_when_unconfigured_or_unreadable(): void
    {
        $guard = new AiBudgetGuard;

        config()->set('ai_automation.model.daily_call_limit', 0);
        $this->assertNull($guard->reserve('@cf/openai/gpt-oss-120b'));

        config()->set('ai_automation.model.daily_call_limit', 100);
        config()->set('ai_automation.model.monthly_budget_usd', 0);
        $this->assertNull($guard->reserve('@cf/openai/gpt-oss-120b'));

        config()->set('ai_automation.model.monthly_budget_usd', 10);
        AiAutomationSetting::query()->delete();
        $this->assertNull($guard->reserve('@cf/openai/gpt-oss-120b'), 'no settings row to lock');
        $this->assertSame(0, AiModelCall::query()->count());
    }

    public function test_usage_summary_reports_today_and_month(): void
    {
        config()->set('ai_automation.model.daily_call_limit', 25);
        config()->set('ai_automation.model.monthly_budget_usd', 7.5);
        $this->seedCall('2026-11-03', AiModelCall::STATE_COMPLETED, 1_500_000);
        $this->seedCall('2026-11-02', AiModelCall::STATE_COMPLETED, 500_000);
        $this->seedCall('2026-10-30', AiModelCall::STATE_COMPLETED, 9_000_000);

        $this->assertSame([
            'today_calls' => 1,
            'daily_limit' => 25,
            'month_cost_usd' => 2.0,
            'monthly_budget_usd' => 7.5,
        ], (new AiBudgetGuard)->usageSummary());
    }

    public function test_prompts_responses_and_credentials_never_reach_the_logs(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        $this->fakeQueue([
            [['success' => false, 'errors' => [['message' => 'SYSTEM-PROMPT-MARKER echoed back']]], 500],
            [$this->chatResult(['content' => '<think>RESPONSE-BODY-MARKER']), 200],
            ['RESPONSE-BODY-MARKER not json', 200],
            new ConnectionException('timed out calling https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT.' with '.self::TOKEN.' USER-CONTENT-MARKER'),
        ]);
        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));
        }
        $this->assertSame(4, AiModelCall::query()->where('state', AiModelCall::STATE_FAILED)->count());

        $joined = implode("\n", $logged);
        $this->assertNotSame('', $joined, 'failures are logged (code and status only)');
        foreach (['SYSTEM-PROMPT-MARKER', 'USER-CONTENT-MARKER', 'RESPONSE-BODY-MARKER', self::TOKEN, self::ACCOUNT, self::REPLY] as $secret) {
            $this->assertStringNotContainsString($secret, $joined);
        }
    }

    public function test_failure_log_carries_only_error_code_status_and_exception_class(): void
    {
        Log::spy();
        Http::fake(['api.cloudflare.com/*' => Http::response('boom', 503)]);

        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'ai_automation.model_call_failed'
                && $context === ['error' => 'http_5xx', 'status' => 503]);
    }

    public function test_cost_follows_the_model_that_made_the_call(): void
    {
        $guard = $this->app->make(AiBudgetGuard::class);

        // 1,000,000 tokens in and out: the price is the cost, in micro-dollars.
        $this->assertSame(1_100_000, $guard->costMicroUsd(1_000_000, 1_000_000, '@cf/openai/gpt-oss-120b'));
        $this->assertSame(500_000, $guard->costMicroUsd(1_000_000, 1_000_000, '@cf/openai/gpt-oss-20b'));

        config()->set('ai_automation.model.model', '@cf/openai/gpt-oss-20b');
        $this->assertSame(500_000, $guard->costMicroUsd(1_000_000, 1_000_000), 'the configured model is the default');
    }

    public function test_switching_the_model_switches_the_ledger_cost(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->chatResult())]);

        $expensive = $this->client()->compose(self::SYSTEM, self::USER);
        config()->set('ai_automation.model.model', '@cf/openai/gpt-oss-20b');
        $cheap = $this->client()->compose(self::SYSTEM, self::USER);

        $this->assertNotNull($expensive);
        $this->assertNotNull($cheap);
        $this->assertSame(72, (int) AiModelCall::query()->findOrFail($expensive->modelCallId)->cost_micro_usd);
        $this->assertSame(36, (int) AiModelCall::query()->findOrFail($cheap->modelCallId)->cost_micro_usd, '120 in x 0.20 + 40 out x 0.30');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/ai/run/@cf/openai/gpt-oss-20b'));
    }

    public function test_a_model_with_no_price_is_refused_before_any_request(): void
    {
        config()->set('ai_automation.model.model', '@cf/vendor/brand-new-model');
        Http::fake();

        $this->assertFalse($this->client()->available());
        $this->assertNull($this->client()->compose(self::SYSTEM, self::USER));
        $this->assertNull($this->app->make(AiBudgetGuard::class)->reserve('@cf/vendor/brand-new-model', 100));
        Http::assertNothingSent();
        $this->assertSame(0, AiModelCall::query()->count());
    }

    public function test_a_malformed_price_counts_as_unpriced(): void
    {
        config()->set('ai_automation.model.prices', ['@cf/openai/gpt-oss-120b' => ['in' => 'free', 'out' => 0.75]]);

        $this->assertNull($this->app->make(AiBudgetGuard::class)->priceFor('@cf/openai/gpt-oss-120b'));
        $this->assertFalse($this->client()->available());
    }

    public function test_every_listed_default_price_is_complete(): void
    {
        // The shipped defaults, not the test overrides from setUp.
        $defaults = require config_path('ai_automation.php');
        config()->set('ai_automation.model.prices', $defaults['model']['prices']);
        $guard = $this->app->make(AiBudgetGuard::class);

        foreach (array_keys($defaults['model']['prices']) as $model) {
            $price = $guard->priceFor($model);
            $this->assertNotNull($price, $model);
            $this->assertGreaterThan(0.0, $price['in'], $model);
            $this->assertGreaterThan(0.0, $price['out'], $model);
        }
        $this->assertNotNull($guard->priceFor('@cf/openai/gpt-oss-120b'));
        $this->assertArrayHasKey((string) env('AI_AUTOMATION_MODEL', '@cf/openai/gpt-oss-120b'), $defaults['model']['prices'], 'the default model must be priced');
    }
}
