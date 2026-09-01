<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\OperationalForms;

use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

final class ApplyFrocImportRequest extends FormRequest
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
        return array_merge(FrocImportSourceRules::rules(), [
            'revision' => ['required', 'integer', 'min:1'],
            'merge_mode' => ['required', 'in:fill_empty_and_append'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(FrocImportSourceRules::messages(), [
            'revision.required' => 'The draft revision is missing. Reload the form and try again.',
            'merge_mode.required' => 'The import merge mode is missing.',
            'idempotency_key.required' => 'The import idempotency key is missing.',
        ]);
    }
}
