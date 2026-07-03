<?php

class DropTableSQLRewriter extends AbstractSQLRewriter
{
    public function rewrite(): string
    {
        $sql = $this->original();

        $pattern = '/DROP TABLE\s+(?:IF EXISTS\s+)?`?(\w+)`?/i';
        preg_match($pattern, $sql, $matches);
        if (empty($matches)) {
            return $sql;
        }
        $table = $matches[1];
        $seq = $table . '_seq';
        $sql = rtrim($sql, ";\n\r\t ");
        $sql .= ";\nDROP SEQUENCE IF EXISTS $seq;";

        return $sql;
    }
}
