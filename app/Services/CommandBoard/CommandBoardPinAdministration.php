<?php

declare(strict_types=1);

namespace App\Services\CommandBoard;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sets the Command Board's command PIN from the Hub. The PIN is hashed here
 * with bcrypt and only the hash leaves the Hub; neither the PIN nor the hash
 * is stored, logged, flashed, or written to the audit trail.
 *
 * Access: an active Super Administrator, or an active Hub administrator holding
 * the DIRECT app.cmd.admin capability. Role-inherited grants do not count.
 */
final class CommandBoardPinAdministration
{
    public const PERMISSION = 'app.cmd.admin';

    public const AUDIT_ACTION = 'command_board.pin.changed';

    public const METHOD_NEW_PIN = 'new_pin';

    public const METHOD_CAMERA_PIN = 'copied_300_camera_pin';

    public const PIN_PATTERN = '/^\d{4,8}$/';

    public const REASON_MAX_LENGTH = 255;

    /** Inside the board's accepted bcrypt cost range of 10-14. */
    private const BCRYPT_ROUNDS = 12;

    private const ACCEPTED_HASH = '/^\$2[by]\$1[0-4]\$[.\/A-Za-z0-9]{53}$/';

    private const REAUTH_LIMIT_KEY = 'command-board-pin:reauth:';

    private const REAUTH_MAX_ATTEMPTS = 5;

    private const REAUTH_DECAY_SECONDS = 300;

    public function __construct(
        private readonly CommandBoardPinClient $client,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    public function canAdminister(User $user): bool
    {
        return $user->isAuthenticationAllowed()
            && ! $user->must_change_password
            && $user->hasCurrentAdminPanelEntitlement()
            && ($user->hasRole('super_admin') || $user->hasDirectWebPermission(self::PERMISSION));
    }

    public function isLinkConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function status(): CommandBoardPinStatus
    {
        return $this->client->status();
    }

    /** Why the 300 camera PIN cannot be copied right now, or null when it can. */
    public function cameraPinUnavailableReason(): ?string
    {
        $hash = config('video-conferencing.command_pin_hash');
        if (! is_string($hash) || trim($hash) === '') {
            return 'The 300 camera PIN is not set in the Hub (VIDEO_CONFERENCING_COMMAND_PIN_HASH is empty), so there is nothing to copy.';
        }

        return preg_match(self::ACCEPTED_HASH, trim($hash)) === 1
            ? null
            : 'The 300 camera PIN is stored in a format the Command Board does not accept (it needs a bcrypt hash with cost 10 to 14), so it cannot be copied.';
    }

    public function setNewPin(User $actor, string $pin, string $currentPassword, string $reason): CommandBoardPinChange
    {
        return $this->push($actor, self::METHOD_NEW_PIN, $currentPassword, $reason, function () use ($pin): string {
            if (preg_match(self::PIN_PATTERN, $pin) !== 1) {
                throw ValidationException::withMessages(['pin' => 'The PIN must be 4 to 8 digits.']);
            }

            return Hash::driver('bcrypt')->make($pin, ['rounds' => self::BCRYPT_ROUNDS]);
        });
    }

    /** One-time copy: afterwards the Command Board PIN and the 300 camera PIN change independently. */
    public function copyCameraPin(User $actor, string $currentPassword, string $reason): CommandBoardPinChange
    {
        return $this->push($actor, self::METHOD_CAMERA_PIN, $currentPassword, $reason, function (): string {
            $unavailable = $this->cameraPinUnavailableReason();
            if ($unavailable !== null) {
                throw ValidationException::withMessages(['camera_pin' => $unavailable]);
            }

            return trim((string) config('video-conferencing.command_pin_hash'));
        });
    }

    /**
     * @param  Closure(): string  $pinHash  Resolves the bcrypt hash to send; runs only after re-authentication.
     */
    private function push(User $actor, string $method, string $currentPassword, string $reason, Closure $pinHash): CommandBoardPinChange
    {
        $reason = trim($reason);
        $current = $this->reauthenticate($actor, $method, $currentPassword, $reason);
        $hash = $pinHash();

        try {
            $status = $this->client->replace($hash, [
                'sub' => 'hub-user:'.$current->getKey(),
                'name' => (string) ($current->display_name ?: $current->name),
            ]);
        } catch (CommandBoardRequestFailed $exception) {
            $this->recordAfterBoardCall($current, 'failed', $reason, [
                'method' => $method, 'error' => $exception->kind, 'status' => $exception->status,
            ]);

            throw $exception;
        }

        $audited = $this->recordAfterBoardCall($current, 'allowed', $reason, [
            'method' => $method, 'pin_version' => $status->pinVersion, 'source' => $status->source,
        ]);

        return new CommandBoardPinChange($status, $audited);
    }

    /**
     * Once the board has answered, a broken audit store must not change what
     * the admin is told about the PIN. The failure is logged without the PIN,
     * the hash, or the exception message (which may echo SQL bindings).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordAfterBoardCall(User $actor, string $result, string $reason, array $metadata): bool
    {
        try {
            $this->audit->record($actor, $actor, self::AUDIT_ACTION, $result, $reason, $metadata);

            return true;
        } catch (Throwable $exception) {
            Log::error('command_board.pin.audit_failed', [
                'actor_user_id' => $actor->getKey(),
                'result' => $result,
                'metadata' => $metadata,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function reauthenticate(User $actor, string $method, string $currentPassword, string $reason): User
    {
        try {
            $current = $actor->fresh();
            if (! $current instanceof User || ! $this->canAdminister($current)) {
                throw new AuthorizationException('Only a Super Administrator or a Hub administrator with the Command Board PIN capability can change the Command Board PIN.');
            }
            if (! $this->client->isConfigured()) {
                throw CommandBoardRequestFailed::notConfigured();
            }
            if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
                throw ValidationException::withMessages(['reason' => 'Give a reason of up to '.self::REASON_MAX_LENGTH.' characters.']);
            }
            $attempts = self::REAUTH_LIMIT_KEY.$current->getKey();
            if (RateLimiter::tooManyAttempts($attempts, self::REAUTH_MAX_ATTEMPTS)) {
                throw ValidationException::withMessages(['current_password' => 'Too many incorrect password attempts. Try again in '.RateLimiter::availableIn($attempts).' seconds.']);
            }
            if ($currentPassword === '' || ! Hash::check($currentPassword, $current->getAuthPassword())) {
                RateLimiter::hit($attempts, self::REAUTH_DECAY_SECONDS);

                throw new CurrentPasswordMismatch('The current password is incorrect.');
            }
            RateLimiter::clear($attempts);
        } catch (AuthorizationException $exception) {
            $this->audit->record($actor, $actor, self::AUDIT_ACTION, 'denied', null, ['method' => $method]);

            throw $exception;
        }

        return $current;
    }
}
