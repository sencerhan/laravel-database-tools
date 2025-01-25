<?php

namespace Sencerhan\LaravelDbTools;

use Illuminate\Support\ServiceProvider;
use Sencerhan\LaravelDbTools\Commands\CreateMigrationsFromDatabaseCommand;
use Sencerhan\LaravelDbTools\Commands\CreateSeederFromDatabaseCommand;

class LaravelDbToolsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateMigrationsFromDatabaseCommand::class,
                CreateSeederFromDatabaseCommand::class,
            ]);
        }
    }

    public function register()
    {
        //
    }
} 