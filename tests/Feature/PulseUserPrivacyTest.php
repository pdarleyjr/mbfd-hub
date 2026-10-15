<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pulse\Contracts\ResolvesUsers;
use Tests\TestCase;

/**
 * Pulse's default user resolver builds Gravatar URLs from hashed member
 * email addresses, sending them to a third party whenever the dashboard
 * renders. Hub users are resolved without an external avatar.
 */
final class PulseUserPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_user_cards_do_not_request_external_avatars(): void
    {
        $user = User::factory()->create(['name' => 'Pulse Privacy Member', 'email' => 'pulse-privacy@example.test']);

        $resolved = app(ResolvesUsers::class)->load(collect([$user->id]))->find($user->id);

        $this->assertSame('Pulse Privacy Member', $resolved->name);
        $this->assertStringNotContainsString('gravatar', (string) ($resolved->avatar ?? ''));
    }
}
