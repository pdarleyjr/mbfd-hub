<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Release;

use App\Support\Release\DeployedRelease;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class DeployedReleaseTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/mbfd-deployed-release-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/storage/app/private', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_the_deploy_marker_wins_for_the_deploy_proof_but_the_image_snapshot_names_the_served_build(): void
    {
        file_put_contents($this->basePath.'/.git-sha', str_repeat('a', 40));
        file_put_contents($this->basePath.'/storage/app/private/deploy-marker.json', json_encode(['sha' => str_repeat('B', 40)], JSON_THROW_ON_ERROR));

        $release = new DeployedRelease($this->basePath);

        // X-App-Commit semantics are unchanged: the marker is the proof of the last completed deploy.
        self::assertSame(str_repeat('b', 40), $release->commit());
        // The page-facing identity is the build that is answering: the marker on the bind mount can
        // still name the previous release for the whole deploy window (or after a rollback).
        self::assertSame(str_repeat('a', 40), $release->servedCommit());
        self::assertSame('aaaaaaaaaaaa', $release->short());
    }

    public function test_the_served_release_falls_back_to_the_marker_when_the_image_has_no_usable_snapshot(): void
    {
        file_put_contents($this->basePath.'/storage/app/private/deploy-marker.json', json_encode(['sha' => str_repeat('B', 40)], JSON_THROW_ON_ERROR));
        self::assertSame('bbbbbbbbbbbb', (new DeployedRelease($this->basePath))->short());

        file_put_contents($this->basePath.'/.git-sha', 'not-a-sha');
        self::assertSame('bbbbbbbbbbbb', (new DeployedRelease($this->basePath))->short());

        unlink($this->basePath.'/storage/app/private/deploy-marker.json');
        self::assertSame('unknown', (new DeployedRelease($this->basePath))->short());
        self::assertSame('unknown', (new DeployedRelease($this->basePath))->servedCommit());
    }

    public function test_an_invalid_marker_falls_back_and_nothing_means_unknown(): void
    {
        file_put_contents($this->basePath.'/storage/app/private/deploy-marker.json', '{"sha": "short"}');
        self::assertSame('unknown', (new DeployedRelease($this->basePath))->commit());
        self::assertSame('unknown', (new DeployedRelease($this->basePath))->short());

        file_put_contents($this->basePath.'/.git-sha', str_repeat('c', 40));
        self::assertSame(str_repeat('c', 40), (new DeployedRelease($this->basePath))->commit());
    }
}
