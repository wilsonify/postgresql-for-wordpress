# Data Model: PG4WP SQL Rewrite Engine

**Last Updated**: 2026-07-02  
**Derived from**: `specs/001-pg4wp-core/plan.md`

---

## Core Entities

### SQLRewriter (Abstract)

Base class for all rewriters. Defines the contract for SQL transformation.

| Field | Type | Description |
|-------|------|-------------|
| `$sql` | `string` | Original MySQL SQL input |
| `$logger` | `callable|null` | Logging function for debug output |

**Methods**:
- `rewrite(): string` — Transforms MySQL SQL to PostgreSQL SQL
- `getType(): string` — Returns the SQL statement type (SELECT, INSERT, etc.)

### Implementations

| Entity | File | Transforms | Coverage |
|--------|------|------------|----------|
| `SelectSQLRewriter` | `rewriters/SelectSQLRewriter.php` | LIMIT, GROUP BY, functions, FOUND_ROWS | Highest |
| `InsertSQLRewriter` | `rewriters/InsertSQLRewriter.php` | ON DUPLICATE KEY, INSERT IGNORE, INSERT SET | High |
| `UpdateSQLRewriter` | `rewriters/UpdateSQLRewriter.php` | LIMIT stripping, backtick removal | Medium |
| `DeleteSQLRewriter` | `rewriters/DeleteSQLRewriter.php` | Multi-table DELETE, REGEXP→~ | Medium |
| `CreateTableSQLRewriter` | `rewriters/CreateTableSQLRewriter.php` | AUTO_INCREMENT→SERIAL, type mapping, INDEX extraction | High |
| `AlterTableSQLRewriter` | `rewriters/AlterTableSQLRewriter.php` | CHANGE COLUMN, ADD/DROP INDEX | Medium |
| `ShowTablesSQLRewriter` | `rewriters/ShowTablesSQLRewriter.php` | SHOW TABLES→pg_catalog query | Complete |
| `ShowVariablesSQLRewriter` | `rewriters/ShowVariablesSQLRewriter.php` | Hardcoded MySQL variables | Complete |
| `ShowFullColumnsSQLRewriter` | `rewriters/ShowFullColumnsSQLRewriter.php` | SHOW FULL COLUMNS→pg_catalog | Complete |
| `ShowIndexSQLRewriter` | `rewriters/ShowIndexSQLRewriter.php` | SHOW INDEX→pg_class query | Complete |
| `DescribeSQLRewriter` | `rewriters/DescribeSQLRewriter.php` | DESCRIBE→information_schema | Complete |
| `SetNamesSQLRewriter` | `rewriters/SetNamesSQLRewriter.php` | SET NAMES→no-op | Complete |
| `OptimizeTableSQLRewriter` | `rewriters/OptimizeTableSQLRewriter.php` | OPTIMIZE→VACUUM ANALYZE | Complete |
| `DropTableSQLRewriter` | `rewriters/DropTableSQLRewriter.php` | Standard DROP TABLE | Complete |
| `ReplaceIntoSQLRewriter` | `rewriters/ReplaceIntoSQLRewriter.php` | REPLACE INTO→INSERT ON CONFLICT DO UPDATE | Medium |

---

## Supporting Entities

### TestStub

| Field | Type | Description |
|-------|------|-------------|
| `mysql` | `string` | Input MySQL SQL |
| `postgresql` | `string` | Expected PostgreSQL output |

Stored as JSON files in `tests/stubs/`. Total: 316 files (510+ assertions).

### wpsqli_Driver Function

Each function in `driver_pgsql.php` maps one MySQLi function to PostgreSQL:

```
wpsqli_query($connection, $sql, $mode = MYSQLI_STORE_RESULT)
  → pg4wp_rewrite($sql)
  → pg_query($connection, $rewrittenSql)
  → $GLOBALS['pg4wp_result'] assign
```

### RewriteEngine

| Field | Type | Description |
|-------|------|-------------|
| `$sql` | `string` | Original SQL input |
| `$rewritten` | `string` | Rewritten SQL output |
| `$type` | `string` | Detected SQL statement type |
| `$insertId` | `array|null` | Cached INSERT ID metadata (table, field) |
| `$foundRowsQuery` | `string|null` | Cached count query for FOUND_ROWS() |
| `$connectionHandle` | `int` | spl_object_id of connection (for concurrent safety) |

---

## Key Relationships

```
RewriteEngine (1) ──detects──► SQLRewriter (1..*)
SQLRewriter (1) ──produces──► Rewritten SQL (1)
RewriteEngine (1) ──caches──► InsertIdCache (0..1)
RewriteEngine (1) ──caches──► FoundRowsCache (0..1)
TestStub (*) ──validates──► RewriteEngine (1)
```

## Type Mappings

| MySQL Type | PostgreSQL Type | Notes |
|------------|----------------|-------|
| `INT(N) AUTO_INCREMENT` | `SERIAL` | Width specifier stripped |
| `BIGINT(N) AUTO_INCREMENT` | `BIGSERIAL` | Width specifier stripped |
| `TINYINT(1)` | `SMALLINT` | Boolean interpretation not preserved |
| `DATETIME` | `TIMESTAMP` | Without timezone |
| `TIMESTAMP` | `TIMESTAMP` | MySQL auto-init handled separately |
| `ENUM('a','b')` | `SMALLINT` or `TEXT` | Based on context |
| `BINARY(N)` | `BYTEA` | |
| `VARBINARY(N)` | `BYTEA` | |
| `BOOL DEFAULT 0` | `BOOLEAN DEFAULT FALSE` | |
| `TINYBLOB` | `BYTEA` | |
| `BLOB` | `BYTEA` | |
| `MEDIUMBLOB` | `BYTEA` | |
| `LONGBLOB` | `BYTEA` | |
| `TINYTEXT` | `TEXT` | |
| `MEDIUMTEXT` | `TEXT` | |
| `LONGTEXT` | `TEXT` | |
| `YEAR(4)` | `SMALLINT` | |
| `FLOAT(N,D)` | `REAL` | Precision/scale args stripped |
| `DOUBLE(N,D)` | `DOUBLE PRECISION` | Precision/scale args stripped |
