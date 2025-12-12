<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Laraib15\SchemaGenerator\SchemaGeneratorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register the package service provider for Testbench.
     */
    protected function getPackageProviders($app): array
    {
        return [
            SchemaGeneratorServiceProvider::class,
        ];
    }

    /**
     * Configure the environment for tests.
     */
    protected function defineEnvironment($app): void
    {
        // Default to sqlite in-memory for most tests
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
