<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Mbfd\PolicyLibrary\PolicyLibraryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $privateRoot;

    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            PermissionServiceProvider::class,
            PolicyLibraryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->privateRoot = sys_get_temp_dir().'/mbfd-policy-tests-'.bin2hex(random_bytes(8));
        $app['config']->set('app.url', 'https://mbfdhub.com');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('policy-library.storage_root', $this->privateRoot);
        $app['config']->set('policy-library.scanner_enabled', false);
        $app['config']->set('policy-library.pin_hash', password_hash('test-library-pin', PASSWORD_BCRYPT));
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        $migration = require __DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $migration->up();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->artisan('migrate')->run();
    }

    protected function tearDown(): void
    {
        if (isset($this->privateRoot) && is_dir($this->privateRoot)) {
            (new Filesystem)->deleteDirectory($this->privateRoot);
        }
        parent::tearDown();
    }

    protected function user(string $name = 'Member'): TestUser
    {
        return TestUser::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => password_hash('test-user-password', PASSWORD_BCRYPT)]);
    }

    protected function accessGrant(TestUser $user, int $offset = 3600): array
    {
        return ['user_id' => (string) $user->id, 'expires_at' => now()->timestamp + $offset, 'pin_version' => hash('sha256', config('policy-library.pin_hash'))];
    }
}
