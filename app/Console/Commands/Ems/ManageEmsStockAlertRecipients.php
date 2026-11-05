<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Ems\StockAlertRecipients;
use Illuminate\Console\Command;

/**
 * Show or change the EMS low-stock alert list from the server. The Admin -> EMS -> Medical Supply Room page
 * has the same list for day-to-day changes.
 */
final class ManageEmsStockAlertRecipients extends Command
{
    protected $signature = 'ems:stock-alert-recipients
        {action=list : list, add or remove}
        {users?* : User ids}
        {--by= : Who asked for the change (recorded with each added person)}';

    protected $description = 'Show, add to or remove from the EMS low-stock alert list';

    public function handle(StockAlertRecipients $recipients, EmsAccess $access): int
    {
        $action = (string) $this->argument('action');
        $ids = array_map('intval', (array) $this->argument('users'));
        if (in_array($action, ['add', 'remove'], true)) {
            if ($ids === [] || trim((string) $this->option('by')) === '') {
                $this->error('Give at least one user id and --by="who asked for this".');

                return self::FAILURE;
            }
            $missing = array_diff($ids, User::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all());
            if ($missing !== []) {
                $this->error('No such user: '.implode(', ', $missing));

                return self::FAILURE;
            }
            $by = trim((string) $this->option('by'));
            $result = $action === 'add' ? $recipients->add($ids, null, $by) : $recipients->remove($ids, null, $by);
            $this->info('Added: '.($result['added'] === [] ? 'none' : implode(', ', $result['added'])).'. Removed: '.($result['removed'] === [] ? 'none' : implode(', ', $result['removed'])).'.');
        } elseif ($action !== 'list') {
            $this->error('Use list, add or remove.');

            return self::FAILURE;
        }

        $this->table(['User', 'Name', 'Can read supply room', 'Added by', 'Added'], $recipients->all()->map(fn ($row): array => [
            // A list row is deleted with its user (cascade), so every row has one.
            $row->user_id, $row->user->name, $access->canReadInventory($row->user) ? 'yes' : 'NO, will not be alerted',
            $row->added_by_name, $row->created_at->toDateTimeString(),
        ])->all());

        return self::SUCCESS;
    }
}
