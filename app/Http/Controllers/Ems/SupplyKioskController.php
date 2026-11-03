<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Http\Middleware\Ems\EnsureSupplyKioskDevice;
use App\Models\Ems\KioskDevice;
use App\Services\Ems\EmsException;
use App\Services\Ems\Kiosk\KioskCatalog;
use App\Services\Ems\Kiosk\KioskDevices;
use App\Services\Ems\Kiosk\KioskWithdrawals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** The supply room screen: its page, one-time pairing, and the device-only API (docs/ems/supply-room-kiosk.md). */
final class SupplyKioskController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly KioskDevices $devices,
        private readonly KioskCatalog $catalog,
        private readonly KioskWithdrawals $withdrawals,
    ) {}

    public function page(): View
    {
        return view('supply-kiosk.kiosk');
    }

    public function pairPage(string $token): Response
    {
        $device = $this->devices->pairable($token);

        return response()->view('supply-kiosk.pair', ['device' => $device, 'token' => $token], $device === null ? 410 : 200)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function pair(Request $request, string $token): RedirectResponse|Response
    {
        try {
            $paired = $this->devices->pair($token);
        } catch (EmsException $exception) {
            return response()->view('supply-kiosk.pair', ['device' => null, 'token' => $token], $exception->status)->header('Cache-Control', 'private, no-store');
        }

        return redirect('/supply-kiosk')->withCookie($this->devices->cookie($paired['cookie'], $request->isSecure() || app()->environment('production')));
    }

    /** Pair this browser with the short code from Admin > Medical Supply Room, typed on the screen itself. */
    public function pairWithCode(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $code = (string) $request->input('code', '');
            if (strlen($code) > 20) {
                throw new EmsException('That pairing code is not right. Check it and try again.', 'pairing_link_ended', 422);
            }
            $paired = $this->devices->pairWithCode($code);

            return $this->ok(['device' => ['name' => $paired['device']->name]])
                ->withCookie($this->devices->cookie($paired['cookie'], $request->isSecure() || app()->environment('production')));
        });
    }

    public function bootstrap(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->catalog->bootstrap($this->device($request))));
    }

    public function withdraw(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->withdrawals->take($this->device($request), $request->all()), creates: true));
    }

    public function undo(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->withdrawals->undo($this->device($request), $uuid)));
    }

    public function heartbeat(): JsonResponse
    {
        return $this->ok(['at' => now()->toIso8601String()]);
    }

    private function device(Request $request): KioskDevice
    {
        $device = $request->attributes->get(EnsureSupplyKioskDevice::ATTRIBUTE);
        if (! $device instanceof KioskDevice) {
            throw new EmsException('This screen is not paired.', 'kiosk_not_paired', 401);
        }

        return $device;
    }
}
