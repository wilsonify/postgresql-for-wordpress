<?php

class ShowVariablesSQLRewriter extends AbstractSQLRewriter
{
    public function rewrite(): string
    {
        $sql = $this->original();
        $variableName = $this->extractVariableName($sql);
        return $this->generatePostgres($sql, $variableName);
    }

    /**
     * Extracts table name from a "SHOW FULL COLUMNS" SQL statement.
     *
     * @param string $sql The SQL statement
     * @return string|null The table name if found, or null otherwise
     */
    protected function extractVariableName($sql)
    {
        $pattern = "/SHOW VARIABLES LIKE ['\"`]?([^'\"`]+)['\"`]?/i";
        if (preg_match($pattern, $sql, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Generates a PostgreSQL-compatible SQL query to mimic MySQL's "SHOW VARIABLES".
     *
     * @param string $tableName The table name
     * @return string The generated SQL query
     */
    public function generatePostgres($sql, $variableName)
    {
        if ($variableName == "sql_mode") {
            return "SELECT '$variableName' AS \"Variable_name\", '' AS \"Value\";";
        }

        $hardcoded = [
            'character_set_database' => 'utf8',
            'collation_database' => 'en_US.UTF-8',
            'character_set_server' => 'utf8',
            'collation_server' => 'en_US.UTF-8',
            'max_allowed_packet' => '1073741824',
            'max_execution_time' => '30000',
            'innodb_lock_wait_timeout' => '50',
            'wait_timeout' => '28800',
        ];

        if (isset($hardcoded[$variableName])) {
            return "SELECT '$variableName' AS \"Variable_name\", '{$hardcoded[$variableName]}' AS \"Value\";";
        }

        return $sql;
    }
}
