<?php

class SelectSQLRewriter extends AbstractSQLRewriter
{
    public function rewrite(): string
    {
        global $wpdb;

        $sql = $this->original();

        $sql = $this->handleCalcFoundRows($sql);
        $sql = $this->handleFoundRows($sql);
        $sql = $this->ensureOrderByInSelect($sql);

        // Convert CONVERT to CAST
        $pattern = '/CONVERT\(([^()]*(\(((?>[^()]+)|(?-2))*\))?[^()]*),\s*([^\s]+)\)/x';
        $sql = preg_replace($pattern, 'CAST($1 AS $4)', $sql);

        // Handle CAST( ... AS CHAR)
        $sql = preg_replace('/CAST\((.+) AS CHAR\)/', 'CAST($1 AS TEXT)', $sql);

        // Handle CAST( ... AS SIGNED)
        $sql = preg_replace('/CAST\((.+) AS SIGNED\)/', 'CAST($1 AS INTEGER)', $sql);

        // Handle COUNT(...) ORDER BY ... (only when ORDER BY directly follows COUNT, no intervening FROM)
        $sql = preg_replace('/(COUNT\s*\([^)]*\))\s+ORDER\s+BY\s+.+$/im', '$1', $sql);

        // In order for users counting to work...
        // Only apply within the SELECT clause to avoid corrupting GROUP BY
        $selPattern = '/^\s*SELECT\s+(.*?)\s+FROM\s+/is';
        if (preg_match($selPattern, $sql, $selMatches)) {
            $selectPart = $selMatches[1];
            $matches = array();
            if(preg_match_all('/COUNT[^C]+\),/', $selectPart, $matches)) {
                foreach($matches[0] as $num => $one) {
                    $sub = substr($one, 0, -1);
                    $selectPart = str_replace($sub, $sub . ' AS count' . $num, $selectPart);
                }
            }
            $sql = str_replace($selMatches[1], $selectPart, $sql);
        }

        // Apply MySQL function conversions BEFORE clause-parsing to avoid
        // clause corruption from function rewrites that change column patterns.
        $sql = $this->applyMySqlFunctionConversions($sql);

        $sql = $this->convertToPostgresLimitSyntax($sql);
        $sql = $this->ensureGroupByOrAggregate($sql);

        // Act like MySQL default configuration, where sql_mode is ""
        $pattern = '/@@SESSION.sql_mode/';
        $sql = preg_replace($pattern, "''", $sql);

        if(isset($wpdb)) {
            $sql = str_replace('GROUP BY ' . $wpdb->prefix . 'posts.ID', '', $sql);
        }
        $sql = str_replace("!= ''", '<> 0', $sql);

        // MySQL 'LIKE' is case insensitive by default, whereas PostgreSQL 'LIKE' is
        // Use word-boundary regex to match LIKE with any surrounding whitespace
        $sql = preg_replace('/\s+LIKE\s+/i', ' ILIKE ', $sql);

        // INDEXES are not yet supported
        if(false !== strpos($sql, 'USE INDEX (comment_date_gmt)')) {
            $sql = str_replace('USE INDEX (comment_date_gmt)', '', $sql);
        }

        // HB : timestamp fix for permalinks
        $sql = str_replace('post_date_gmt > 1970', 'post_date_gmt > to_timestamp (\'1970\')', $sql);

        // Akismet sometimes doesn't write 'comment_ID' with 'ID' in capitals where needed ...
        if(isset($wpdb) && $wpdb->comments && false !== strpos($sql, $wpdb->comments)) {
            $sql = str_replace(' comment_id ', ' comment_ID ', $sql);
        }

        $sql = $this->handleHavingWithoutGroupBy($sql);

        // MySQL allows integers to be used as boolean expressions
        // where 0 is false and all other values are true.
        //
        // Although this could occur anywhere with any number, so far it
        // has only been observed as top-level expressions in the WHERE
        // clause and only with 0.  For performance, limit current
        // replacements to that.
        $pattern_after_where = '(?:\s*$|\s+(GROUP|HAVING|ORDER|LIMIT|PROCEDURE|INTO|FOR|LOCK))';
        $pattern = '/(WHERE\s+)0(\s+AND|\s+OR|' . $pattern_after_where . ')/';
        $sql = preg_replace($pattern, '$1false$2', $sql);

        $pattern = '/(AND\s+|OR\s+)0(' . $pattern_after_where . ')/';
        $sql = preg_replace($pattern, '$1false$2', $sql);

        // MySQL supports strings as names, PostgreSQL needs identifiers.
        // Limit to after closing parenthesis to reduce false-positives
        // Currently only an issue for nextgen-gallery plugin
        $pattern = '/\) AS \'([^\']+)\'/';
        $sql = preg_replace($pattern, ') AS "$1"', $sql);

        return $sql;
    }

