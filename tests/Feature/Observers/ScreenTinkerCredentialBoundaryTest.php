<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ScreenTinkerCredentialBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.screentinker.sync_url' => 'https://screen-sync.invalid/api/users',
            'services.screentinker.sync_token' => 'test-token-fixture',
        ]);

        Role::findOrCreate('admin', 'web');
    }

    public function test_admin_password_change_never_sends_or_queues_the_human_password(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'downstream unavailable'], 503),
        ]);
        Queue::fake();

        $plaintext = 'Human-password-must-stay-in-Hub';
        $user = User::factory()->create();
        $user->assignRole('admin');

        $user->update(['password' => $plaintext]);

        self::assertTrue(Hash::check($plaintext, $user->fresh()->password));
        self::assertStringNotContainsString($plaintext, serialize($user));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_role_attachment_after_user_creation_does_not_send_the_human_password(): void
    {
        Http::fake();

        $plaintext = 'Another-human-password-that-stays-local';
        $user = User::create([
            'name' => 'New Admin',
            'email' => 'new-admin@example.test',
            'password' => $plaintext,
        ]);

        $user->assignRole('admin');

        self::assertTrue(Hash::check($plaintext, $user->password));
        self::assertStringNotContainsString($plaintext, serialize($user));
        Http::assertNothingSent();
    }

    public function test_password_capture_and_screentinker_observer_classes_are_removed(): void
    {
        self::assertFalse(Schema::hasColumn('users', 'plain_password'));
        self::assertFileDoesNotExist(app_path('Casts/HashedAndCaptured.php'));
        self::assertFileDoesNotExist(app_path('Observers/SyncToScreentinker.php'));
    }
}
