<?php

class CreateTableSQLRewriter extends AbstractSQLRewriter
{
    private $stringReplacements = [
        'bigint(20)'    => 'bigint',
        'bigint(10)'    => 'int',
        'int(11)'        => 'int',
        'int(10)'        => 'int',
        'tinytext'        => 'text',
        'mediumtext'    => 'text',
        'longtext'        => 'text',
        'mediumblob'    => 'bytea',
        'longblob'        => 'bytea',
        'blob'            => 'bytea',
        'tinyblob'        => 'bytea',
        'unsigned'        => '',
        'gmt datetime NOT NULL default \'0000-00-00 00:00:00\''    => 'gmt timestamp NOT NULL DEFAULT timezone(\'gmt\'::text, now())',
        'default \'0000-00-00 00:00:00\''    => 'DEFAULT now()',
        '\'0000-00-00 00:00:00\''    => 'now()',
        'datetime'        => 'timestamp',
        'DEFAULT CHARACTER SET utf8mb4' => '',
        'DEFAULT CHARACTER SET utf8'    => '',

        // WP 2.7.1 compatibility
        'int(4)'        => 'smallint',

        // For WPMU (starting with WP 3.2)
        'tinyint(4)'    => 'smallint',
        'tinyint(2)'    => 'smallint',
        'tinyint(1)'    => 'smallint',
        "enum('0','1')"    => 'smallint',
        'COLLATE utf8mb4_unicode_520_ci'    => '',
        'COLLATE utf8_general_ci'    => '',

        // For flash-album-gallery plugin
        'tinyint'        => 'smallint',

        // MySQL-only types
        'year(4)'        => 'smallint',
        'year'            => 'smallint',
        'bool'            => 'boolean',
        'boolean'        => 'boolean',
        'float'            => 'double precision',
        'double'        => 'double precision',
    ];

    public function rewrite(): string
    {
        $sql = $this->original();

        $sql = str_ireplace('CREATE TABLE IF NOT EXISTS ', 'CREATE TABLE ', $sql);

        // Remove backticks so reserved word matching works correctly
        $sql = str_replace('`', '', $sql);

        $pattern = '/CREATE TABLE [`]?(\w+)[`]?/';
        preg_match($pattern, $sql, $matches);
        $table = $matches[1];

        // Remove trailing spaces
        $sql = trim($sql) . ';';

        // Translate types and some other replacements
        $sql = str_replace(
            array_keys($this->stringReplacements),
            array_values($this->stringReplacements),
            $sql
        );

        // Convert varbinary(N) and binary(N) to bytea
        $sql = preg_replace('/\bvarbinary\s*\(\s*\d+\s*\)/i', 'bytea', $sql);
        $sql = preg_replace('/\bbinary\s*\(\s*\d+\s*\)/i', 'bytea', $sql);

        // Quote PostgreSQL reserved words used as column names in CREATE TABLE
        $reservedCols = [
            'default', 'end', 'order', 'group', 'key', 'comment',
            'number', 'value', 'type', 'year', 'month', 'day',
            'hour', 'minute', 'second', 'zone', 'time', 'date',
        ];
        foreach ($reservedCols as $reserved) {
            $sql = preg_replace(
                '/,\s*\b(' . preg_quote($reserved, '/') . ')\s+(?=\w+)/i',
                ', "' . $reserved . '" ',
                $sql
            );
            $sql = preg_replace(
                '/\(\s*\b(' . preg_quote($reserved, '/') . ')\s+(?=\w+)/i',
                '( "' . $reserved . '" ',
                $sql
            );
        }

        // Fix auto_increment by adding a sequence
        $pattern = '/\w+(?:\s+NOT\s+NULL)?\s+auto_increment/i';
        preg_match($pattern, $sql, $matches);
        if($matches) {
            $seq = $table . '_seq';
            $sql = preg_replace('/NOT\s+NULL\s+auto_increment/i', "NOT NULL DEFAULT nextval('$seq'::text)", $sql);
            $sql = preg_replace('/\bauto_increment\b/i', "DEFAULT nextval('$seq'::text)", $sql);
            $sql .= "\nCREATE SEQUENCE IF NOT EXISTS $seq;";
        }

        // Support for INDEX creation
        $pattern = '/,\s+(UNIQUE |)KEY\s+([^\s]+)\s+\(((?:[\w]+(?:\([\d]+\))?[,]?)*)\)/';
        if(preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach($matches as $match) {
                $unique = $match[1];
                $index = $match[2];
                $columns = $match[3];
                $columns = preg_replace('/\(\d+\)/', '', $columns);
                // Workaround for index name duplicate
                $index = $table . '_' . $index;
                $sql .= "\nCREATE {$unique}INDEX $index ON $table ($columns);";
            }
        }
        // Now remove handled indexes
        $sql = preg_replace($pattern, '', $sql);

        return $sql;
    }
}