    protected function handleCalcFoundRows(string $sql): string
    {
        if (false === strpos($sql, 'SQL_CALC_FOUND_ROWS')) {
            return $sql;
        }
        $sql = str_replace('SQL_CALC_FOUND_ROWS', '', $sql);
        $GLOBALS['pg4wp_numrows_query'] = $sql;
        if (PG4WP_DEBUG) {
            error_log('[' . microtime(true) . "] Number of rows required for :\n$sql\n---------------------\n", 3, PG4WP_LOG . 'pg4wp_NUMROWS.log');
        }
        return $sql;
    }

    protected function handleFoundRows(string $sql): string
    {
        if (false === strpos($sql, 'FOUND_ROWS()')) {
            return $sql;
        }
        $sql = $GLOBALS['pg4wp_numrows_query'];
        $sql = preg_replace('/\s+LIMIT\s+\d+(\s*,\s*\d+)?/i', '', $sql);
        $sql = preg_replace('/\s+ORDER\s+BY\s+[^)]+/i', '', $sql);
        $sql = preg_replace('/SELECT\s+.*?\s+FROM\s+/is', 'SELECT COUNT(*) FROM ', $sql, 1);
        return $sql;
    }

    protected function handleHavingWithoutGroupBy(string $sql): string
    {
        if (false === strpos($sql, 'HAVING') || false !== strpos($sql, 'GROUP BY')) {
            return $sql;
        }
        if (false === strpos($sql, 'WHERE')) {
            return str_replace('HAVING', 'WHERE', $sql);
        }
        $pattern = '/WHERE\s+(.*?)\s+HAVING\s+(.*?)(\s*(?:ORDER|LIMIT|PROCEDURE|INTO|FOR|LOCK|$))/';
        return preg_replace($pattern, 'WHERE ($1) AND ($2) $3', $sql);
    }

    /**
     * Ensure the columns used in the ORDER BY clause are also present in the SELECT clause.
     *
     * @param string $sql Original SQL query string.
     * @return string Modified SQL query string.
     */
    protected function ensureOrderByInSelect(string $sql): string
    {
        // Extract the SELECT and ORDER BY clauses
        preg_match('/SELECT\s+(.*?)\s+FROM/si', $sql, $selectMatches);
        preg_match('/ORDER BY(.*?)(ASC|DESC|$)/si', $sql, $orderMatches);
        preg_match('/GROUP BY(.*?)(ASC|DESC|$)/si', $sql, $groupMatches);

        // If the SELECT clause is missing, return the original query
        if (!$selectMatches) {
            return $sql;
        }

        // If both ORDER BY and GROUP BY clauses are missing, return the original query
        if (!$orderMatches && !$groupMatches) {
            return $sql;
        }

        $selectClause = trim($selectMatches[1]);
        $orderByClause = $orderMatches ? trim($orderMatches[1]) : null;
        $groupClause = $groupMatches ? trim($groupMatches[1]) : null;

        // Check for wildcard in SELECT
        if (strpos($selectClause, '*') !== false) {
            return $sql; // Cannot handle wildcards, return original query
        }

        $clause = $this->ensureOrderByColumnsInSelect($selectClause, $orderByClause);
        if ($clause === $selectClause) {
            $clause = $this->ensureGroupByColumnsInSelect($selectClause, $groupClause);
        }
        if ($clause === $selectClause) {
            return $sql;
        }
        return $this->replaceSelectClause($sql, $selectMatches[1], $clause);
    }

