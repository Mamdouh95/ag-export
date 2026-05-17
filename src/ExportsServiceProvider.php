<?php

namespace Agriserv\Exports;

use Agriserv\Exports\Console\PruneExportsCommand;
use Agriserv\Exports\Livewire\ExportsList;
use Agriserv\Exports\Support\QueuedExports;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class ExportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/exports.php', 'exports');

        $this->app->singleton('agriserv.exports', fn () => new QueuedExports());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'exports');

        if (config('exports.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/Config/exports.php' => config_path('exports.php'),
            ], 'exports-config');

            $this->publishes([
                __DIR__ . '/Database/migrations' => database_path('migrations'),
            ], 'exports-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/exports'),
            ], 'exports-views');

            $this->commands([
                PruneExportsCommand::class,
            ]);
        }

        if (class_exists(Livewire::class)) {
            Livewire::component('exports.list', ExportsList::class);
        }
    }
}
