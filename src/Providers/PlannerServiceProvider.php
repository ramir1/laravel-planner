<?php

declare(strict_types=1);

namespace Ramir\Planner\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Ramir\Planner\Console\Commands\PlannerAtCommand;
use Ramir\Planner\Console\Commands\PlannerRunCommand;
use Ramir\Planner\Planner;

class PlannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/planner.php', 'planner');

        $this->app->singleton(Planner::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PlannerAtCommand::class,
                PlannerRunCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        if (config('planner.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        if (config('planner.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('planner:run')->everyMinute()->withoutOverlapping();
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/planner.php' => config_path('planner.php'),
            ], 'planner-config');

            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'planner-migrations');
        }
    }
}
