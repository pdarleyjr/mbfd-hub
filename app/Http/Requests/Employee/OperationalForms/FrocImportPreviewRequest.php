<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\OperationalForms;

use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

final class FrocImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        try {
            app(AuthenticatedMemberContextResolver::class)->resolve($this)->actor()->requireEmployee();

            return true;
        } catch (AuthenticationException|AuthorizationException) {
            return false;
        }
    }

    public function rules(): array
    {
        return FrocImportSourceRules::rules();
    }

    public function messages(): array
    {
        return FrocImportSourceRules::messages();
    }
}
