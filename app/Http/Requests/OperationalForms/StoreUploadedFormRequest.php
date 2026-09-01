<?php

namespace App\Http\Requests\OperationalForms;

use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

class StoreUploadedFormRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:'.config('operational-forms.upload_max_kilobytes', 51200)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The file may not be larger than '.config('operational-forms.upload_max_megabytes', 50).' MB.',
        ];
    }
}
