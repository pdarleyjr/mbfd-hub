<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\OperationalForms;

use App\Services\OperationalForms\FrocImportLimits;

/**
 * Shared validation rules for the F-ROC AI import source, used by both the
 * preview and the record-scoped apply endpoints so the two paths can never
 * drift. Upload size limits are read from configuration (never hard-coded)
 * and clamped to safe operational ranges.
 */
final class FrocImportSourceRules
{
    public static function rules(): array
    {
        $maxKb = FrocImportLimits::uploadMaxKilobytes();

        return [
            'unit_id' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN][\pL\pN .\/_-]*$/u'],
            'notes' => ['nullable', 'string', 'max:524288', 'required_without:notes_file'],
            'notes_file' => [
                'nullable',
                'file',
                'max:'.$maxKb,
                'required_without:notes',
                function (string $attribute, $value, callable $fail): void {
                    if (! ($value instanceof \Illuminate\Http\UploadedFile)) {
                        return;
                    }
                    $extension = strtolower((string) $value->getClientOriginalExtension());
                    if (! in_array($extension, ['zip', 'txt'], true)) {
                        $fail('Upload a WhatsApp .zip or plain-text .txt export. PDF, Word, image, and video files are not analyzed by this importer.');
                    }
                },
            ],
        ];
    }

    public static function messages(): array
    {
        $megabytes = (int) round(FrocImportLimits::uploadMaxBytes() / (1024 * 1024));

        return [
            'notes_file.max' => "The selected file is larger than the {$megabytes} MB import limit.",
            'notes_file.file' => 'Upload a WhatsApp .zip or plain-text .txt export. PDF, Word, image, and video files are not analyzed by this importer.',
            'notes_file.required_without' => 'Provide activity notes to import or upload a .txt or .zip file.',
            'unit_id.required' => 'Enter the unit designation, for example R6, JHAT, or Gator 1.',
            'unit_id.regex' => 'Enter a valid unit designation using letters, numbers, spaces, periods, slashes, underscores, or hyphens.',
        ];
    }
}
