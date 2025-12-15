<?php

declare(strict_types=1);

namespace Laraib15\SchemaGenerator;

use Illuminate\Support\ServiceProvider;
use Laraib15\SchemaGenerator\Console\Commands\GenerateCrudFromSchema;

class SchemaGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCrudFromSchema::class,
            ]);
        }
    }
}
