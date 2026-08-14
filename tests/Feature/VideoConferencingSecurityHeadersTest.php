<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class VideoConferencingSecurityHeadersTest extends TestCase
{
    public function test_video_conferencing_policy_allows_livekit_signaling_and_validation(): void
    {
        $response = $this->get('https://www.mbfdhub.com/employee/video-conferencing');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://video.mbfdhub.com', $policy);
        $this->assertStringContainsString('wss://video.mbfdhub.com', $policy);
    }

    public function test_livekit_origins_are_not_allowed_on_unrelated_pages(): void
    {
        $response = $this->get('https://www.mbfdhub.com/');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('https://video.mbfdhub.com', $policy);
        $this->assertStringNotContainsString('wss://video.mbfdhub.com', $policy);
    }
}
