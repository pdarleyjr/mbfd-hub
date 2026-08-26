<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Workgroup\WorkgroupAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    public function test_known_external_integrations_are_disabled_in_phpunit(): void
    {
        foreach ([
            'cloudflare.ai.account_id',
            'cloudflare.ai.api_token',
            'cloudflare.ai.local.url',
            'cloudflare.worker_api_secret',
            'cloudflare.worker_url',
            'filesystems.disks.r2.endpoint',
            'filesystems.disks.r2.key',
            'filesystems.disks.r2.secret',
            'health.notifications.slack.webhook_url',
            'health.oh_dear_endpoint.secret',
            'health.oh_dear_endpoint.url',
            'sentry.dsn',
            'services.bid.console_url',
            'services.bid.reader_token',
            'services.cloudflare.ai.account_id',
            'services.cloudflare.ai.api_token',
            'services.cloudflare.api_secret',
            'services.cloudflare.worker_url',
            'services.display_api.token',
            'services.pulsepoint.worker_url',
            'services.screentinker.sync_token',
            'services.screentinker.sync_url',
            'services.snipeit.token',
            'services.snipeit.url',
            'video-conferencing.livekit.profiles.cloud.api_key',
            'video-conferencing.livekit.profiles.cloud.api_secret',
            'video-conferencing.livekit.profiles.cloud.api_url',
            'video-conferencing.livekit.profiles.cloud.url',
            'video-conferencing.livekit.profiles.self_hosted.api_key',
            'video-conferencing.livekit.profiles.self_hosted.api_secret',
            'video-conferencing.livekit.profiles.self_hosted.api_url',
            'video-conferencing.livekit.profiles.self_hosted.url',
            'webpush.vapid.pem_file',
            'webpush.vapid.private_key',
            'webpush.vapid.public_key',
            'webpush.vapid.subject',
        ] as $configuration) {
            $this->assertEmpty(config($configuration), $configuration.' must be blank in PHPUnit');
        }

        $this->assertFalse((bool) config('cloudflare.ai.enabled'));
        $this->assertFalse((bool) config('google_sheets.apparatus_sync_enabled'));
        $this->assertFalse((bool) config('services.cloudflare.ai.enabled'));
        $this->assertFalse(app(WorkgroupAIService::class)->isEnabled());
        $this->assertFalse((bool) config('video-conferencing.enabled'));
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('local', config('filesystems.default'));
        $this->assertSame('local', config('filesystems.private'));
        $this->assertSame('log', config('broadcasting.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertTrue(Http::getFacadeRoot()->preventingStrayRequests());
        $this->assertTrue(Process::getFacadeRoot()->preventingStrayProcesses());
    }

    public function test_phpunit_forces_the_core_isolation_environment(): void
    {
        $configuration = (string) file_get_contents(base_path('phpunit.xml'));

        foreach ([
            'APP_ENV',
            'APP_KEY',
            'APP_URL',
            'CACHE_STORE',
            'DB_CONNECTION',
            'DB_DATABASE',
            'FILESYSTEM_DISK',
            'MAIL_MAILER',
            'PRIVATE_FILESYSTEM_DISK',
            'PULSE_ENABLED',
            'QUEUE_CONNECTION',
            'SESSION_DRIVER',
            'TELESCOPE_ENABLED',
            'WEBPUSH_DB_CONNECTION',
            'WORKGROUP_AI_ENABLED',
        ] as $variable) {
            $this->assertMatchesRegularExpression(
                '/<env name="'.preg_quote($variable, '/').'" value="[^"]*" force="true"\/>/',
                $configuration,
                $variable.' must override an inherited process environment value.',
            );
        }
    }
}
