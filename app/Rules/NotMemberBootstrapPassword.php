<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Identity\MemberBootstrapCredential;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class NotMemberBootstrapPassword implements ValidationRule
{
    public function __construct(private MemberBootstrapCredential $credential) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->credential->matches($value)) {
            $fail('Choose a private password that is different from the initial password.');
        }
    }
}
