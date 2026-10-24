<?php

declare(strict_types=1);

namespace App\Services\Ems;

use Illuminate\Support\Facades\Validator;

/** Validation that fails with a plain-language EmsException instead of the framework default. */
final class EmsValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed> the validated data
     */
    public static function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = Validator::make($data, $rules, $messages, $attributes);
        if ($validator->fails()) {
            $errors = $validator->errors();

            throw new EmsException((string) $errors->first(), 'validation_failed', 422, $errors->toArray());
        }

        return $validator->validated();
    }
}
