<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Workgroup;
use App\Services\Workgroup\WorkgroupAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkgroupAIServiceConfigurationTest extends TestCase
{
    public function test_workgroup_ai_configuration_requires_explicit_enablement_a_url_and_a_secret(): void
    {
        $disabled = $this->workgroupConfiguration([
            'WORKGROUP_AI_ENABLED' => 'false',
            'WORKGROUP_AI_WORKER_URL' => 'https://workgroup-ai.example.test',
            'WORKGROUP_AI_WORKER_SECRET' => 'test-secret',
        ]);
        $this->assertFalse($disabled['ai_worker_enabled']);
        $this->assertSame('', $disabled['ai_worker_url']);
        $this->assertNull($disabled['ai_worker_secret']);

        $missingSecret = $this->workgroupConfiguration([
            'WORKGROUP_AI_ENABLED' => 'true',
            'WORKGROUP_AI_WORKER_URL' => 'https://workgroup-ai.example.test',
            'WORKGROUP_AI_WORKER_SECRET' => '',
        ]);
        $this->assertFalse($missingSecret['ai_worker_enabled']);
        $this->assertSame('', $missingSecret['ai_worker_url']);
        $this->assertNull($missingSecret['ai_worker_secret']);

        $enabled = $this->workgroupConfiguration([
            'WORKGROUP_AI_ENABLED' => 'true',
            'WORKGROUP_AI_WORKER_URL' => 'https://workgroup-ai.example.test',
            'WORKGROUP_AI_WORKER_SECRET' => 'test-secret',
        ]);
        $this->assertTrue($enabled['ai_worker_enabled']);
        $this->assertSame('https://workgroup-ai.example.test', $enabled['ai_worker_url']);
        $this->assertSame('test-secret', $enabled['ai_worker_secret']);
    }

    public function test_phpunit_cannot_send_document_text_to_the_workgroup_worker(): void
    {
        Http::fake();

        $service = app(WorkgroupAIService::class);

        $this->assertFalse($service->isEnabled());
        $this->assertSame(
            ['success' => false, 'error' => 'Service not enabled or empty text'],
            $service->vectorizeTextChunk('Sensitive test text', 'specification.txt'),
        );
        Http::assertNothingSent();
    }

    public function test_disabled_worker_configuration_never_sends_document_text(): void
    {
        $this->configureWorkgroupService(
            enabled: false,
            url: 'https://workgroup-ai.example.test',
            secret: 'test-secret',
        );
        Http::fake();

        $service = new WorkgroupAIService;

        $this->assertFalse($service->isEnabled());
        $this->assertSame(
            ['success' => false, 'error' => 'Service not enabled or empty text'],
            $service->vectorizeTextChunk('Sensitive test text', 'specification.txt'),
        );
        Http::assertNothingSent();
    }

    public function test_enabled_worker_configuration_without_a_secret_never_sends_document_text(): void
    {
        $this->configureWorkgroupService(
            enabled: true,
            url: 'https://workgroup-ai.example.test',
            secret: null,
        );
        Http::fake();

        $service = new WorkgroupAIService;

        $this->assertFalse($service->isEnabled());
        $this->assertSame(
            ['success' => false, 'error' => 'Service not enabled or empty text'],
            $service->vectorizeTextChunk('Sensitive test text', 'specification.txt'),
        );
        Http::assertNothingSent();
    }

    public function test_enabled_worker_configuration_without_a_url_never_sends_document_text(): void
    {
        $this->configureWorkgroupService(
            enabled: true,
            url: '',
            secret: 'test-secret',
        );
        Http::fake();

        $service = new WorkgroupAIService;

        $this->assertFalse($service->isEnabled());
        $this->assertSame(
            ['success' => false, 'error' => 'Service not enabled or empty text'],
            $service->vectorizeTextChunk('Sensitive test text', 'specification.txt'),
        );
        Http::assertNothingSent();
    }

    public function test_enabled_worker_configuration_sends_the_required_authentication_header(): void
    {
        $this->configureWorkgroupService(
            enabled: true,
            url: 'https://workgroup-ai.example.test',
            secret: 'test-secret',
        );
        Http::fake([
            'https://workgroup-ai.example.test/vectorize' => Http::response(['success' => true]),
        ]);

        $service = new WorkgroupAIService;

        $this->assertTrue($service->isEnabled());
        $this->assertSame(
            ['success' => true],
            $service->vectorizeTextChunk('Sensitive test text', 'specification.txt'),
        );
        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://workgroup-ai.example.test/vectorize'
                && $request->hasHeader('x-api-secret', 'test-secret'),
        );
    }

    public function test_cached_configuration_never_falls_back_to_runtime_environment_values(): void
    {
        config(['workgroup' => []]);

        $this->withWorkgroupEnvironment([
            'WORKGROUP_AI_ENABLED' => 'true',
            'WORKGROUP_AI_WORKER_URL' => 'https://runtime-environment.example.test',
            'WORKGROUP_AI_WORKER_SECRET' => 'runtime-environment-secret',
        ], function (): void {
            Http::fake();

            $service = new WorkgroupAIService;

            $this->assertFalse($service->isEnabled());
            $this->assertSame(
                ['success' => false, 'error' => 'Service not enabled or empty text'],
                $service->vectorizeTextChunk('Sensitive test text', 'specification.txt'),
            );
            Http::assertNothingSent();
        });
    }

    public function test_phpunit_blocks_an_unfaked_workgroup_worker_request(): void
    {
        $this->configureWorkgroupService(
            enabled: true,
            url: 'https://workgroup-ai.example.test',
            secret: 'test-secret',
        );

        $this->assertTrue(Http::getFacadeRoot()->preventingStrayRequests());

        Log::spy();

        $result = (new WorkgroupAIService)->vectorizeTextChunk('Sensitive test text', 'specification.txt');

        $this->assertFalse($result['success']);
        $this->assertSame('AI service request failed. Please try again later.', $result['error']);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(
                fn (string $message, array $context): bool => $message === '[WorkgroupAI] Worker request failed'
                    && $context['operation'] === 'vectorize'
                    && isset($context['exception_type'])
                    && ! str_contains((string) json_encode($context), 'workgroup-ai.example.test'),
            );
    }

    public function test_worker_failure_logs_only_structured_metadata_and_returns_a_safe_error(): void
    {
        $this->configureWorkgroupService(
            enabled: true,
            url: 'https://workgroup-ai.example.test',
            secret: 'test-secret',
        );
        $sensitiveResponse = 'private worker response: procurement document text';
        Http::fake([
            'https://workgroup-ai.example.test/vectorize' => Http::response($sensitiveResponse, 502, [
                'X-Request-Id' => 'worker-request-123',
            ]),
        ]);
        Log::spy();

        $result = (new WorkgroupAIService)->vectorizeTextChunk('Sensitive test text', 'specification.txt');

        $this->assertSame(['success' => false, 'error' => 'AI service request failed. Please try again later.'], $result);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('[WorkgroupAI] Worker request failed', [
                'operation' => 'vectorize',
                'status' => 502,
                'request_id' => 'worker-request-123',
            ]);
    }

    public function test_worker_failure_uses_the_correlation_id_when_the_request_id_is_absent(): void
    {
        $this->configureWorkgroupService(
            enabled: true,
            url: 'https://workgroup-ai.example.test',
            secret: 'test-secret',
        );
        Http::fake([
            'https://workgroup-ai.example.test/vectorize' => Http::response('worker failure', 502, [
                'X-Correlation-Id' => 'worker-correlation-456',
            ]),
        ]);
        Log::spy();

        (new WorkgroupAIService)->vectorizeTextChunk('Sensitive test text', 'specification.txt');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('[WorkgroupAI] Worker request failed', [
                'operation' => 'vectorize',
                'status' => 502,
                'request_id' => 'worker-correlation-456',
            ]);
    }

    public function test_disabled_service_explains_the_complete_worker_configuration_contract(): void
    {
        $this->configureWorkgroupService(
            enabled: false,
            url: '',
            secret: null,
        );
        Http::fake();

        $report = (new WorkgroupAIService)->generateSaverReport(new Workgroup);

        $this->assertSame(
            '<p class="text-red-600">AI service not configured. Set WORKGROUP_AI_ENABLED, WORKGROUP_AI_WORKER_URL, and WORKGROUP_AI_WORKER_SECRET in configuration.</p>',
            $report,
        );
        Http::assertNothingSent();
    }

    private function configureWorkgroupService(bool $enabled, string $url, ?string $secret): void
    {
        config([
            'workgroup.ai_worker_enabled' => $enabled,
            'workgroup.ai_worker_url' => $url,
            'workgroup.ai_worker_secret' => $secret,
        ]);
    }

    /** @param array<string, string> $environment */
    private function workgroupConfiguration(array $environment): array
    {
        return $this->withWorkgroupEnvironment($environment, function (): array {
            /** @var array{ai_worker_enabled: bool, ai_worker_url: string, ai_worker_secret: ?string} $configuration */
            $configuration = require base_path('config/workgroup.php');

            return $configuration;
        });
    }

    /**
     * @template TResult
     *
     * @param  array<string, string>  $environment
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withWorkgroupEnvironment(array $environment, callable $callback): mixed
    {
        $keys = [
            'WORKGROUP_AI_ENABLED',
            'WORKGROUP_AI_WORKER_URL',
            'WORKGROUP_AI_WORKER_SECRET',
        ];
        $previous = [];

        foreach ($keys as $key) {
            $previous[$key] = [
                'environment' => getenv($key),
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
            ];
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        }

        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        Env::enablePutenv();

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value['environment'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$value['environment']);
                }

                if ($value['server_exists']) {
                    $_SERVER[$key] = $value['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($value['env_exists']) {
                    $_ENV[$key] = $value['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }

            Env::enablePutenv();
        }
    }
}
