<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class EnsureAdminRoles extends Command
{
    protected $signature = 'mbfd:ensure-admin-roles';
    protected $description = 'Ensure specified users have admin or super_admin roles for Equipment Intake and Snipe-IT access';

    public function handle(): int
    {
        $emails = [
            'MiguelAnchia@miamibeachfl.gov',
            'RichardQuintela@miamibeachfl.gov',
            'PeterDarley@miamibeachfl.gov',
            'GreciaTrabanino@miamibeachfl.gov',
            'geralddeyoung@miamibeachfl.gov',
        ];

        foreach ($emails as $email) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

            if (!$user) {
                $this->warn("User not found: {$email} — they may need to be created first.");
                continue;
            }

            $hasAdminRole = $user->hasRole(['super_admin', 'admin', 'logistics_admin']);

            if ($hasAdminRole) {
                $roles = $user->getRoleNames()->implode(', ');
                $this->info("✓ {$user->email} already has role(s): {$roles}");
            } else {
                $user->assignRole('admin');
                $this->info("✓ {$user->email} — assigned 'admin' role");
            }
        }

        $this->newLine();
        $this->info('Done. Run `php artisan permission:cache-reset` on VPS after deploying.');

        return self::SUCCESS;
    }
}
