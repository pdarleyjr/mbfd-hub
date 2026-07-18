<?php

use App\Actions\Admin\ConsolidateTrainingAdminAccounts;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        app(ConsolidateTrainingAdminAccounts::class)->handle();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $adminRole = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if (! $adminRole) {
            return;
        }

        User::query()
            ->role(['training_admin', 'training_viewer'])
            ->each(fn (User $user) => $user->removeRole($adminRole));
    }
};
