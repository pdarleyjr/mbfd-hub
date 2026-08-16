<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\AddBuildHeaders;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class AddBuildHeadersTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/mbfd-build-header-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/public', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_prefers_the_runtime_deploy_marker_over_the_source_snapshot(): void
    {
        file_put_contents($this->basePath.'/.git-sha', str_repeat('a', 40));
        file_put_contents($this->basePath.'/public/deploy-marker.json', json_encode([
            'sha' => str_repeat('B', 40),
            'deployed_at' => '2026-08-16T20:26:43Z',
        ], JSON_THROW_ON_ERROR));

        $response = $this->responseFromMiddleware();

        self::assertSame(str_repeat('b', 40), $response->headers->get('X-App-Commit'));
    }

    public function test_it_falls_back_to_the_source_snapshot_when_the_marker_is_invalid(): void
    {
        file_put_contents($this->basePath.'/.git-sha', str_repeat('c', 40));
        file_put_contents($this->basePath.'/public/deploy-marker.json', '{partial');

        $response = $this->responseFromMiddleware();

        self::assertSame(str_repeat('c', 40), $response->headers->get('X-App-Commit'));
    }

    private function responseFromMiddleware(): Response
    {
        return (new AddBuildHeaders($this->basePath))->handle(
            Request::create('/health'),
            fn (): Response => new Response('ok'),
        );
    }
}
