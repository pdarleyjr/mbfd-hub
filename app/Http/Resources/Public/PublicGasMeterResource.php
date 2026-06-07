<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted view of a SingleGasMeter for the unauthenticated
 * daily-checkout SPA.
 *
 * SECURITY (H-02): the full serial number is sensitive and is never emitted.
 * A masked serial (last 4 chars only) is provided so the UI can still show a
 * partial identifier without exposing the full value.
 */
class PublicGasMeterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial_number' => $this->maskedSerial(),
            'activation_date' => $this->activation_date,
            'expiration_date' => $this->expiration_date,
            'status' => $this->status,
            'days_until_expiration' => $this->daysUntilExpiration(),
            'apparatus_name' => $this->apparatus?->designation
                ?: $this->apparatus?->name
                ?: $this->apparatus?->unit_id
                ?: 'Unassigned',
        ];
    }

    private function maskedSerial(): ?string
    {
        $serial = (string) ($this->serial_number ?? '');

        if ($serial === '') {
            return null;
        }

        $last4 = substr($serial, -4);

        return '••••'.$last4;
    }
}
