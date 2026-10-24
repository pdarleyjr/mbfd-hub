<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Ems\EmsException;
use App\Services\Ems\Idempotency;
use Closure;
use Filament\Actions\MountableAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the Admin -> EMS screens. Every write goes through an App\Services\Ems
 * service, which checks EmsAccess again. This trait adds the server-side check at the Filament
 * edge (hiding a button is cosmetic) and turns a service refusal into a plain message that keeps
 * the modal open.
 */
trait RunsEmsActions
{
    /** The signed-in person, refused with 403 unless EmsAccess allows the ability (view, act or configure). */
    protected function emsActor(string $ability = 'act'): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(EmsAccess::class)->allows($user, $ability), 403);

        return $user;
    }

    protected function emsCan(string $ability): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(EmsAccess::class)->allows($user, $ability);
    }

    /**
     * The idempotency key chosen when the modal opened, so a double click or retry is one write.
     *
     * @param  array<string, mixed>  $data
     */
    protected function emsKey(array $data): string
    {
        $key = (string) ($data['idempotency_key'] ?? '');

        return preg_match(Idempotency::KEY_PATTERN, $key) === 1 ? $key : (string) Str::uuid();
    }

    /**
     * Run a service call as the signed-in person.
     *
     * @param  Closure(User): mixed  $work
     * @return mixed the closure's return value
     */
    protected function emsRun(MountableAction $action, string $ability, Closure $work, string $successTitle, ?string $successBody = null): mixed
    {
        $user = $this->emsActor($ability);
        try {
            $result = $work($user);
        } catch (EmsException $exception) {
            if ($exception->status === 403) {
                abort(403);
            }
            Notification::make()->danger()->title('Nothing was changed')->body($exception->getMessage())->send();
            $action->halt();

            return null;
        }

        $notification = Notification::make()->success()->title($successTitle);
        if ($successBody !== null) {
            $notification->body($successBody);
        }
        $notification->send();

        return $result;
    }
}
