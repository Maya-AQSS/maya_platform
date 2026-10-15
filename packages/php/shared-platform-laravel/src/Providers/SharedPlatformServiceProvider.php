<?php

declare(strict_types=1);

namespace Maya\Platform\Providers;

use Illuminate\Support\ServiceProvider;
use Maya\Platform\Console\Commands\ApplyMayaAppsViews;
use Maya\Platform\Console\Commands\GenerateSeedersFromDatabase;

class SharedPlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/maya-views.php', 'maya-views');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateSeedersFromDatabase::class,
                ApplyMayaAppsViews::class,
            ]);
        }
    }
}
