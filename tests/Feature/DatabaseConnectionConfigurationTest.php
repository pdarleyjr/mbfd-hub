<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseConnectionConfigurationTest extends TestCase
{
    public function test_an_explicit_test_connection_environment_is_not_overridden_by_phpunit_defaults(): void
    {
        $expectedConnection = getenv('EXPECTED_TEST_DB_CONNECTION');

        if (! is_string($expectedConnection) || $expectedConnection === '') {
            $this->markTestSkipped('No explicit test database connection was requested.');
        }

        $this->assertSame($expectedConnection, config('database.default'));
    }
}
