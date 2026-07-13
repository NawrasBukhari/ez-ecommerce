<?php

namespace EzEcommerce\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Base class for two-process race tests. Disables RefreshDatabase's per-test
 * transaction so setup data commits and is visible to child worker processes
 * spawned via proc_open (a separate DB connection cannot see an uncommitted
 * parent transaction). Resets the migration cache in tearDown so each race
 * test re-runs migrate:fresh for a clean slate.
 */
abstract class RaceTestCase extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        // Intentionally empty: commit setup data so child processes see it.
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Force the next race test to re-migrate fresh, since we skipped the
        // per-test transaction rollback that would otherwise clean up.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }
}
