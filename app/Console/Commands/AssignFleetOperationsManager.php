<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\FleetOperationsManagerAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator path for the same one-switch assignment the Employees & Access
 * page offers, attributed in the security audit to a named Super Administrator.
 */
final class AssignFleetOperationsManager extends Command
{
    protected $signature = 'mbfd:fleet-operations-manager
                            {employee_id : Employee ID of the member}
                            {--actor= : Employee ID of the Super Administrator authorizing the change}
                            {--reason= : Why the change is being made (recorded in the audit)}
                            {--remove : Remove the assignment instead of granting it}
                            {--dry-run : Show what would change without changing anything}';

    protected $description = 'Assign or remove the Fleet Operations Manager role as one audited change';

    public function handle(FleetOperationsManagerAssignment $assignment): int
    {
        $reason = trim((string) $this->option('reason'));
        $target = User::query()->where('employee_id', (string) $this->argument('employee_id'))->get();
        $actor = User::query()->where('employee_id', (string) $this->option('actor'))->get();
        if ($target->count() !== 1 || $actor->count() !== 1) {
            $this->error('The member and the authorizing Super Administrator must each match exactly one account.');

            return self::FAILURE;
        }
        $member = $target->sole();
        $authorizer = $actor->sole();
        if (! $authorizer->isAuthenticationAllowed() || ! $authorizer->hasRole('super_admin')
            || $authorizer->is($member) || $member->hasRole('super_admin') || $reason === '' || mb_strlen($reason) > 500) {
            $this->error('An active Super Administrator other than the member, a non-super-admin member, and a reason are required.');

            return self::FAILURE;
        }

        $enable = ! $this->option('remove');
        $this->line(($enable ? 'Assigning' : 'Removing').' Fleet Operations Manager for user #'.$member->id.'.');
        $this->line('Currently assigned: '.(FleetOperationsManagerAssignment::isAssigned($member) ? 'yes' : 'no'));
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN ONLY. Nothing was changed.');

            return self::SUCCESS;
        }

        $result = DB::transaction(fn (): array => $assignment->apply($authorizer, $member, $enable, $reason));
        $this->info('Done. Granted directly: '.(implode(', ', $result['granted']) ?: 'none (already held)')
            .'. Revoked: '.(implode(', ', $result['revoked']) ?: 'none').'.');
        $this->line('Assigned now: '.(FleetOperationsManagerAssignment::isAssigned($member->fresh()) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
