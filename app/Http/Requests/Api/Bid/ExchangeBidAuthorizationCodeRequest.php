<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Bid;

use Illuminate\Foundation\Http\FormRequest;

final class ExchangeBidAuthorizationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:512'],
        ];
    }
}
