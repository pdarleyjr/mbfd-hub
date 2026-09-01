<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Bid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AuthorizeBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $clientId = $this->string('client_id')->toString();
        $clients = (array) config('services.bid.authorization.clients', []);
        $callbacks = (array) data_get($clients, $clientId.'.callbacks', []);

        return [
            'client_id' => ['required', 'string', Rule::in(array_keys($clients))],
            'redirect_uri' => ['required', 'string', Rule::in($callbacks)],
            'state' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43,128}\z/'],
        ];
    }
}
