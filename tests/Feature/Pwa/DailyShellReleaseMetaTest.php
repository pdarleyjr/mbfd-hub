<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Support\Release\DeployedRelease;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Daily SPA shell is a static build rendered through a Laravel route. The page-side
 * update registrar needs to know which release produced the shell, so a shell that the
 * service worker restores from its offline cache days later is recognised as old.
 */
final class DailyShellReleaseMetaTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/mbfd-daily-release-'.bin2hex(random_bytes(8));
        mkdir($this->basePath, 0777, true);
        $this->app->instance(DeployedRelease::class, new DeployedRelease($this->basePath));

        // The SPA shell is a build artifact; provide a stand-in when it is absent.
        $shell = public_path('daily/index.html');
        if (! is_file($shell)) {
            @mkdir(dirname($shell), 0777, true);
            file_put_contents($shell, '<html><head></head><body><div id="root"></div></body></html>');
            $this->beforeApplicationDestroyed(fn () => @unlink($shell));
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_the_shell_carries_the_deployed_release_that_app_release_reports(): void
    {
        file_put_contents($this->basePath.'/.git-sha', 'abcdef0123456789abcdef0123456789abcdef01');
        $this->actingAsCanonicalFixture('E-DAILY-REL', 'Release Member', 'Firefighter');

        $response = $this->get('/daily/stations')->assertOk();

        $response->assertSee('<meta name="mbfd-release" content="abcdef012345">', false);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->getJson('/app-release')->assertExactJson(['release' => 'abcdef012345']);
    }

    public function test_an_unknown_release_is_published_as_such_not_as_a_commit(): void
    {
        $this->actingAsCanonicalFixture('E-DAILY-UNK', 'Unknown Member', 'Firefighter');

        $this->get('/daily/')->assertOk()->assertSee('<meta name="mbfd-release" content="unknown">', false);
    }
}