    private function ensureOrderByColumnsInSelect(string $selectClause, ?string $orderByClause): string
    {
        if (!$orderByClause) {
            return $selectClause;
        }
        $clause = $selectClause;
        $columns = explode(',', $orderByClause);
        foreach ($columns as $col) {
            $col = trim($col);
            if (strpos($clause, $col) === false) {
                $clause .= ', ' . $col;
            }
        }
        return $clause;
    }

    private function ensureGroupByColumnsInSelect(string $selectClause, ?string $groupClause): string
    {
        if (!$groupClause) {
            return $selectClause;
        }
        $clause = $selectClause;
        $columns = explode(',', $groupClause);
        foreach ($columns as $col) {
            $col = trim($col);
            if (strpos($clause, $col) === false) {
                $clause .= ', ' . $col;
            }
        }
        return $clause;
    }

    private function replaceSelectClause(string $sql, string $originalClause, string $newClause): string
    {
        $pos = strpos($sql, $originalClause);
        if ($pos === false) {
            return $sql;
        }
        return substr_replace($sql, $newClause, $pos, strlen($originalClause));
    }

    /**
     * Transforms a given SQL query to include a GROUP BY clause if the SELECT statement has both aggregate
     * and non-aggregate columns. This function is specifically designed to work with PostgreSQL.
     *
     * In PostgreSQL, a query that uses aggregate functions must group by all columns in the SELECT list that
     * are not part of the aggregate functions. Failing to do so results in a syntax error. This function
     * automatically adds a GROUP BY clause to meet this PostgreSQL requirement when both aggregate (COUNT, SUM,
     * AVG, MIN, MAX) and non-aggregate columns are present.
     *
     * @param string $sql The SQL query string to be transformed.
     *
     * @return string The transformed SQL query string with appropriate GROUP BY clause if required.
     *
     * @throws Exception If the SQL query cannot be parsed or modified.
     *
     * @example
     * Input:  SELECT COUNT(id), username FROM users;
     * Output: SELECT COUNT(id), username FROM users GROUP BY username;
     *
     */
    protected function ensureGroupByOrAggregate(string $sql): string
    {
        if (preg_match('/@@[a-zA-Z0-9_]+/', $sql) || preg_match('/\bSELECT\b.*\bFROM\b.*\bSELECT\b/is', $sql)) {
            return $sql;
        }

        $regex = '/\A(SELECT\s+)(.*?)(\s+FROM\s+)((?:\w+(?:\s*\w+)*\s*(?:INNER|LEFT|RIGHT|CROSS|JOIN|,\s*\w+)*\s*\w*))(\s+WHERE\s+.*?)?(\s+GROUP\s+BY\s+.*?)?(\s+HAVING\s+.*?)?(\s+ORDER\s+BY\s+.*?)?(\s+LIMIT\s+.*?)?\Z/is';

        if (!preg_match($regex, $sql, $matches)) {
            return $sql;
        }

        $selectClause = trim($matches[2] ?? '');
        $fromClause = trim($matches[4] ?? '');

        if (empty($selectClause) || empty($fromClause)) {
            return $sql;
        }

        $whereClause = trim($matches[5] ?? '');
        $groupClause = trim($matches[6] ?? '');
        $havingClause = trim($matches[7] ?? '');
        $orderClause = trim($matches[8] ?? '');
        $limitClause = trim($matches[9] ?? '');

        $pattern = '/,(?![^\(]*\))/';
        $columns = array_map('trim', preg_split($pattern, $selectClause));

        $aggregateColumns = [];
        $nonAggregateColumns = [];

        foreach ($columns as $col) {
            if (preg_match('/(COUNT|SUM|AVG|MIN|MAX)\s*?\(/i', $col)) {
                $aggregateColumns[] = $col;
            } else {
                $nonAggregateColumns[] = $col;
            }
        }

        if (empty($aggregateColumns) || empty($nonAggregateColumns)) {
            return $sql;
        }

        return $this->buildGroupedQuery($selectClause, $fromClause, $whereClause, $groupClause, $nonAggregateColumns, $havingClause, $orderClause, $limitClause);
    }

