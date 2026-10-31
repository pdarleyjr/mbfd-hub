<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Dashboards\IssueSource;
use App\Support\Dashboards\IssueSourceRegistry;
use Illuminate\Support\ServiceProvider;

/** Builds the admin dashboard issue registry from config/dashboards.php (one entry per domain adapter). */
final class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IssueSourceRegistry::class, function ($app): IssueSourceRegistry {
            $sources = [];
            foreach ([...(array) config('dashboards.vehicle_sources', []), ...(array) config('dashboards.station_sources', [])] as $class) {
                $source = $app->make($class);
                if ($source instanceof IssueSource) {
                    $sources[] = $source;
                }
            }

            return new IssueSourceRegistry($sources);
        });
    }
}
