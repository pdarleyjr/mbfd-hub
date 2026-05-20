<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\HashedAndCaptured;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use WeakMap;

class HashedAndCapturedTest extends TestCase
{
    use RefreshDatabase;

    public function test_plaintext_password_is_captured_in_weakmap_keyed_by_model(): void
    {
        $user = User::factory()->make();
        $user->password = 'Penco3';

        $captured = HashedAndCaptured::captured();
        $this->assertTrue(isset($captured[$user]));
        $this->assertSame('Penco3', $captured[$user]);
    }

    public function test_plaintext_password_is_hashed_for_persistence(): void
    {
        $user = User::factory()->make();
        $user->password = 'Penco3';

        $stored = $user->getRawOriginal('password') ?? $user->getAttributes()['password'] ?? null;
        $this->assertNotNull($stored);
        $this->assertNotSame('Penco3', $stored, 'password attribute must be hashed, never stored as plaintext');
        $this->assertTrue(Hash::check('Penco3', $stored), 'hashed value must verify against the plaintext');
    }

    public function test_pre_hashed_value_is_passed_through_without_re_hashing(): void
    {
        $alreadyHashed = Hash::make('SomethingElse');

        $user = User::factory()->make();
        $user->password = $alreadyHashed;

        $stored = $user->getAttributes()['password'] ?? null;
        $this->assertSame($alreadyHashed, $stored, 'an already-hashed value must not be re-hashed');
    }

    public function test_pre_hashed_value_does_not_get_captured_as_plaintext(): void
    {
        $user = User::factory()->make();
        $user->password = Hash::make('whatever');

        $captured = HashedAndCaptured::captured();
        $this->assertFalse(
            isset($captured[$user]),
            'capture should only happen for plaintext assignments, never for incoming hashes'
        );
    }

    public function test_empty_string_does_not_capture_or_hash(): void
    {
        $user = User::factory()->make();
        $user->password = '';

        $captured = HashedAndCaptured::captured();
        $this->assertFalse(isset($captured[$user]));
        $this->assertSame('', $user->getAttributes()['password'] ?? null);
    }
}
