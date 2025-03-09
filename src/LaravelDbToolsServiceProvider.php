<?php

namespace Sencerhan\LaravelDbTools;

use Illuminate\Support\ServiceProvider;
use Sencerhan\LaravelDbTools\Commands\CreateMigrationsFromDatabaseCommand;
use Sencerhan\LaravelDbTools\Commands\CreateSeederFromDatabaseCommand;
use Sencerhan\LaravelDbTools\Commands\CheckAndSaveMigrationsCommand;
use Sencerhan\LaravelDbTools\Commands\FetchDatabaseSchemaCommand;

class LaravelDbToolsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateMigrationsFromDatabaseCommand::class,
                CreateSeederFromDatabaseCommand::class,
                CheckAndSaveMigrationsCommand::class,
                FetchDatabaseSchemaCommand::class,
            ]);
        }
    }

    public function register()
    {
        //
    }
}