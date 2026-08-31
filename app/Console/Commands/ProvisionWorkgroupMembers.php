<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Illuminate\Console\Command;

class ProvisionWorkgroupMembers extends Command
{
    protected $signature = 'mbfd:provision-workgroup-members';

    protected $description = 'Assign configured existing Users to the active Workgroup without creating accounts or changing credentials.';

    /**
     * Accounts holding any of these roles are not enrolled by this command.
     */
    private array $protectedRoles = [
        'super_admin',
        'admin',
        'logistics_admin',
        'training_admin',
        'workgroup_admin',
        'workgroup_facilitator',
    ];

    /**
     * Existing Users to enroll as Workgroup members.
     */
    private array $memberEmails = [
        'DavidGarcia@miamibeachfl.gov',
        'AlexanderNunez@miamibeachfl.gov',
        'MillyesGomez@miamibeachfl.gov',
        'JesusAbay@miamibeachfl.gov',
        'TimothyBarreto@miamibeachfl.gov',
        'MarcioBueno@miamibeachfl.gov',
    ];

    public function handle(): int
    {
        $this->info('=== Provisioning configured Workgroup memberships ===');

        $activeWorkgroup = Workgroup::where('is_active', true)->first();

        if (! $activeWorkgroup) {
            $this->error('No active workgroup found! Cannot provision members.');

            return self::FAILURE;
        }

        $this->line("Active workgroup: [{$activeWorkgroup->id}] {$activeWorkgroup->name}");

        $assignedRoles = 0;
        $createdMemberships = 0;
        $existingMemberships = 0;
        $missingUsers = 0;
        $protectedSkips = 0;

        foreach ($this->memberEmails as $configuredEmail) {
            $email = strtolower($configuredEmail);
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $user) {
                $this->line("  [SKIP] {$configuredEmail} — canonical User does not exist; provision identity through approved identity workflow.");
                $missingUsers++;

                continue;
            }

            if ($user->hasAnyRole($this->protectedRoles)) {
                $this->line("  [SKIP]  {$user->email} — has protected role(s): ".
                    implode(', ', array_filter($this->protectedRoles, fn ($role) => $user->hasRole($role))));
                $protectedSkips++;

                continue;
            }

            if (! $user->hasRole('workgroup_member')) {
                $user->assignRole('workgroup_member');
                $this->line("    → Role 'workgroup_member' assigned.");
                $assignedRoles++;
            } else {
                $this->line("    → Role 'workgroup_member' already assigned.");
            }

            $membership = WorkgroupMember::firstOrCreate(
                [
                    'workgroup_id' => $activeWorkgroup->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => 'member',
                    'is_active' => true,
                    'count_evaluations' => true,
                ],
            );

            if ($membership->wasRecentlyCreated) {
                $this->line("    → WorkgroupMember record created (ID: {$membership->id}).");
                $createdMemberships++;
            } else {
                $this->line("    → WorkgroupMember record already exists (ID: {$membership->id}).");
                $existingMemberships++;
            }
        }

        $this->info('');
        $this->info('=== SUMMARY ===');
        $this->line("Roles assigned              : {$assignedRoles}");
        $this->line("Membership records created  : {$createdMemberships}");
        $this->line("Existing memberships        : {$existingMemberships}");
        $this->line("Missing canonical Users     : {$missingUsers}");
        $this->line("Protected accounts skipped  : {$protectedSkips}");
        $this->info('Workgroup membership provisioning completed; canonical credentials were not accessed or modified.');

        return self::SUCCESS;
    }
}
