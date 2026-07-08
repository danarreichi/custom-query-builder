<?php

use PHPUnit\Framework\TestCase;

/**
 * Base test case: shares one live DB connection across the whole run and
 * guarantees every test starts from clean query-builder state, so a pending_*
 * queue leaked by one test can't bleed into the next test's compiled SQL.
 */
abstract class CqbTestCase extends TestCase
{
    /** @var CustomQueryBuilder */
    protected $db;

    protected function setUp(): void
    {
        $this->db = cqb_connection();
        $this->db->reset_query();
    }

    /**
     * Normalize CI's multi-line compiled SQL to single-spaced text so
     * assertions aren't sensitive to CI's internal line-wrapping.
     */
    protected function sql($builder_result)
    {
        return preg_replace('/\s+/', ' ', trim($builder_result));
    }
}
