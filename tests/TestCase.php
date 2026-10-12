<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a test database.
     *
     * Runs before RefreshDatabase, which drops every table on the connection
     * it is given. With `php artisan config:cache` in effect, Laravel ignores
     * phpunit.xml's SQLite settings and that connection is the real one: on
     * 2026-10-12 a test run did exactly that and emptied the development
     * database. So the suite stops first, with the fix in the message.
     */
    protected function setUpTraits()
    {
        if ($this->app->configurationIsCached()) {
            throw new RuntimeException(
                'The configuration is cached, so these tests would run against the real database and wipe it. '
                .'Run `php artisan config:clear` first.'
            );
        }

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run the tests against [{$connection}] database [{$database}]: "
                .'only an in-memory SQLite database or one whose name ends in _test is allowed.'
            );
        }

        return parent::setUpTraits();
    }
}
