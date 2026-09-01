<?php

namespace App\Http\Requests\OperationalForms;

use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormRecordRequest extends FormRequest
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
            'form_type' => ['required', Rule::in(['ics_214', 'froc_log_001_ff'])],
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