    protected function buildGroupedQuery(string $select, string $from, string $where, string $group, array $nonAggCols, string $having, string $order, string $limit): string
    {
        $sql = "SELECT $select FROM $from";
        if (!empty($where))  { $sql .= " $where"; }
        if (!empty($group))  { $sql .= " $group"; }
        elseif (!empty($nonAggCols)) { $sql .= ' GROUP BY ' . implode(", ", $nonAggCols); }
        if (!empty($having)) { $sql .= " $having"; }
        if (!empty($order))  { $sql .= " $order"; }
        if (!empty($limit))  { $sql .= " $limit"; }
        return $sql;
    }

    protected function applyMySqlFunctionConversions(string $sql): string
    {
        $pattern = '/DATE_ADD\s*\(((?:[^()]+|\([^()]*\))*)\s*,\s*((?:[^()]+|\([^()]*\))*)\)/i';
        $sql = preg_replace($pattern, '($1 + $2)', $sql);

        $pattern = '/DATE_SUB\s*\(((?:[^()]+|\([^()]*\))*)\s*,\s*((?:[^()]+|\([^()]*\))*)\)/i';
        $sql = preg_replace($pattern, '($1::timestamp - $2)', $sql);

        $pattern = '/FIELD[ ]*\(([^\),]+),([^\)]+)\)/';
        $sql = preg_replace_callback($pattern, function ($matches) {
            $case = 'CASE ' . trim($matches[1]);
            $comparands = explode(',', $matches[2]);
            foreach ($comparands as $i => $comparand) {
                $case .= ' WHEN ' . trim($comparand) . ' THEN ' . ($i + 1);
            }
            $case .= ' ELSE 0 END';
            return $case;
        }, $sql);

        $pattern = '/GROUP_CONCAT\(([^()]*(\(((?>[^()]+)|(?-2))*\))?[^()]*)\)/x';
        $sql = preg_replace($pattern, "string_agg($1, ',')", $sql);

        $pattern = '/RAND[ ]*\([ ]*\)/';
        $sql = preg_replace($pattern, 'RANDOM()', $sql);

        $pattern = '/UNIX_TIMESTAMP\(([^\)]+)\)/';
        $sql = preg_replace($pattern, 'ROUND(DATE_PART(\'epoch\',$1))', $sql);

        $date_funcs = [
            'DAYOFMONTH(' => 'EXTRACT(DAY FROM ',
            'YEAR('       => 'EXTRACT(YEAR FROM ',
            'MONTH('      => 'EXTRACT(MONTH FROM ',
            'DAY('        => 'EXTRACT(DAY FROM ',
        ];

        $sql = str_replace('ORDER BY post_date DESC', 'ORDER BY YEAR(post_date) DESC, MONTH(post_date) DESC', $sql);
        $sql = str_replace('ORDER BY post_date ASC', 'ORDER BY YEAR(post_date) ASC, MONTH(post_date) ASC', $sql);
        $sql = str_replace(array_keys($date_funcs), array_values($date_funcs), $sql);
        $curryear = date('Y');
        $sql = str_replace('FROM \'' . $curryear, 'FROM TIMESTAMP \'' . $curryear, $sql);

        $pattern = '/(?<!NULL)IF\s*\(((?:[^()]+|\([^()]*\))*)\s*,\s*((?:[^()]+|\([^()]*\))*)\s*,\s*((?:[^()]+|\([^()]*\))*)\)/i';
        $sql = preg_replace($pattern, 'CASE WHEN $1 THEN $2 ELSE $3 END', $sql);

        return $sql;
    }

    /**
     * Convert MySQL LIMIT syntax to PostgreSQL LIMIT syntax
     *
     * @param string $sql MySQL query string
     * @return string PostgreSQL query string
     */
    protected function convertToPostgresLimitSyntax($sql)
    {
        // Use regex to find "LIMIT m, n" syntax in query
        if (preg_match('/LIMIT\s+(\d+),\s*(\d+)/i', $sql, $matches)) {
            $offset = $matches[1];
            $limit = $matches[2];

            // Replace MySQL LIMIT syntax with PostgreSQL LIMIT syntax
            $postgresLimitSyntax = "LIMIT $limit OFFSET $offset";
            $postgresSql = preg_replace('/LIMIT\s+\d+,\s*\d+/i', $postgresLimitSyntax, $sql);

            return $postgresSql;
        }

        // Return original query if no MySQL LIMIT syntax is found
        return $sql;
    }

}
