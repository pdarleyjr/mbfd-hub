<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Bid;

use Illuminate\Foundation\Http\FormRequest;

final class RevalidateBidIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'hub_user_id' => ['required', 'integer', 'min:1'],
            'security_version' => ['required', 'integer', 'min:1'],
            'member_id' => ['required', 'integer', 'min:1'],
            'name' => ['prohibited'],
            'email' => ['prohibited'],
            'rank' => ['prohibited'],
            'role' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
