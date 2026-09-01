<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\SessionContextClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Default D01 issuance policy. Request headers are not trusted as device
 * posture, so every session remains unmanaged until a later enrollment
 * implementation explicitly replaces this service.
 */
class CanonicalSessionPolicy
{
    /** @return array{context_class: SessionContextClass, idle_expires_at: CarbonImmutable, absolute_expires_at: CarbonImmutable} */
    public function resolve(Request $request, CarbonImmutable $issuedAt): array
    {
        return [
            'context_class' => SessionContextClass::UnmanagedBrowser,
            'idle_expires_at' => $issuedAt->addSeconds(max(
                60,
                (int) config('security.canonical_session.unmanaged_idle_seconds', 3600),
            )),
            'absolute_expires_at' => $issuedAt->addSeconds(max(
                300,
                (int) config('security.canonical_session.unmanaged_absolute_seconds', 86400),
            )),
        ];
    }
}
