<?php
/**
 * PHPUnit bootstrap for the CustomQueryBuilder regression suite.
 *
 * Boots just enough of CodeIgniter 3's database layer (system/database/DB.php)
 * to obtain a real, connected CustomQueryBuilder instance — reusing the exact
 * same DB()-based wiring (system/database/DB.php:172-186, which defines
 * `class CI_DB extends CustomQueryBuilder {}` before loading the mysqli
 * driver) that test-ci3/application/controllers/Test_custom_qb.php goes
 * through via the framework's front controller. No HTTP server, no
 * controller dispatch — just the DB stack.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

define('ENVIRONMENT', 'testing');
define('BASEPATH', str_replace('\\', '/', realpath(__DIR__ . '/../test-ci3/system')) . '/');
define('APPPATH', str_replace('\\', '/', realpath(__DIR__ . '/../test-ci3/application')) . '/');

require_once __DIR__ . '/vendor/autoload.php';

if (!function_exists('show_error')) {
    function show_error($message, $status_code = 500, $heading = 'An Error Was Encountered')
    {
        throw new RuntimeException(is_array($message) ? implode("\n", $message) : (string) $message);
    }
}
if (!function_exists('log_message')) {
    function log_message($level, $message) {}
}
if (!function_exists('get_instance')) {
    function get_instance() { return null; }
}
if (!function_exists('is_php')) {
    // Same implementation as system/core/Common.php::is_php(), which the DB
    // driver layer normally relies on the front controller having loaded.
    function is_php($version)
    {
        static $_is_php;
        $version = (string) $version;
        if (!isset($_is_php[$version])) {
            $_is_php[$version] = version_compare(PHP_VERSION, $version, '>=');
        }
        return $_is_php[$version];
    }
}

require_once BASEPATH . 'database/DB.php';
require_once __DIR__ . '/CqbTestCase.php';

/**
 * Return a live, connected CustomQueryBuilder instance. Connection settings
 * come from test-ci3/application/config/database.php — the SAME config file
 * the manual smoke-test controller uses — so there is exactly one place to
 * edit when pointing this suite at a different MySQL server/database.
 *
 * The connection is reused across the whole test run (connecting per-test
 * would be needlessly slow); each test is responsible for leaving no state
 * behind, which CqbTestCase::setUp() enforces via reset_query().
 *
 * @return CustomQueryBuilder
 */
function cqb_connection()
{
    static $connection = null;
    if ($connection === null) {
        require APPPATH . 'config/database.php'; // defines $active_group, $db (array of connection groups)
        $connection = DB($db[$active_group], true);
        cqb_seed_fixtures($connection);
    }
    return $connection;
}

/**
 * Deterministically (re)seed the fixture tables the test suite depends on,
 * so the suite is self-contained and never depends on test-ci3's HTTP smoke
 * test having run first. Mirrors the fixtures Test_custom_qb.php creates.
 *
 * `users` is only seeded if empty — it's treated as a pre-existing app
 * table, not a test-owned scratch table, so we never blow away rows a
 * developer may have added to it by hand.
 */
function cqb_seed_fixtures($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100), email VARCHAR(150), category VARCHAR(10))");
    $existing = $db->query("SELECT COUNT(*) AS c FROM users")->row()->c;
    if ((int) $existing === 0) {
        $db->query("INSERT INTO users (name, email, category) VALUES
            ('Alice', 'alice@example.com', 'A'),
            ('Bob', 'bob@example.com', 'B'),
            ('Charlie', 'charlie@example.com', 'A')");
    }

    $db->query("CREATE TABLE IF NOT EXISTS scores (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, value INT)");
    $db->query("DELETE FROM scores");
    $db->query("INSERT INTO scores (user_id, value) VALUES (1, 10), (1, 50), (1, 30)");

    $db->query("CREATE TABLE IF NOT EXISTS category_scores (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, category VARCHAR(10), value INT)");
    $db->query("DELETE FROM category_scores");
    $db->query("INSERT INTO category_scores (user_id, category, value) VALUES (1, 'A', 100), (1, 'A', 50), (1, 'B', 999)");

    $db->query("CREATE TABLE IF NOT EXISTS profiles (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, rank_score INT)");
    $db->query("DELETE FROM profiles");
    $db->query("INSERT INTO profiles (user_id, rank_score) VALUES (1, 10), (2, 30), (3, 20)");
}
