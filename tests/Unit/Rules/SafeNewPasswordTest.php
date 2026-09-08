<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\SafeNewPassword;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SafeNewPasswordTest extends TestCase
{
    #[DataProvider('passwordInputs')]
    public function test_new_password_safety_respects_hash_bytes_without_changing_policy(string $driver, mixed $password, bool $passes): void
    {
        config(['hashing.driver' => $driver]);
        $validator = Validator::make(['password' => $password], ['password' => [new SafeNewPassword]]);

        self::assertSame($passes, $validator->passes());
        if (! $passes) {
            self::assertNotEmpty($validator->errors()->get('password'));
        }
    }

    public static function passwordInputs(): array
    {
        return [
            '73 ASCII bytes rejected' => ['bcrypt', str_repeat('a', 73), false],
            '37 Unicode characters are 74 bytes' => ['bcrypt', str_repeat('é', 37), false],
            'NUL rejected' => ['bcrypt', "password\0suffix", false],
            '72 ASCII bytes accepted' => ['bcrypt', str_repeat('a', 72), true],
            '36 Unicode characters are 72 bytes' => ['bcrypt', str_repeat('é', 36), true],
            'rule does not replace minimum policy' => ['bcrypt', 'short', true],
            'other configured algorithm has no bcrypt ceiling' => ['argon2id', str_repeat('a', 73), true],
            'NUL rejected independently of algorithm' => ['argon2id', "password\0suffix", false],
            'nonstring rejected safely' => ['bcrypt', ['password'], false],
        ];
    }
}
