<?php

namespace Tests\Feature\VideoConferencing;

use Tests\TestCase;

class ReverbConfigurationTest extends TestCase
{
    public function test_reverb_server_and_broadcaster_share_the_configured_application(): void
    {
        $this->assertSame('config', config('reverb.apps.provider'));
        $this->assertNotEmpty(config('reverb.apps.apps'));
        $this->assertSame(
            config('broadcasting.connections.reverb.app_id'),
            config('reverb.apps.apps.0.app_id'),
        );
        $this->assertSame(
            config('broadcasting.connections.reverb.key'),
            config('reverb.apps.apps.0.key'),
        );
        $this->assertSame(
            config('broadcasting.connections.reverb.secret'),
            config('reverb.apps.apps.0.secret'),
        );
    }
}
