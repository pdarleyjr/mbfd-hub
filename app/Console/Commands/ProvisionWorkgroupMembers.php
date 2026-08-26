<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ProvisionWorkgroupMembers extends Command
{
    protected $signature = 'mbfd:provision-workgroup-members
                            {--password= : Unique temporary password for affected accounts (minimum 15 characters)}
                            {--password-env= : Environment variable containing the unique temporary password}';

    protected $description = 'Create 6 new workgroup member accounts and reset existing base member passwords using an explicitly supplied temporary password. SAFE: never touches admin or elevated-role accounts.';

    /**
     * Roles that are PROTECTED — accounts holding any of these roles
     * will NOT have their passwords changed.
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
     * New members to provision.
     */
    private array $newMembers = [
        ['email' => 'DavidGarcia@miamibeachfl.gov',    'name' => 'David Garcia'],
        ['email' => 'AlexanderNunez@miamibeachfl.gov', 'name' => 'Alexander Nunez'],
        ['email' => 'MillyesGomez@miamibeachfl.gov',   'name' => 'Millyes Gomez'],
        ['email' => 'JesusAbay@miamibeachfl.gov',      'name' => 'Jesus Abay'],
        ['email' => 'TimothyBarreto@miamibeachfl.gov', 'name' => 'Timothy Barreto'],
        ['email' => 'MarcioBueno@miamibeachfl.gov',    'name' => 'Marcio Bueno'],
    ];

    public function handle(): int
    {
        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $hashedPassword = Hash::make($password);

        // ----------------------------------------------------------------
        // STEP 1 — Create or update the 6 new member accounts
        // ----------------------------------------------------------------
        $this->info('=== STEP 1: Provisioning new workgroup member accounts ===');

        $activeWorkgroup = Workgroup::where('is_active', true)->first();

        if (! $activeWorkgroup) {
            $this->error('No active workgroup found! Cannot provision members.');

            return self::FAILURE;
        }

        $this->line("Active workgroup: [{$activeWorkgroup->id}] {$activeWorkgroup->name}");

        $protectedProvisionSkips = 0;
        foreach ($this->newMembers as $memberData) {
            $email = strtolower($memberData['email']);
            $name = $memberData['name'];

            // Find or create the User (case-insensitive email lookup)
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user && $user->hasAnyRole($this->protectedRoles)) {
                $this->line("  [SKIP]  {$user->email} — has protected role(s): ".
                    implode(', ', array_filter($this->protectedRoles, fn ($role) => $user->hasRole($role))));
                $protectedProvisionSkips++;

                continue;
            }

            if ($user) {
                $user->update([
                    'password' => $hashedPassword,
                    'must_change_password' => true,
                ]);
                $this->line("  [UPDATE] User found: {$user->email} — password reset.");
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $memberData['email'],
                    'password' => $hashedPassword,
                    'must_change_password' => true,
                    'email_verified_at' => now(),
                ]);
                $this->line("  [CREATE] User created: {$user->email}");
            }

            // Ensure workgroup_member role is assigned (only this role — do not strip others)
            if (! $user->hasRole('workgroup_member')) {
                $user->assignRole('workgroup_member');
                $this->line("    → Role 'workgroup_member' assigned.");
            } else {
                $this->line("    → Role 'workgroup_member' already assigned.");
            }

            // Ensure WorkgroupMember record exists
            $existingMember = WorkgroupMember::where('user_id', $user->id)
                ->where('workgroup_id', $activeWorkgroup->id)
                ->first();

            if ($existingMember) {
                $this->line("    → WorkgroupMember record already exists (ID: {$existingMember->id}).");
            } else {
                $wm = WorkgroupMember::create([
                    'workgroup_id' => $activeWorkgroup->id,
                    'user_id' => $user->id,
                    'role' => 'member',
                    'is_active' => true,
                    'count_evaluations' => true,
                ]);
                $this->line("    → WorkgroupMember record created (ID: {$wm->id}).");
            }
        }

        // ----------------------------------------------------------------
        // STEP 2 — Reset passwords for EXISTING base workgroup_members
        //          who do NOT hold any protected/elevated role
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('=== STEP 2: Resetting passwords for existing base workgroup members ===');

        // Get all users that have the workgroup_member role
        $candidateUsers = User::role('workgroup_member')->get();

        $resetCount = 0;
        $skippedCount = 0;

        foreach ($candidateUsers as $user) {
            // Skip anyone holding a protected/elevated role
            if ($user->hasAnyRole($this->protectedRoles)) {
                $this->line("  [SKIP]  {$user->email} — has protected role(s): ".
                    implode(', ', array_filter($this->protectedRoles, fn ($r) => $user->hasRole($r))));
                $skippedCount++;

                continue;
            }

            $user->update([
                'password' => $hashedPassword,
                'must_change_password' => true,
            ]);
            $this->line("  [RESET] {$user->email}");
            $resetCount++;
        }

        $this->info('');
        $this->info('=== SUMMARY ===');
        $this->line('New accounts provisioned : '.(count($this->newMembers) - $protectedProvisionSkips));
        $this->line("Passwords reset          : {$resetCount}");
        $this->line('Protected accounts skipped: '.($protectedProvisionSkips + $skippedCount));
        $this->info('All workgroup member passwords were reset using the supplied credential material.');

        return self::SUCCESS;
    }

    private function resolvePassword(): ?string
    {
        $optionPassword = (string) $this->option('password');
        $environmentVariable = trim((string) $this->option('password-env'));

        if ($optionPassword !== '' && $environmentVariable !== '') {
            $this->error('Use either --password=<value> or --password-env=<VARIABLE>, not both.');

            return null;
        }

        if ($environmentVariable !== '') {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $environmentVariable)) {
                $this->error('The --password-env value must be a valid environment variable name.');

                return null;
            }

            $environmentPassword = getenv($environmentVariable);
            $password = is_string($environmentPassword) ? $environmentPassword : '';
        } else {
            $password = $optionPassword;
        }

        if ($password === '') {
            $this->error('Pass a unique temporary password with --password=<value> or --password-env=<VARIABLE>. No default password is available.');

            return null;
        }

        if (mb_strlen($password) < 15) {
            $this->error('Temporary passwords must contain at least 15 characters.');

            return null;
        }

        return $password;
    }
}
