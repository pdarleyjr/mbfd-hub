<?php

namespace Tests\Unit\VideoConferencing;

use App\Services\VideoConferencing\LiveKitConferenceProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tests\TestCase;

class LiveKitConferenceProviderTest extends TestCase
{
    public function test_join_token_is_short_lived_and_scoped_to_one_room_without_admin_grants(): void
    {
        config([
            'video-conferencing.livekit.api_key' => 'test-key',
            'video-conferencing.livekit.api_secret' => 'test-secret-at-least-32-characters',
            'video-conferencing.livekit.url' => 'wss://video.test.example',
            'video-conferencing.livekit.token_ttl_seconds' => 600,
        ]);

        $issued = (new LiveKitConferenceProvider)->issueToken(
            'opaque-room-name',
            'mbfd:sta1',
            'Station 1',
            '{"join_as":"sta1"}',
        );
        $claims = (array) JWT::decode($issued->token, new Key('test-secret-at-least-32-characters', 'HS256'));
        $video = (array) $claims['video'];

        $this->assertSame('test-key', $claims['iss']);
        $this->assertSame('mbfd:sta1', $claims['sub']);
        $this->assertSame('Station 1', $claims['name']);
        $this->assertSame('{"join_as":"sta1"}', $claims['metadata']);
        $this->assertSame('opaque-room-name', $video['room']);
        $this->assertTrue($video['roomJoin']);
        $this->assertTrue($video['canPublish']);
        $this->assertTrue($video['canSubscribe']);
        $this->assertTrue($video['canPublishData']);
        $this->assertSame(
            ['camera', 'microphone', 'screen_share', 'screen_share_audio'],
            $video['canPublishSources'],
        );
        $this->assertArrayNotHasKey('roomAdmin', $video);
        $this->assertLessThanOrEqual(600, $claims['exp'] - time());
        $this->assertGreaterThan(590, $claims['exp'] - time());
    }

    public function test_active_cloud_profile_is_isolated_from_self_hosted_credentials(): void
    {
        config([
            'video-conferencing.livekit.profile' => 'cloud',
            'video-conferencing.livekit.profiles.cloud' => [
                'url' => 'wss://cloud.video.test.example',
                'api_url' => 'https://cloud.video.test.example',
                'api_key' => 'cloud-key',
                'api_secret' => 'cloud-secret-at-least-32-characters',
            ],
            'video-conferencing.livekit.profiles.self_hosted' => [
                'url' => 'wss://fallback.video.test.example',
                'api_url' => 'https://fallback.video.test.example',
                'api_key' => 'fallback-key',
                'api_secret' => 'fallback-secret-at-least-32-characters',
            ],
            'video-conferencing.livekit.token_ttl_seconds' => 600,
        ]);

        $issued = (new LiveKitConferenceProvider)->issueToken(
            'profile-room',
            'mbfd:sta3',
            'Station 3',
            '{"join_as":"sta3"}',
        );
        $claims = (array) JWT::decode(
            $issued->token,
            new Key('cloud-secret-at-least-32-characters', 'HS256'),
        );

        $this->assertSame('cloud-key', $claims['iss']);
        $this->assertSame('wss://cloud.video.test.example', (new LiveKitConferenceProvider)->clientUrl());
    }
}
