<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * Administrator actions on an import, with plain results for the admin screen. The authorization and
 * audit live in the services this calls, so nothing here is the only guard.
 */
final class RosterImportActions
{
    public function __construct(
        private readonly RosterImportOperations $operations,
        private readonly RosterRollbackService $rollback,
    ) {}

    public function canRetry(RosterImport $import): bool
    {
        return $this->operations->canRetry($import);
    }

    public function canRetryAttested(RosterImport $import): bool
    {
        return $this->operations->canRetryAttested($import);
    }

    public function canRetryCleanup(RosterImport $import): bool
    {
        return $this->operations->canRetryCleanup($import);
    }

    public function canActivate(RosterImport $import): bool
    {
        if ($import->roster_snapshot_id === null || $import->duty_date === null) {
            return false;
        }
        $pointer = $this->pointer($import);

        return $pointer === null || (int) $pointer->roster_snapshot_id !== (int) $import->roster_snapshot_id;
    }

    public function canRelease(RosterImport $import): bool
    {
        return $import->duty_date !== null && $this->pointer($import)?->pinned_at !== null;
    }

    public function retry(RosterImport $import, ?User $actor): void
    {
        $this->run('Import sent back for processing', 'The import could not be retried. It was left as it is.', fn () => $this->operations->retry($import, $actor));
    }

    public function retryAttested(RosterImport $import, ?User $actor, string $reason): void
    {
        $this->run('Import confirmed and sent back for processing', 'The import could not be retried. It was left as it is.', fn () => $this->operations->retryAttested($import, $actor, $reason));
    }

    public function retryCleanup(RosterImport $import, ?User $actor): void
    {
        $this->run('Email cleanup sent back for processing', 'The email cleanup could not be retried. It was left as it is.', fn () => $this->operations->retryCleanup($import, $actor));
    }

    public function activate(RosterImport $import, ?User $actor, string $reason): void
    {
        $this->run('Members now see this version', 'That version could not be shown. Members still see the same roster.', function () use ($import, $actor, $reason): void {
            if (! $actor instanceof User) {
                throw new AuthorizationException('roster_rollback_forbidden');
            }
            $this->rollback->activate($actor, $import->duty_date->format('Y-m-d'), (int) $import->roster_snapshot_id, $reason);
        });
    }

    public function release(RosterImport $import, ?User $actor, string $reason): void
    {
        $this->run('Duty date released', 'The duty date could not be released. It is still pinned.', function () use ($import, $actor, $reason): void {
            if (! $actor instanceof User) {
                throw new AuthorizationException('roster_rollback_forbidden');
            }
            $this->rollback->release($actor, $import->duty_date->format('Y-m-d'), $reason);
        });
    }

    private function pointer(RosterImport $import): ?RosterActiveVersion
    {
        return RosterActiveVersion::query()->whereDate('duty_date', $import->duty_date->format('Y-m-d'))->first();
    }

    private function run(string $success, string $failure, callable $action): void
    {
        try {
            $action();
            Notification::make()->success()->title($success)->send();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('You do not have permission to do that.')->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title($failure)->send();
        }
    }
}
