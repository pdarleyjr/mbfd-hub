<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Bid;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request shape for POST /api/v2/verify-credentials.
 *
 * The mbfd-bid Cloudflare Worker calls this endpoint with the member's
 * employee_id + portal password to bridge login from the bid site to the
 * Employee Portal's identity store. Authorization is enforced by the
 * bearer-token middleware on the route; this class validates the body only.
 */
class VerifyCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by the verify.bid.reader middleware on
        // the route definition (see routes/api.php). FormRequest just
        // validates shape.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'min:1', 'max:32'],
            'password' => ['required', 'string', 'min:1', 'max:200'],
        ];
    }
}
