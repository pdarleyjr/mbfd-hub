<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Workgroup\WorkgroupAIService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
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

    /** @param array<string, string> $environment */
    private function workgroupConfiguration(array $environment): array
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
            /** @var array{ai_worker_enabled: bool, ai_worker_url: string, ai_worker_secret: ?string} $configuration */
            $configuration = require base_path('config/workgroup.php');

            return $configuration;
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
