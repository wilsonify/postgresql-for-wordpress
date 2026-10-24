<?php

class ReplaceIntoSQLRewriter extends AbstractSQLRewriter
{
    public function rewrite(): string
    {
        $sql = $this->original();
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
        $insertIndex = strpos($statement, 'REPLACE INTO');
        $columnsStartIndex = strpos($statement, '(');
        $columnsEndIndex = strpos($statement, ')');
        $valuesIndex = strpos($statement, 'VALUES');

        // Extract SQL components
        $tableSection = trim(substr($statement, $insertIndex, $columnsStartIndex - $insertIndex));
        $valuesSection = trim(substr($statement, $valuesIndex, strlen($statement) - $valuesIndex));
        $columnsSection = trim(substr($statement, $columnsStartIndex, $columnsEndIndex - $columnsStartIndex + 1));

        // Extract and clean up column names
        $updateCols = array_map(
            'trim',
            explode(',', substr($columnsSection, 1, strlen($columnsSection) - 2))
        );

        // Choose a primary key for ON CONFLICT
        $primaryKey = $this->choosePrimaryKey($updateCols);

        // SWAP REPLACE INTO for INSERT INTO
        $tableSection = str_replace('REPLACE INTO', 'INSERT INTO', $tableSection);

        // Construct the PostgreSQL ON CONFLICT DO UPDATE section
        $updateSection = $this->buildUpdateSection($updateCols, $primaryKey);

        // Construct the PostgreSQL query
        $postgresSQL = sprintf(
            '%s %s %s ON CONFLICT (%s) DO UPDATE SET %s',
            $tableSection,
            $columnsSection,
            $valuesSection,
            $primaryKey,
            $updateSection
        );

        return $this->appendReturning($postgresSQL);
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

    private function buildUpdateSection(array $updateCols, string $primaryKey): string
    {
        $updateSection = '';

        foreach ($updateCols as $col) {
            if ($col !== $primaryKey) {
                $updateSection .= ', ';
                $updateSection .= "$col = EXCLUDED.$col";
            }
        }

        // trim any preceding commas
        return ltrim($updateSection, ', ');
    }

    private function appendReturning(string $postgresSQL): string
    {
        if (false !== strpos($postgresSQL, 'RETURNING')) {
            return $postgresSQL;
        }

        $endOfStatement = $this->findSemicolon($postgresSQL);
        if ($endOfStatement === false) {
            return $postgresSQL . ' RETURNING *';
        }

        // Splice the SQL string together with 'RETURNING *'
        return substr($postgresSQL, 0, $endOfStatement) . ' RETURNING *' . substr($postgresSQL, $endOfStatement);
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
