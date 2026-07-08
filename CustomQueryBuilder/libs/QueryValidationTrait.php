<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Query Validation Trait
 *
 * Provides shared validation methods for SQL injection prevention
 * Used by both NestedQueryBuilder and CustomQueryBuilder classes
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait QueryValidationTrait
{
    /**
     * Common dangerous SQL patterns to block
     * @var array
     */
    protected static $DANGEROUS_SQL_PATTERNS = [
        // BUG FIX: HAVING was missing here, so is_valid_column_name()'s bare-token
        // fallback in is_valid_custom_expression() let it through as an "assumed
        // column name" (e.g. "1 HAVING 1=1"), unlike is_valid_calculation_expression()'s
        // separate $dangerous_keywords list, which already blocked it explicitly.
        '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND|WHERE|HAVING|FROM|JOIN|INTO|VALUES|SET|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i',
        '/[;]/',        // Dash character blocked
        '/--/',             // SQL comment pattern
        '/\/\*/',           // Multi-line comment start
        '/\*\//',           // Multi-line comment end
        '/\s+--/',          // Space followed by comment
        '/-{2,}/',          // Multiple dashes
    ];

    /**
     * Extended dangerous patterns for table/column validation
     * @var array
     */
    protected static $EXTENDED_DANGEROUS_PATTERNS = [
        '/\bxp_/',          // Extended stored procedures
        '/\bsp_/',          // Stored procedures
        '/\|\|/',           // OR operator in some SQL dialects
        '/&&/',             // AND operator in some SQL dialects
    ];

    /**
     * Allowed comparison operators
     * @var array
     */
    protected static $ALLOWED_OPERATORS = ['=', '>', '<', '>=', '<=', '!=', '<>'];

    /**
     * Allowed aggregate types
     * @var array
     */
    protected static $ALLOWED_AGGREGATE_TYPES = ['count', 'sum', 'avg', 'min', 'max', 'custom'];

    /**
     * Allowed SQL functions in expressions
     * @var array
     */
    protected static $ALLOWED_SQL_FUNCTIONS = [
        'SUM',
        'AVG',
        'COUNT',
        'MAX',
        'MIN',
        'ROUND',
        'FLOOR',
        'CEIL',
        'ABS',
        'COALESCE',
        'IFNULL',
        'NULLIF',
        'CASE',
        'WHEN',
        'THEN',
        'ELSE',
        'END',
        'DATEDIFF',
        'TIMESTAMPDIFF',
        'DAY',
        'MONTH',
        'YEAR',
        'NOW',
        'DATE',
        'CONCAT',
        'SUBSTRING',
        'TRIM',
        'UPPER',
        'LOWER',
        'LENGTH',
        'REPLACE',
        'CAST',
        'CONVERT',
        'IF',
        'AND',
        'OR',
        'IS',
        'NOT',
        'NULL',
        'AS'
    ];

    /**
     * Extract clean table name from table string (removes alias and backticks)
     *
     * @param string $table_string Table string that may contain alias and backticks
     * @return string Clean table name
     */
    protected function extract_table_name($table_string)
    {
        // Remove any alias by splitting on space and taking first part
        $table_parts = explode(' ', trim($table_string));
        $table_name = trim($table_parts[0]);

        // Remove backticks if present
        $table_name = trim($table_name, '`');

        return $table_name;
    }

    /**
     * Validate a full relation/table string, including an optional alias
     * ("table", "table alias", or "table AS alias", with optional backticks).
     *
     * This is the security gate for relation strings — extract_table_name()
     * is a display/parsing helper that only returns the first whitespace
     * token, so callers that validated extract_table_name($relation) while
     * storing/using the original $relation string let anything after the
     * first space through completely unvalidated (e.g. "orders UNION SELECT
     * ... -- " would pass because "orders" alone was checked). This method
     * validates every token in the string instead.
     *
     * @param string $relation_string Relation string that may contain an alias and backticks
     * @return bool True if the whole string is a safe table (+ optional alias) reference
     */
    protected function is_valid_relation_string($relation_string)
    {
        if (!is_string($relation_string) || trim($relation_string) === '')
            return false;

        $cleaned = str_replace('`', '', trim($relation_string));
        $tokens = preg_split('/\s+/', $cleaned);

        // "table AS alias" -> treat like "table alias"
        if (count($tokens) === 3) {
            if (strtoupper($tokens[1]) !== 'AS')
                return false;
            $tokens = [$tokens[0], $tokens[2]];
        }

        if (count($tokens) > 2)
            return false;

        foreach ($tokens as $token) {
            if (!$this->is_valid_table_name($token))
                return false;
        }

        return true;
    }

    /**
     * Extract table name or alias from table string
     * Returns the alias if present, otherwise returns the table name
     *
     * @param string $table_string Table string that may contain alias and backticks
     * @return string Table alias or name
     */
    protected function extract_table_or_alias($table_string)
    {
        // Remove backticks first
        $cleaned = str_replace('`', '', trim($table_string));

        // Split by space to separate table name and alias
        $parts = preg_split('/\s+/', $cleaned);

        // If there are multiple parts, check for AS keyword
        if (count($parts) >= 2) {
            // If AS keyword exists, return the part after AS
            $as_index = array_search('AS', array_map('strtoupper', $parts));
            if ($as_index !== false && isset($parts[$as_index + 1])) {
                return $parts[$as_index + 1];
            }
            // Otherwise return the last part (assumed to be alias)
            return $parts[count($parts) - 1];
        }

        // No alias found, return the table name itself
        return $parts[0];
    }

    /**
     * Internal helper to validate identifier (column or table name)
     *
     * @param string $name Identifier to validate
     * @param string $regex_pattern Regex pattern for allowed format
     * @param bool $check_extended Whether to check extended dangerous patterns
     * @return bool True if valid, false otherwise
     */
    protected function validate_identifier_internal($name, $regex_pattern, $check_extended = false)
    {
        if (!is_string($name) || empty($name))
            return false;

        if (!preg_match($regex_pattern, $name))
            return false;

        // Check against common SQL injection patterns
        foreach (self::$DANGEROUS_SQL_PATTERNS as $pattern) {
            if (preg_match($pattern, $name))
                return false;
        }

        // Check extended patterns if needed
        if ($check_extended) {
            foreach (self::$EXTENDED_DANGEROUS_PATTERNS as $pattern) {
                if (preg_match($pattern, $name))
                    return false;
            }
        }

        // Check length to prevent buffer overflow attacks
        if (strlen($name) > 64)
            return false;

        return true;
    }

    /**
     * Validate column name to prevent SQL injection
     *
     * @param string $column_name Column name to validate
     * @return bool True if valid, false otherwise
     */
    protected function is_valid_column_name($column_name)
    {
        // Allow only alphanumeric characters, underscores, and dots (for table.column)
        return $this->validate_identifier_internal(
            $column_name,
            '/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/',
            true  // Check extended patterns for columns
        );
    }

    /**
     * Validate table name to prevent SQL injection
     *
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     */
    protected function is_valid_table_name($table_name)
    {
        // Allow only alphanumeric characters and underscores
        return $this->validate_identifier_internal(
            $table_name,
            '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            false  // Don't check extended patterns for tables
        );
    }

    /**
     * Dangerous patterns specifically for expression validation
     * @var array
     */
    protected static $EXPRESSION_DANGEROUS_PATTERNS = [
        '/\b(INSERT|UPDATE|DELETE|DROP|UNION|EXEC|EXECUTE|CREATE|ALTER|TRUNCATE)\b/i',
        '/[;]/',             // Quotes and semicolons
        '/--/',               // SQL comments
        '/\/\*/',             // Multi-line comment start
        '/\*\//',             // Multi-line comment end
        '/\|\|/',             // String concatenation operator
        '/&&/',               // Logical AND operator
        '/\bxp_/',            // Extended stored procedures
        '/\bsp_/',            // Stored procedures
    ];

    /**
     * Internal helper for expression validation
     *
     * @param string $expression Expression to validate
     * @param string $allowed_char_pattern Regex for allowed characters
     * @param array $extra_dangerous_patterns Additional dangerous patterns to check
     * @return bool True if basic checks pass, false otherwise
     */
    protected function validate_expression_base($expression, $allowed_char_pattern, $extra_dangerous_patterns = [])
    {
        if (!is_string($expression) || empty($expression))
            return false;
        if (!preg_match($allowed_char_pattern, $expression))
            return false;
        // Check expression-specific dangerous patterns
        foreach (self::$EXPRESSION_DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $expression))
                return false;
        }
        // Check extra dangerous patterns
        foreach ($extra_dangerous_patterns as $pattern) {
            if (preg_match($pattern, $expression))
                return false;
        }
        if (!$this->are_parentheses_balanced($expression))
            return false;
        if (strlen($expression) > 2000)
            return false;
        return true;
    }

    /**
     * Check if token is in allowed SQL functions list
     *
     * @param string $token Token to check
     * @return bool True if allowed, false otherwise
     */
    protected function is_allowed_sql_function($token)
    {
        return in_array(strtoupper($token), self::$ALLOWED_SQL_FUNCTIONS);
    }

    /**
     * Reject expressions that call a function not on the allowlist.
     *
     * Token-level validation alone lets an unrecognized identifier through as
     * an "assumed column name" (there's no schema to check it against), which
     * also lets disallowed function calls like SLEEP(5) or BENCHMARK(...)
     * slip through as if they were column references. This scans for any
     * `identifier(` call and requires the identifier to be explicitly allowed.
     *
     * @param string $expression Expression to scan (quoted string literals should already be stripped)
     * @param array $extra_allowed Additional function names allowed in this context (uppercase)
     * @return bool True if every function call in the expression is allowed
     */
    protected function has_only_allowed_function_calls($expression, $extra_allowed = [])
    {
        // Allow an optional backtick immediately around the identifier (e.g.
        // `SLEEP`(5)) so a quoted identifier can't dodge detection here — MySQL
        // treats a backtick-quoted name immediately followed by `(` as a valid
        // function call, same as an unquoted one. Without the backtick in the
        // pattern, `SLEEP`(5) was invisible to this check entirely (no match),
        // then the tokenizer below stripped the backticks and let "SLEEP"
        // through as if it were a harmless bare column reference.
        if (!preg_match_all('/`?([A-Za-z_][A-Za-z0-9_]*)`?\s*\(/', $expression, $matches))
            return true;

        foreach ($matches[1] as $name) {
            if (!in_array(strtoupper($name), self::$ALLOWED_SQL_FUNCTIONS) && !in_array(strtoupper($name), $extra_allowed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * OR/AND/IS/NOT are only allowed as tokens so CASE WHEN ... THEN ... END
     * expressions can use compound conditions (e.g. "WHEN a=1 AND b=2").
     * Every CASE...END span is stripped out first, then the remainder is
     * checked for a boolean keyword — so a decoy "CASE WHEN 1=1 THEN 1 END"
     * tacked onto an expression can no longer be used to unlock a real
     * OR/AND elsewhere (e.g. "SUM(price) OR 1=1 CASE WHEN 1=1 THEN 1 END"
     * used to pass because *a* CASE existed somewhere in the string; it's
     * now rejected because the OR survives outside the stripped CASE span).
     * This still isn't a real parser (nested CASE...END isn't paired
     * correctly), but it closes the simple decoy bypass.
     *
     * @param string $expression
     * @return bool True if a boolean keyword appears outside any CASE...END span
     */
    protected function has_boolean_keyword_outside_case($expression)
    {
        $without_case = preg_replace('/\bCASE\b.*?\bEND\b/is', '', $expression);
        return preg_match('/\b(OR|AND|IS|NOT)\b/i', $without_case) === 1;
    }

    /**
     * Validate custom SQL expression for aggregation functions
     *
     * @param string $expression Custom SQL expression to validate
     * @return bool True if valid, false otherwise
     */
    protected function is_valid_custom_expression($expression)
    {
        $extra_patterns = ['/\bSELECT\b/i', '/\bFROM\b/i', '/\bJOIN\b/i'];
        if (!$this->validate_expression_base($expression, '/^[\w\s\(\)\+\-\*\/\.,`<>=]+$/', $extra_patterns))
            return false;
        if ($this->has_boolean_keyword_outside_case($expression))
            return false;
        if (!$this->has_only_allowed_function_calls($expression))
            return false;
        // Validate tokens
        $tokens = preg_split('/[\s\(\)\+\-\*\/,<>=]+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            if (is_numeric($token) || $this->is_allowed_sql_function($token))
                continue;
            $cleaned_token = str_replace('`', '', $token);
            if (!$this->is_valid_column_name($cleaned_token))
                return false;
        }
        return true;
    }

    /**
     * Validate calculation expression for mathematical operations with aggregates
     *
     * This method validates expressions that can contain:
     * - Aggregate functions (SUM, AVG, COUNT, MIN, MAX)
     * - Mathematical operations (+, -, *, /, %)
     * - Date functions (DATEDIFF, TIMESTAMPDIFF)
     * - Mathematical functions (ROUND, FLOOR, CEIL, ABS)
     * - Conditional expressions (CASE WHEN)
     *
     * @param string $expression Mathematical expression to validate
     * @return bool True if valid, false otherwise
     */
    protected function is_valid_calculation_expression($expression)
    {
        if (!$this->validate_expression_base($expression, '/^[\w\s\(\)\+\-\*\/\.,`%<>=\'"]+$/'))
            return false;

        if ($this->has_boolean_keyword_outside_case($expression))
            return false;

        // Block dangerous SQL keywords (but allow CASE, WHEN, THEN, ELSE, END).
        // SELECT/FROM/JOIN/WHERE/HAVING/OUTFILE/DUMPFILE were previously only
        // blocked incidentally — as a side effect of is_valid_column_name()
        // rejecting them as bare tokens — rather than explicitly, unlike
        // is_valid_custom_expression()'s dedicated extra_patterns. Listing them
        // here directly means this stays blocked even if the token-validation
        // path is ever refactored, instead of relying on that overlap.
        $dangerous_keywords = [
            'INSERT',
            'UPDATE',
            'DELETE',
            'DROP',
            'UNION',
            'EXEC',
            'EXECUTE',
            'CREATE',
            'ALTER',
            'TRUNCATE',
            'INTO',
            'VALUES',
            'SELECT',
            'FROM',
            'JOIN',
            'WHERE',
            'HAVING',
            'OUTFILE',
            'DUMPFILE'
        ];
        foreach ($dangerous_keywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $expression))
                return false;
        }

        // Remove quoted strings temporarily for token validation
        $expression_without_strings = preg_replace('/"[^"]*"/', 'STRING_LITERAL', $expression);
        $expression_without_strings = preg_replace("/'[^']*'/", 'STRING_LITERAL', $expression_without_strings);

        $extra_allowed_functions = ['POW', 'SQRT', 'MOD', 'CURDATE', 'CURTIME'];
        if (!$this->has_only_allowed_function_calls($expression_without_strings, $extra_allowed_functions))
            return false;

        // Validate tokens (exclude string literals)
        $tokens = preg_split('/[\s\(\)\+\-\*\/,%<>=]+/', $expression_without_strings, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            // Skip string literal placeholders
            if ($token === 'STRING_LITERAL')
                continue;

            // Allow numbers
            if (is_numeric($token))
                continue;

            // Allow SQL functions
            if ($this->is_allowed_sql_function($token))
                continue;

            // Allow TRUE/FALSE and other safe keywords
            if (in_array(strtoupper($token), ['TRUE', 'FALSE', 'DISTINCT', 'HOUR', 'MINUTE', 'SECOND', 'CURDATE', 'CURTIME', 'POW', 'SQRT', 'MOD']))
                continue;

            // Validate column names
            if (!$this->is_valid_column_name($token))
                return false;
        }
        return true;
    }

    /**
     * Check if parentheses are balanced in expression
     *
     * @param string $expression Expression to check
     * @return bool True if balanced, false otherwise
     */
    protected function are_parentheses_balanced($expression)
    {
        $count = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            if ($expression[$i] === '(') {
                $count++;
            } elseif ($expression[$i] === ')') {
                $count--;
                // If we have more closing than opening, it's unbalanced
                if ($count < 0)
                    return false;
            }
        }

        // Should be zero if balanced
        return $count === 0;
    }

    /**
     * Validate boolean parameter
     *
     * @param mixed $value Value to validate
     * @param string $param_name Parameter name for error message
     * @return void
     * @throws InvalidArgumentException
     */
    protected function validate_boolean_param($value, $param_name)
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException("Parameter {$param_name} must be boolean, " . gettype($value) . " given.");
        }
    }

    /**
     * Validate column or custom expression
     *
     * @param string $column Column name or expression
     * @param bool $is_custom_expression Whether this is a custom expression
     * @return void
     * @throws InvalidArgumentException
     */
    protected function validate_column_or_expression($column, $is_custom_expression)
    {
        $this->validate_boolean_param($is_custom_expression, 'is_custom_expression');

        if ($is_custom_expression) {
            if (!$this->is_valid_custom_expression($column)) {
                throw new InvalidArgumentException("Invalid custom expression: {$column}. Custom expressions can only contain column names, aggregate functions, and mathematical operators.");
            }
        } else {
            if (!$this->is_valid_column_name($column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}. Column names can only contain alphanumeric characters, underscores, and dots.");
            }
        }
    }

    /**
     * Process and validate keys (foreign or local)
     *
     * @param string|array $keys Key(s) to process
     * @param string $key_type Type of key for error message ('local key', 'foreign key', etc.)
     * @param bool $extract_from_dot Unused; kept for backward-compatible signature. Previously,
     *        when true, only the segment after the LAST dot was validated while the full
     *        original key (including whatever came before the dot) was stored — meaning a key
     *        like "id) UNION SELECT ...-- .name" would validate ("name" alone passes) while the
     *        malicious prefix survived untouched. is_valid_column_name() already validates a
     *        full "table.column" string in one shot (anchored, single dot), so the key is now
     *        always validated in full regardless of this flag.
     * @return array Processed keys
     * @throws InvalidArgumentException
     */
    protected function process_keys($keys, $key_type = 'key', $extract_from_dot = true)
    {
        $processed = [];
        $keys_array = is_array($keys) ? $keys : [$keys];
        foreach ($keys_array as $key) {
            if (!$this->is_valid_column_name($key)) {
                throw new InvalidArgumentException("Invalid {$key_type}: {$key}. Keys can only contain alphanumeric characters, underscores, and a single dot separator.");
            }
            $processed[] = $key;
        }
        return $processed;
    }

    /**
     * Validate that two key arrays have matching counts
     *
     * @param array $keys1 First key array
     * @param array $keys2 Second key array
     * @param string $message Error message
     * @return void
     * @throws InvalidArgumentException
     */
    protected function validate_key_count_match($keys1, $keys2, $message = 'Number of foreign keys must match number of local keys')
    {
        if (count($keys1) !== count($keys2)) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * Qualify a foreign/local key with a default table alias unless it already
     * carries its own table qualifier (contains a dot).
     *
     * Centralizes a "check for a dot, else prepend a default alias" pattern that
     * used to be repeated (with a different default-alias argument each time)
     * across every pending-condition processor: process_pending_where_has(),
     * process_pending_aggregates(), process_pending_where_aggregates(),
     * process_single_where_exists(), and the nested-aggregate path inside
     * load_single_relation().
     *
     * @param string $key Column name, optionally already table-qualified ("table.col")
     * @param string $default_alias Table/alias to prefix $key with when it has no qualifier of its own
     * @return string
     */
    protected function _qualify_key($key, $default_alias)
    {
        return strpos($key, '.') !== false ? $key : $default_alias . '.' . $key;
    }

    /**
     * Backtick-quote an already-validated identifier, preserving its
     * bare/dotted shape (does NOT qualify a bare identifier with any prefix —
     * that is what _qualify_key()/_quote_agg_column() are for).
     *
     * "col" -> "`col`", "tbl.col" -> "`tbl`.`col`".
     *
     * Defense-in-depth for callers (e.g. order_by_relation()) that already
     * validate the identifier with is_valid_table_name()/is_valid_column_name()
     * (alnum/underscore, optional single dot) and interpolate it into raw SQL:
     * quoting here means a future relaxation of those regexes (e.g. to allow
     * "AS alias") would not by itself reopen an injection path.
     *
     * @param string $identifier Validated identifier, optionally "table.column"
     * @return string
     */
    protected function _quote_identifier($identifier)
    {
        if (strpos($identifier, '.') !== false) {
            list($part1, $part2) = explode('.', $identifier, 2);
            return '`' . $part1 . '`.`' . $part2 . '`';
        }
        return '`' . $identifier . '`';
    }

    /**
     * Quote a column reference for use inside an aggregate function.
     *
     * When $column already contains a table qualifier (e.g. "tbl.col") it is
     * quoted as `tbl`.`col`.  Otherwise it is prefixed with $relation_ref
     * producing `relation_ref`.`col`.
     *
     * This handles both plain tables AND subquery aliases transparently.
     *
     * @param string $column       Column name, optionally table-qualified
     * @param string $relation_ref Table name or subquery alias to use as prefix
     * @return string              Safely quoted SQL fragment
     */
    protected function _quote_agg_column($column, $relation_ref)
    {
        if (strpos($column, '.') !== false) {
            list($tbl, $col) = explode('.', $column, 2);
            return '`' . $tbl . '`.`' . $col . '`';
        }
        return '`' . $relation_ref . '`.`' . $column . '`';
    }

    /**
     * Prefix bare (unqualified) identifiers in a validated custom aggregate
     * expression with the subquery alias, so the expression resolves against
     * the correlated subquery's own table instead of leaking out to whatever
     * table happens to be in scope outside it.
     *
     * Skips: SQL keywords/functions, identifiers already qualified with a dot
     * ("table.col"), and identifiers already backtick-quoted ("`col`").
     *
     * BUG FIX: this used to be done with a regex callback that tested
     * `strpos($matches[0], '.') !== false` to detect an already-qualified
     * identifier — but $matches[0] is only ever the identifier itself (the
     * pattern never includes the dot), so that check could never be true.
     * A custom expression like "scores.value" got EVERY bare identifier
     * prefixed independently, producing invalid double-qualified SQL like
     * "`sub`.`scores`.`sub`.`value`". The same blind-spot let a backtick-
     * quoted column get double-backtick-mangled the same way.
     *
     * @param string $expression   Already-validated custom expression
     * @param string $subquery_alias Alias to prefix bare identifiers with
     * @return string
     */
    protected function _prefix_bare_identifiers($expression, $subquery_alias)
    {
        // BUG FIX: this used to hardcode a small local $keywords list (SUM, AVG,
        // COUNT, MAX, MIN, CASE, WHEN, THEN, ELSE, END, AND, OR, NOT) instead of
        // reusing self::$ALLOWED_SQL_FUNCTIONS. Any other whitelisted function
        // name (ROUND, FLOOR, COALESCE, CONCAT, ...) fell through to the "bare
        // column" branch and got wrongly qualified, e.g. ROUND(price, 2) became
        // `sub`.`ROUND`(price, 2) — invalid SQL for an otherwise valid, validated
        // custom expression.
        $keywords = self::$ALLOWED_SQL_FUNCTIONS;

        if (!preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $expression, $matches, PREG_OFFSET_CAPTURE))
            return $expression;

        $result = '';
        $cursor = 0;
        foreach ($matches[1] as $match) {
            list($identifier, $offset) = $match;
            $result .= substr($expression, $cursor, $offset - $cursor);

            $prev_char = $offset > 0 ? $expression[$offset - 1] : '';
            $after_offset = $offset + strlen($identifier);
            $next_char = isset($expression[$after_offset]) ? $expression[$after_offset] : '';

            if (
                in_array(strtoupper($identifier), $keywords)
                || $prev_char === '.' || $next_char === '.'
                || $prev_char === '`' || $next_char === '`'
            ) {
                // Already dotted (this identifier is either the "table" or the
                // "col" half of "table.col") or already backtick-quoted — leave
                // it exactly as-is instead of prefixing.
                $result .= $identifier;
            } else {
                $result .= "`{$subquery_alias}`.`{$identifier}`";
            }

            $cursor = $after_offset;
        }
        $result .= substr($expression, $cursor);

        return $result;
    }
}
