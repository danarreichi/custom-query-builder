<?php

require_once __DIR__ . '/EdgeCaseScenariosTrait.php';

/**
 * SQLite (sqlite3 driver) twin of EdgeCaseTest.php — shares 37 driver-agnostic
 * scenarios via EdgeCaseScenariosTrait; the 5 methods below assert exact
 * compiled-SQL text and so are double-quote-quoted here instead of backtick.
 *
 * Deliberately does NOT extend EdgeCaseTest — see SqliteExecutionTest.php's
 * docblock for why every Sqlite*Test class uses a shared trait instead of
 * cross-driver class inheritance (CI3's DB_driver.php::escape_identifiers()
 * caches its quote-char regex in a process-wide `static` local variable).
 */
class SqliteEdgeCaseTest extends CqbTestCase
{
    use EdgeCaseScenariosTrait;

    protected function setUp(): void
    {
        $this->db = cqb_sqlite_connection();
        $this->db->reset_query();
    }

    public function test_sibling_where_exists_calls_do_not_leak_state_between_each_other()
    {
        $sql = $this->sql($this->db->from('users')
            ->where_exists(function ($q) {
                $q->select('1')->from('scores')->where('scores.user_id = users.id');
            })
            ->where_exists(function ($q) {
                $q->select('1')->from('profiles')->where('profiles.user_id = users.id');
            })
            ->get_compiled_select());

        $this->assertSame(
            'SELECT * FROM "users" WHERE EXISTS (SELECT 1 FROM "scores" WHERE "scores"."user_id" = "users"."id") AND EXISTS (SELECT 1 FROM "profiles" WHERE "profiles"."user_id" = "users"."id")',
            $sql
        );
    }

    public function test_search_with_empty_columns_array_leaves_query_unchanged()
    {
        $sql = $this->sql($this->db->search('john', [])->get_compiled_select('users'));
        $this->assertSame('SELECT * FROM "users"', $sql);
    }

    public function test_search_skips_blank_entries_in_columns_without_producing_invalid_sql()
    {
        // Regression guard: an empty-string column at index 0 must not leave a
        // dangling "OR" as the first condition inside the group.
        $sql = $this->sql($this->db->search('john', ['', 'name'])->get_compiled_select('users'));
        $this->assertSame('SELECT * FROM "users" WHERE ( "name" LIKE \'%john%\' ESCAPE \'!\' )', $sql);
    }

    public function test_unless_runs_default_callback_when_condition_is_true()
    {
        $sql = $this->sql($this->db->unless(true, function ($q) {
            $q->where('category', 'A');
        }, function ($q) {
            $q->where('category', 'B');
        })->get_compiled_select('users'));

        $this->assertSame('SELECT * FROM "users" WHERE "category" = \'B\'', $sql);
    }

    public function test_column_name_of_exactly_64_chars_is_accepted()
    {
        $column = str_repeat('a', 64);
        // Should not throw — 64 is the documented maximum, not the cutoff.
        $sql = $this->sql($this->db->where_in($column, [1])->get_compiled_select('users'));
        $this->assertStringContainsString('"' . $column . '"', $sql);
    }
}