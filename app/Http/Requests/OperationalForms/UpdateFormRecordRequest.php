<?php

namespace App\Http\Requests\OperationalForms;

use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFormRecordRequest extends FormRequest
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
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'data' => ['required', 'array'],
        ];
    }
}
