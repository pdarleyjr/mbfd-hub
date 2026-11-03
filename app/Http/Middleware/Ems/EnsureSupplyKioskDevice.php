<?php

declare(strict_types=1);

namespace App\Http\Middleware\Ems;

use App\Services\Ems\Kiosk\KioskDevices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every supply room screen API call must come from a paired, unrevoked screen (docs/ems/supply-room-kiosk.md
 * section 1). There is no member sign-in; the screen is what is trusted.
 */
final class EnsureSupplyKioskDevice
{
    public const ATTRIBUTE = 'supply_kiosk_device';

    public function __construct(private readonly KioskDevices $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie(KioskDevices::COOKIE);
        $device = $this->devices->authenticate($cookie);
        if ($device === null) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'kiosk_not_paired',
                'message' => 'This screen is not paired. An admin pairs it from Admin > Medical Supply Room > Pair supply room screen.',
                'fields' => (object) [],
            ]], 401, ['Cache-Control' => 'private, no-store']);
        }
        $this->devices->seen($device);
        $request->attributes->set(self::ATTRIBUTE, $device);
        $response = $next($request);
        // Browsers cap a cookie's life at about 400 days, so each bootstrap (every 5 minutes) renews it: the screen
        // stays paired for as long as it runs.
        if ($request->isMethod('GET') && is_string($cookie)) {
            $response->headers->setCookie($this->devices->cookie($cookie, $request->isSecure() || app()->environment('production')));
        }

        return $response;
    }
}
