<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

use App\Models\Ems\KioskDevice;
use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Ems\EmsActor;
use App\Services\Ems\EmsException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Pairs, recognises and revokes supply room screens (docs/ems/supply-room-kiosk.md section 1). The screen is
 * trusted, never the member: a paired browser holds `{device id}.{secret}` in a long-lived cookie, and only the
 * secret's hash is stored. There is no timer expiry; Revoke ends it.
 */
final class KioskDevices
{
    public const COOKIE = 'mbfd_supply_kiosk';

    public const PAIRING_MINUTES = 30;

    /** Ten years: the screen never signs out on its own. */
    private const COOKIE_MINUTES = 60 * 24 * 365 * 10;

    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private const COOKIE_PATTERN = '/\A([1-9][0-9]{0,17})\.([a-f0-9]{64})\z/';

    public function __construct(private readonly EmsAccess $access) {}

    /** No 0/O, 1/I/L: a code read off one screen and typed on another. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    private const WRONG_CODE_KEY = 'supply-kiosk:wrong-pairing-codes';

    private const WRONG_CODES_PER_HOUR = 50;

    /**
     * A single-use way to pair one screen for the next 30 minutes: a short code to type on the screen, or a link.
     *
     * @return array{device: KioskDevice, url: string, code: string, expires_at: string}
     */
    public function createPairing(User $user, string $name): array
    {
        $this->authorize($user);
        $token = bin2hex(random_bytes(32));
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'Supply room screen';
        $device = KioskDevice::query()->create([
            'name' => $name,
            'pairing_token_hash' => hash('sha256', $token),
            'pairing_code_hash' => hash('sha256', $code),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_MINUTES),
            'created_by_user_id' => $user->getKey(),
            'created_by_name' => EmsActor::forUser($user, 'captain5')->name,
        ]);
        Log::info('Supply room screen pairing link created', ['device_id' => $device->id, 'by' => $user->getKey()]);

        return [
            'device' => $device, 'url' => url('/supply-kiosk/pair/'.$token), 'code' => substr($code, 0, 3).'-'.substr($code, 3),
            'expires_at' => $device->pairing_expires_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * Pair this browser with a code typed on the screen.
     *
     * @return array{device: KioskDevice, cookie: string}
     */
    public function pairWithCode(string $code): array
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $device = strlen($normalized) === self::CODE_LENGTH
            ? KioskDevice::query()->where('pairing_code_hash', hash('sha256', $normalized))->first()
            : null;

        try {
            return $this->complete($device);
        } catch (EmsException $exception) {
            $this->countWrongCode();
            throw $exception;
        }
    }

    /**
     * The route limits tries per address; this limits them everywhere. After too many wrong codes in an hour, every
     * open code is voided and an admin makes a new one.
     */
    private function countWrongCode(): void
    {
        RateLimiter::hit(self::WRONG_CODE_KEY, 3600);
        if (RateLimiter::attempts(self::WRONG_CODE_KEY) >= self::WRONG_CODES_PER_HOUR) {
            $voided = KioskDevice::query()->whereNull('paired_at')->whereNotNull('pairing_code_hash')->update(['pairing_code_hash' => null]);
            RateLimiter::clear(self::WRONG_CODE_KEY);
            Log::warning('Supply room screen pairing codes voided after repeated wrong codes', ['voided' => $voided]);
        }
    }

    /** The device a still-valid pairing link belongs to, or null. */
    public function pairable(string $token): ?KioskDevice
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }
        $device = KioskDevice::query()->where('pairing_token_hash', hash('sha256', $token))->first();

        return $device !== null && $device->paired_at === null && $device->revoked_at === null && $device->pairing_expires_at?->isFuture()
            ? $device : null;
    }

    /**
     * Pair this browser: the link is spent and a new secret is issued.
     *
     * @return array{device: KioskDevice, cookie: string}
     */
    public function pair(string $token): array
    {
        return $this->complete($this->pairable($token));
    }

    /** @return array{device: KioskDevice, cookie: string} */
    private function complete(?KioskDevice $found): array
    {
        return DB::transaction(function () use ($found): array {
            $device = $found === null ? null : KioskDevice::query()->lockForUpdate()->find($found->id);
            if ($device === null || $device->paired_at !== null || $device->revoked_at !== null || ! $device->pairing_expires_at?->isFuture()) {
                throw new EmsException('That pairing code or link has already been used or has ended. Make a new one from Admin > Medical Supply Room > Supply room screen.', 'pairing_link_ended', 410);
            }
            $secret = bin2hex(random_bytes(32));
            $device->forceFill([
                'secret_hash' => hash('sha256', $secret), 'paired_at' => now(), 'last_seen_at' => now(),
                'pairing_token_hash' => null, 'pairing_code_hash' => null, 'pairing_expires_at' => null,
            ])->save();
            Log::info('Supply room screen paired', ['device_id' => $device->id]);

            return ['device' => $device, 'cookie' => $device->id.'.'.$secret];
        });
    }

    public function cookie(string $value, bool $secure): Cookie
    {
        return cookie(self::COOKIE, $value, self::COOKIE_MINUTES, '/', null, $secure, true, false, 'strict');
    }

    /** The paired, unrevoked screen this cookie proves, or null. */
    public function authenticate(mixed $cookie): ?KioskDevice
    {
        if (! is_string($cookie) || preg_match(self::COOKIE_PATTERN, $cookie, $match) !== 1) {
            return null;
        }
        $device = KioskDevice::query()->find((int) $match[1]);
        if ($device === null || ! $device->isActive() || ! hash_equals((string) $device->secret_hash, hash('sha256', $match[2]))) {
            return null;
        }

        return $device;
    }

    /** The screen checked in: remember when, and clear an offline alert so the next silence alerts again. */
    public function seen(KioskDevice $device): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(30)) || $device->offline_alerted_at !== null) {
            KioskDevice::query()->whereKey($device->id)->update(['last_seen_at' => now(), 'offline_alerted_at' => null]);
        }
    }

    public function revoke(int $deviceId, User $user): void
    {
        $this->authorize($user);
        $changed = KioskDevice::query()->whereKey($deviceId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_by_name' => EmsActor::forUser($user, 'captain5')->name,
            'secret_hash' => null, 'pairing_token_hash' => null, 'pairing_code_hash' => null, 'pairing_expires_at' => null,
        ]);
        Log::info('Supply room screen revoked', ['device_id' => $deviceId, 'by' => $user->getKey(), 'changed' => $changed]);
    }

    public function rename(int $deviceId, string $name, User $user): void
    {
        $this->authorize($user);
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            throw new EmsException('Give the screen a name.', 'name_required', 422);
        }
        KioskDevice::query()->whereKey($deviceId)->update(['name' => $name]);
    }

    /**
     * Paired screens and still-open pairing links, active first, for the admin list.
     *
     * @return Collection<int, KioskDevice>
     */
    public function all(): Collection
    {
        return KioskDevice::query()->where(fn ($query) => $query->whereNotNull('paired_at')->orWhere('pairing_expires_at', '>', now()))
            ->orderByRaw('revoked_at is not null')->orderByDesc('id')->get();
    }

    public function canManage(?User $user): bool
    {
        return $this->access->canEditInventory($user);
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canEditInventory($user)) {
            throw EmsException::forbidden('Only Captain 5 or an administrator can pair or change a supply room screen.');
        }
    }
}
