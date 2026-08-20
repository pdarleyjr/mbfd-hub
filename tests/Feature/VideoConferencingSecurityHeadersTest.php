<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class VideoConferencingSecurityHeadersTest extends TestCase
{
    public function test_video_conferencing_policy_allows_livekit_signaling_and_validation(): void
    {
        config([
            'video-conferencing.livekit.profile' => 'cloud',
            'video-conferencing.livekit.profiles.cloud.url' => 'wss://mbfd-cloud.video.test.example',
            'video-conferencing.livekit.profiles.cloud.api_url' => 'https://mbfd-cloud.video.test.example',
            'video-conferencing.realtime.host' => 'www.mbfdhub.com',
            'video-conferencing.realtime.port' => 443,
            'video-conferencing.realtime.scheme' => 'https',
        ]);
        $response = $this->get('https://www.mbfdhub.com/employee/video-conferencing');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://mbfd-cloud.video.test.example', $policy);
        $this->assertStringContainsString('wss://mbfd-cloud.video.test.example', $policy);
        $this->assertStringContainsString('wss://www.mbfdhub.com', $policy);
        $this->assertStringNotContainsString("connect-src 'self' wss: ", $policy);
    }

    public function test_livekit_origins_are_not_allowed_on_unrelated_pages(): void
    {
        $response = $this->get('https://www.mbfdhub.com/');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('livekit.cloud', $policy);
    }
}
