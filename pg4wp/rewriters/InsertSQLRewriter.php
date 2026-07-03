<?php

class InsertSQLRewriter extends AbstractSQLRewriter
{
    private const ON_DUPLICATE_KEY_UPDATE = 'ON DUPLICATE KEY UPDATE';

    public function rewrite(): string
    {
        global $wpdb;

        $sql = $this->original();

        $sql = str_replace('(0,', "('0',", $sql);
        $sql = str_replace('(1,', "('1',", $sql);

        $sql = $this->convertInsertSetToValues($sql);
        $sql = $this->fixCategoryInsert($sql, $wpdb);
        $sql = str_replace("'0000-00-00 00:00:00'", "now() AT TIME ZONE 'gmt'", $sql);
        $sql = $this->splitMultiValues($sql, $wpdb);
        $sql = $this->handleOnDuplicateKeyUpdate($sql);
        $sql = $this->handleInsertIgnore($sql);
        $sql = $this->ensureUtf8Encoding($sql);
        $sql = $this->ensureReturningClause($sql);

        return $sql;
    }

    private function convertInsertSetToValues(string $sql): string
    {
        if (1 !== preg_match('/^INSERT\s+INTO\s+(.+?)\s+SET\s+(.+)$/is', $sql, $m)) {
            return $sql;
        }
        $pairs = explode(',', $m[2]);
        $cols = [];
        $vals = [];
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            $eqPos = strpos($pair, '=');
            if ($eqPos !== false) {
                $cols[] = trim(substr($pair, 0, $eqPos));
                $vals[] = trim(substr($pair, $eqPos + 1));
            }
        }
        return sprintf('INSERT INTO %s (%s) VALUES (%s)', $m[1], implode(', ', $cols), implode(', ', $vals));
    }

    private function fixCategoryInsert(string $sql, object $wpdb): string
    {
        if (false === strpos($sql, 'INSERT INTO ' . $wpdb->categories)) {
            return $sql;
        }
        $sql = str_replace('"cat_ID",', '', $sql);
        return str_replace("VALUES ('0',", 'VALUES(', $sql);
    }

    private function splitMultiValues(string $sql, object $wpdb): string
    {
        if (false === strpos($sql, $wpdb->options) || false === strpos($sql, '), (')) {
            return $sql;
        }
        preg_match('/INSERT INTO.+VALUES/', $sql, $matches);
        $insert = $matches[0];
        return str_replace('), (', ');' . $insert . '(', $sql);
    }

    private function handleOnDuplicateKeyUpdate(string $sql): string
    {
        if (false === $pos = strpos($sql, self::ON_DUPLICATE_KEY_UPDATE)) {
            return $sql;
        }
        $statements = $this->splitStatements($sql);
        $converted = [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }
            $statement = str_replace('`', '"', $statement);
            $insertIndex = strpos($statement, 'INSERT INTO');
            $valuesIndex = strpos($statement, 'VALUES');
            $oduIndex = strpos($statement, self::ON_DUPLICATE_KEY_UPDATE);
            $tableSection = trim(substr($statement, $insertIndex, $valuesIndex - $insertIndex));
            $valuesSection = trim(substr($statement, $valuesIndex, $oduIndex - $valuesIndex));
            $updateSection = trim(str_replace(self::ON_DUPLICATE_KEY_UPDATE, '', substr($statement, $oduIndex)));
            $updateCols = array_map(function ($col) {
                return trim(explode('=', $col)[0]);
            }, explode(',', $updateSection));
            $primaryKey = $this->choosePrimaryKey($updateCols);
            $updateSet = implode(', ', array_map(fn($col) => "$col = EXCLUDED.$col", $updateCols));
            $converted[] = sprintf('%s %s ON CONFLICT (%s) DO UPDATE SET %s', $tableSection, $valuesSection, $primaryKey, $updateSet);
        }
        return implode('; ', $converted);
    }

    private function choosePrimaryKey(array $cols): string
    {
        if (in_array('option_name', $cols)) {
            return 'option_name';
        }
        if (in_array('meta_name', $cols)) {
            return 'meta_name';
        }
        return $cols[0] ?? '';
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

    private function handleInsertIgnore(string $sql): string
    {
        if (0 !== strpos($sql, 'INSERT IGNORE')) {
            return $sql;
        }
        return 'INSERT' . substr($sql, 13) . ' ON CONFLICT DO NOTHING';
    }

    private function ensureUtf8Encoding(string $sql): string
    {
        if (preg_match('/^.{1}/us', $sql, $ar) == 1) {
            return $sql;
        }
        return utf8_encode($sql);
    }

    private function ensureReturningClause(string $sql): string
    {
        if (false !== strpos($sql, 'RETURNING')) {
            return $sql;
        }
        return rtrim($sql, "; \t\n\r\0\x0B") . ' RETURNING *;';
    }
}
