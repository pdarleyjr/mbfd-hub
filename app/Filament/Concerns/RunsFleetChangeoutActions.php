<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Fleet\ChangeoutConflictException;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Runs one Fleet action on a changeout or alert page. All rules live in the services, so this only passes the
 * signed-in user along, reports the outcome in plain words, and redraws the page from the database.
 */
trait RunsFleetChangeoutActions
{
    /** @param Closure(User): mixed $work */
    private function runFleetAction(Closure $work, string $success, ?string $body = null): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $work($actor);
        } catch (ChangeoutConflictException $conflict) {
            Notification::make()->warning()->title('Nothing was changed')->body($conflict->getMessage())->persistent()->send();
            $this->getRecord()->refresh();

            return;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Nothing was changed')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))->persistent()->send();

            return;
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('You do not have permission to do this.')->body($exception->getMessage() ?: null)->send();

            return;
        }

        $this->getRecord()->refresh();
        Notification::make()->success()->title($success)->body($body)->send();
    }
}
