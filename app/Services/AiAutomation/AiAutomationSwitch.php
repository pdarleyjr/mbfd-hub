<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Models\AiAutomation\AiAutomationSetting;
use App\Models\AiAutomation\AiAutomationSwitchEvent;
use App\Models\AiAutomation\AiBacklogRun;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one master switch. OFF stops every automated acknowledgment, reply, backlog batch and model call this
 * feature makes. State lives in the database (deploys clear caches); every ON/OFF bumps the epoch so work
 * queued under an earlier setting can never post, even if the switch is turned back on.
 */
final class AiAutomationSwitch
{
    public function hardDisabled(): bool
    {
        return (bool) config('ai_automation.hard_disabled', false);
    }

    /** @phpstan-impure reads the live database row every call */
    public function isOn(): bool
    {
        if ($this->hardDisabled()) {
            return false;
        }

        $setting = $this->setting();

        return $setting !== null && $setting->enabled;
    }

    /**
     * The epoch to stamp on new work, read in the same query as the ON check; null while OFF.
     *
     * @phpstan-impure
     */
    public function currentEpochIfOn(): ?int
    {
        if ($this->hardDisabled()) {
            return null;
        }
        $setting = $this->setting();

        return $setting !== null && $setting->enabled ? (int) $setting->epoch : null;
    }

    /** @phpstan-impure */
    public function epoch(): int
    {
        $setting = $this->setting();

        return $setting === null ? 0 : (int) $setting->epoch;
    }

    /**
     * True when the switch is ON and still in the epoch the work was queued under.
     *
     * @phpstan-impure reads the live database row every call
     */
    public function allows(int $epoch): bool
    {
        if ($this->hardDisabled()) {
            return false;
        }

        $setting = $this->setting();

        return $setting !== null && $setting->enabled && (int) $setting->epoch === $epoch;
    }

    /**
     * Call inside the transaction that writes a member-visible record. Takes a row lock on the switch so an
     * OFF either commits first (and this returns false) or waits until this write commits.
     *
     * @phpstan-impure
     */
    public function allowsUnderLock(int $epoch): bool
    {
        if ($this->hardDisabled()) {
            return false;
        }

        $setting = AiAutomationSetting::query()->orderBy('id')->lockForUpdate()->first();

        return $setting !== null && $setting->enabled && (int) $setting->epoch === $epoch;
    }

    /** @return array{changed: bool, suppressed: int} */
    public function turnOff(?User $actor, string $source = 'admin'): array
    {
        return $this->set(false, $actor, $source);
    }

    /** @return array{changed: bool, suppressed: int} */
    public function turnOn(?User $actor, string $source = 'admin'): array
    {
        return $this->set(true, $actor, $source);
    }

    /** @return array{changed: bool, suppressed: int} */
    private function set(bool $enabled, ?User $actor, string $source): array
    {
        return DB::transaction(function () use ($enabled, $actor, $source): array {
            $setting = AiAutomationSetting::query()->orderBy('id')->lockForUpdate()->first()
                ?? AiAutomationSetting::query()->create(['enabled' => false, 'epoch' => 1]);

            if ($setting->enabled === $enabled) {
                return ['changed' => false, 'suppressed' => 0];
            }

            $from = $setting->enabled;
            $setting->forceFill([
                'enabled' => $enabled,
                'epoch' => $setting->epoch + 1,
                'updated_by_user_id' => $actor?->getKey(),
            ])->save();

            $suppressed = 0;
            if (! $enabled) {
                // Queued and in-flight work becomes inert now; it is never revived by a later ON.
                $suppressed = AiIntakeEvent::query()
                    ->whereIn('state', AiIntakeEvent::OPEN_STATES)
                    ->update([
                        'state' => AiIntakeEvent::STATE_CANCELLED,
                        'last_error' => 'switched_off',
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);

                AiBacklogRun::query()
                    ->where('state', AiBacklogRun::STATE_RUNNING)
                    ->update(['state' => AiBacklogRun::STATE_PAUSED, 'updated_at' => now()]);
            }

            AiAutomationSwitchEvent::query()->create([
                'from_enabled' => $from,
                'to_enabled' => $enabled,
                'epoch' => $setting->epoch,
                'suppressed_count' => $suppressed,
                'actor_user_id' => $actor?->getKey(),
                'actor_name' => $actor !== null ? (string) ($actor->name ?? 'Administrator') : 'Server console',
                'source' => $source,
                'created_at' => now(),
            ]);

            return ['changed' => true, 'suppressed' => $suppressed];
        });
    }

    private function setting(): ?AiAutomationSetting
    {
        return AiAutomationSetting::query()->orderBy('id')->first();
    }
}
