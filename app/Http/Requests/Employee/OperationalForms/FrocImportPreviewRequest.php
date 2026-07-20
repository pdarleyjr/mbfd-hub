<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\OperationalForms;

use Illuminate\Foundation\Http\FormRequest;

final class FrocImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('employee') !== null;
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
