<?php

class InsertSQLRewriter extends AbstractSQLRewriter
{
    private const ON_DUPLICATE_KEY = 'ON DUPLICATE KEY UPDATE';

    public function rewrite(): string
    {
        global $wpdb;

        $sql = $this->original();

        // Those are used when we need to set the date to now() in gmt time
        $sql = str_replace("'0000-00-00 00:00:00'", 'now() AT TIME ZONE \'gmt\'', $sql);

        $sql = str_replace("utc_timestamp()", "CURRENT_TIMESTAMP AT TIME ZONE 'UTC'", $sql);

        // Multiple values group when calling INSERT INTO don't always work
        if (false !== strpos($sql, $wpdb->options) && false !== strpos($sql, '), (')) {
            $sql = $this->splitMultipleValues($sql);
        }

        // Swap ON DUPLICATE KEY SYNTAX
        if (false !== strpos($sql, self::ON_DUPLICATE_KEY)) {
            $sql = $this->rewriteOnDuplicateKey($sql);
        } elseif (0 === strpos($sql, 'INSERT IGNORE')) {
            // Note: Requires PostgreSQL 9.5
            $sql = 'INSERT' . substr($sql, 13) . ' ON CONFLICT DO NOTHING';
        }

        // To avoid Encoding errors when inserting data coming from outside
        if (preg_match('/^./us', $sql, $ar) != 1) {
            $sql = utf8_encode($sql);
        }

        return $this->appendReturning($sql);
    }

    private function splitMultipleValues(string $sql): string
    {
        $pattern = '/INSERT INTO.+VALUES/';
        preg_match($pattern, $sql, $matches);
        $insert = $matches[0];

        return str_replace('), (', ');' . $insert . '(', $sql);
    }

    private function rewriteOnDuplicateKey(string $sql): string
    {
        $convertedStatements = [];

        foreach ($this->splitStatements($sql) as $statement) {
            $statement = trim($statement);

            // Skip empty statements
            if (empty($statement)) {
                continue;
            }

            $convertedStatements[] = $this->convertStatement($statement);
        }

        return implode('; ', $convertedStatements);
    }

    private function convertStatement(string $statement): string
    {
        // Replace backticks with double quotes for PostgreSQL compatibility
        $statement = str_replace('`', '"', $statement);

        // Find index positions for the SQL components
        $insertIndex = strpos($statement, 'INSERT INTO');
        $valuesIndex = strpos($statement, 'VALUES');
        $onDuplicateKeyIndex = strpos($statement, self::ON_DUPLICATE_KEY);

        // Extract SQL components
        $tableSection = trim(substr($statement, $insertIndex, $valuesIndex - $insertIndex));
        $valuesSection = trim(substr($statement, $valuesIndex, $onDuplicateKeyIndex - $valuesIndex));
        $updateSection = trim(str_replace(self::ON_DUPLICATE_KEY, '', substr($statement, $onDuplicateKeyIndex)));

        // Extract and clean up column names from the update section
        $updateCols = array_map(function ($col) {
            return trim(explode('=', $col)[0]);
        }, explode(',', $updateSection));

        // Construct the PostgreSQL ON CONFLICT DO UPDATE section
        $primaryKey = $this->choosePrimaryKey($updateCols);
        $updateSection = implode(', ', array_map(fn ($col) => "$col = EXCLUDED.$col", $updateCols));

        // Construct the PostgreSQL query
        return sprintf(
            '%s %s ON CONFLICT (%s) DO UPDATE SET %s',
            $tableSection,
            $valuesSection,
            $primaryKey,
            $updateSection
        );
    }

    private function choosePrimaryKey(array $updateCols): string
    {
        if (in_array('option_name', $updateCols)) {
            return 'option_name';
        }

        if (in_array('meta_name', $updateCols)) {
            return 'meta_name';
        }

        return $updateCols[0] ?? '';
    }

    private function appendReturning(string $sql): string
    {
        if (false !== strpos($sql, 'RETURNING')) {
            return $sql;
        }

        $endOfStatement = $this->findSemicolon($sql);
        if ($endOfStatement === false) {
            return $sql . ' RETURNING *';
        }

        // Splice the SQL string together with 'RETURNING *'
        return substr($sql, 0, $endOfStatement) . ' RETURNING *' . substr($sql, $endOfStatement);
    }

    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;

        for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
            $char = $sql[$i];

            if ($quote) {
                if ($char === $quote && $sql[$i - 1] !== '\\') {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === ';') {
                $statements[] = $buffer . ';';
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (!empty($buffer)) {
            $statements[] = $buffer;
        }

        return $statements;
    }

    // finds semicolons that aren't in variables
    private function findSemicolon($sql)
    {
        $quoteOpened = false;
        $parenthesisDepth = 0;

        $sqlAsArray = str_split($sql);
        for ($i = 0; $i < count($sqlAsArray); $i++) {
            if (($sqlAsArray[$i] == '"' || $sqlAsArray[$i] == "'") && ($i == 0 || $sqlAsArray[$i - 1] != '\\')) {
                $quoteOpened = !$quoteOpened;
            } elseif ($sqlAsArray[$i] == '(' && !$quoteOpened) {
                $parenthesisDepth++;
            } elseif ($sqlAsArray[$i] == ')' && !$quoteOpened) {
                $parenthesisDepth--;
            } elseif ($sqlAsArray[$i] == ';' && !$quoteOpened && $parenthesisDepth == 0) {
                return $i;
            }
        }

        return false;
    }
}
