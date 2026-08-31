<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserPasswordHashingTest extends TestCase
{
    public function test_user_password_uses_laravels_one_way_hashed_cast(): void
    {
        $user = new User;

        self::assertSame('hashed', $user->getCasts()['password']);
        self::assertNotContains('plain_password', $user->getHidden());
        self::assertFileDoesNotExist(app_path('Casts/HashedAndCaptured.php'));
    }

    public function test_plaintext_password_is_hashed_and_never_serialized(): void
    {
        $plaintext = 'Unique-human-password-not-for-egress';
        $user = User::factory()->make();

        $user->password = $plaintext;

        $stored = $user->getAttributes()['password'] ?? null;

        self::assertIsString($stored);
        self::assertNotSame($plaintext, $stored);
        self::assertTrue(Hash::check($plaintext, $stored));
        self::assertStringNotContainsString($plaintext, serialize($user));
        self::assertStringNotContainsString($plaintext, serialize($user->toArray()));
    }

    public function test_pre_hashed_password_is_not_hashed_again(): void
    {
        $alreadyHashed = Hash::make('Existing-password');
        $user = User::factory()->make();

        $user->password = $alreadyHashed;

        self::assertSame($alreadyHashed, $user->getAttributes()['password'] ?? null);
    }
}
