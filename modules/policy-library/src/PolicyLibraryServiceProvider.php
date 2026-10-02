<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Mbfd\PolicyLibrary\Console\ImportCommand;
use Mbfd\PolicyLibrary\Console\PublishCommand;
use Mbfd\PolicyLibrary\Filament\LibraryPanelProvider;
use Mbfd\PolicyLibrary\Support\LibraryAccess;
use Mbfd\PolicyLibrary\Support\PinErrorRedactor;
use Sentry\State\Scope;

final class PolicyLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/policy-library.php', 'policy-library');
        if (! config('queue.connections.policy-library')) {
            $redis = config('queue.connections.redis', []);
            config(['queue.connections.policy-library' => array_merge($redis, ['driver' => 'redis', 'connection' => $redis['connection'] ?? 'default', 'queue' => config('policy-library.queue_name'), 'retry_after' => config('policy-library.queue_retry_after'), 'block_for' => null, 'after_commit' => true])]);
        }
        $this->app->register(LibraryPanelProvider::class);
    }

    public function boot(): void
    {
        if (function_exists('Sentry\\configureScope')) {
            \Sentry\configureScope(fn (Scope $scope) => $scope->addEventProcessor(new PinErrorRedactor(config('policy-library.domain'))));
        }
        $rules = config('livewire.temporary_file_upload.rules') ?? ['required', 'file', 'max:12288'];
        $rules = is_array($rules) ? $rules : explode('|', $rules);
        foreach ($rules as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $limit)) {
                config(['policy-library.max_upload_kb' => min((int) config('policy-library.max_upload_kb'), (int) $limit[1])]);
            }
        }
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'policy-library');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        Gate::define('policy-library.manage', fn ($user) => LibraryAccess::canManage($user));
        $this->publishes([__DIR__.'/../public' => public_path('vendor/policy-library')], 'policy-library-assets');
        $this->publishes([__DIR__.'/../config/policy-library.php' => config_path('policy-library.php')], 'policy-library-config');
        if ($this->app->runningInConsole()) {
            $this->commands([ImportCommand::class, PublishCommand::class]);
        }
    }
}
