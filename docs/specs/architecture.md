# PostgreSQL Backend for WordPress — Architecture Specification

**Version**: 1.0  
**Status**: Draft  
**Last Updated**: 2026-07-01  
**Project**: PG4WP — PostgreSQL for WordPress  
**Upstream**: https://github.com/PostgreSQL-For-Wordpress/postgresql-for-wordpress  
**Fork**: https://github.com/wilsonify/postgresql-for-wordpress

---

## 1. Executive Summary

WordPress and its ecosystem are designed exclusively for MySQL/MariaDB. Every WordPress function, every plugin, and every theme issues SQL queries that assume MySQL syntax, MySQL functions, and MySQL connection semantics. Replacing the database engine without changing the application layer requires a **database compatibility layer** — a shim that intercepts every database call and translates it from MySQL dialect to PostgreSQL dialect transparently.

PG4WP (PostgreSQL for WordPress) provides this layer. It operates as a WordPress drop-in plugin (`wp-content/db.php`) that:

1. Intercepts all `mysqli_*` function calls made by WordPress's `wpdb` class
2. Rewrites every SQL query from MySQL syntax to PostgreSQL syntax
3. Maps MySQL-specific behaviors (auto-increment, insert ID retrieval, error codes) to PostgreSQL equivalents

This specification describes the architecture, design decisions, and operational characteristics of the PG4WP compatibility layer in the context of a WordPress + WooCommerce deployment targeting PostgreSQL.

---

## 2. System Context

### 2.1 The Problem

WordPress's database abstraction layer (`wpdb`) was built around MySQL. It issues MySQL-specific SQL and uses MySQLi extension functions directly:

```
WordPress / Plugins / Themes
        │
        ▼
    wpdb class (wp-includes/class-wpdb.php)
        │
        ▼
    MySQLi extension (mysql_* / mysqli_* functions)
        │
        ▼
    MySQL / MariaDB Server
```

Switching to PostgreSQL breaks at every level:
- SQL syntax differences (`LIMIT m, n`, backtick quoting, `INSERT ... SET`)
- Missing functions (`YEAR()`, `MONTH()`, `FIELD()`, `RAND()`, `GROUP_CONCAT()`)
- Type system differences (`AUTO_INCREMENT`, `ENUM`, `BINARY`, `UNSIGNED`)
- Connection semantics (`DO 1` for ping, `SELECT FOUND_ROWS()` for pagination)
- Transaction and locking differences (`GET_LOCK()`, `RELEASE_LOCK()`)

### 2.2 The Solution Architecture

```
WordPress / Plugins / Themes
        │
        ▼
    wpdb2 (eval-patched copy of wpdb)
        │   - class wpdb → wpdb2
        │   - mysqli_* → wpsqli_*
        │
        ▼
    wpsqli_*() compatibility functions
        │   - driver_pgsql.php (PG implementation)
        │   - driver_mysql.php (MySQL passthrough, reference)
        │
        ▼
    SQL Rewrite Engine
        │   - pg4wp_rewrite() entry point
        │   - 15 rewriter classes (one per SQL command type)
        │
        ▼
    PostgreSQL native functions
        pg_query() / pg_fetch_*() / pg_affected_rows() / etc.
        │
        ▼
    PostgreSQL Server
```

### 2.3 Key Constraint: Zero Core Modifications

The defining architectural constraint is that **no WordPress core file is ever modified**. The entire compatibility layer lives in `wp-content/db.php` and its supporting files under `wp-content/pg4wp/`. This means:

- WordPress core updates are applied normally — no patching, no merge conflicts
- The compatibility layer can be enabled or disabled by removing one file
- Standard WordPress debugging and diagnostic tools continue to work
- Plugin and theme developers are unaware that PostgreSQL is backing the deployment

---

## 3. Design Decisions

### 3.1 Decision: eval()-Based wpdb Patching

**Status**: Current (targeted for removal in Phase 4)

**Problem**: WordPress's `wpdb` class calls MySQLi functions directly (`mysqli_query()`, `mysqli_fetch_assoc()`, etc.) and uses MySQL-specific type checks (`instanceof mysqli_result`). To intercept these calls without modifying `class-wpdb.php`, the class must be altered at runtime.

**Approach**: The file `wp-includes/class-wpdb.php` is read, undergoes a series of string replacements, and is then `eval()`'d:

| Source String | Replacement | Purpose |
|---|---|---|
| `class wpdb` | `class wpdb2` | Avoid class name collision |
| `mysqli_` | `wpsqli_` | Redirect all MySQLi calls |
| `instanceof mysqli_result` | `instanceof \PgSql\Result` | Type check compatibility |
| `instanceof mysqli` | `instanceof \PgSql\Connection` | Connection type compatibility |
| `new wpdb` | `new wpdb2` | Constructor call redirection |
| `is_resource(` | `wpsqli_is_resource(` | PHP 8.1+ resource compatibility |

**Trade-offs**:
- **Pro**: Zero core modifications, trivial to disable, automatically adapts to wpdb changes
- **Con**: Brittle — if the string patterns in `class-wpdb.php` change (e.g., whitespace, indentation), the replacements silently fail
- **Con**: `eval()` is flagged by security scanners and site health checks
- **Mitigation**: A pinned copy of `wp-includes/class-wpdb.php` is included in the repository for testing

### 3.2 Decision: wpsqli_ Function Namespace

**Status**: Permanent

All MySQLi functions are re-implemented under the `wpsqli_` prefix in two drivers:
- `driver_pgsql.php` — PostgreSQL implementation via `pg_*` functions
- `driver_mysql.php` — MySQL passthrough (for testing and as a reference implementation)

This allows the driver to be swapped by changing `DB_DRIVER` in `wp-config.php` without altering any code paths.

### 3.3 Decision: SQL Rewrite via Statement-Type Factory

**Status**: Permanent

**Problem**: MySQL and PostgreSQL differ in SQL syntax across every statement type. A monolithic rewrite function would be unmaintainable.

**Approach**: A factory function (`createSQLRewriter`) detects the SQL statement type by regex and delegates to a dedicated rewriter class:

```
Raw SQL → createSQLRewriter() → SelectSQLRewriter()
                              → InsertSQLRewriter()
                              → UpdateSQLRewriter()
                              → CreateTableSQLRewriter()
                              → AlterTableSQLRewriter()
                              → DeleteSQLRewriter()
                              → DropTableSQLRewriter()
                              → DescribeSQLRewriter()
                              → ShowFullColumnsSQLRewriter()
                              → ShowIndexSQLRewriter()
                              → ShowTablesSQLRewriter()
                              → ShowVariablesSQLRewriter()
                              → SetNamesSQLRewriter()
                              → OptimizeTableSQLRewriter()
```

Each rewriter implements an `AbstractSQLRewriter` interface with a single `rewrite(): string` method, making them independently testable and maintainable.

### 3.4 Decision: Stub-Based Regression Testing

**Status**: Permanent

**Problem**: Rewriting SQL is error-prone. Changes to one rewriter can break another. A test strategy that does not require a live database is essential.

**Approach**: Every test is a JSON fixture pair of MySQL input and expected PostgreSQL output:

```json
{"mysql": "SELECT * FROM wp_posts WHERE ID = 1",
 "postgresql": "SELECT * FROM wp_posts WHERE \"ID\" = 1"}
```

Tests run as pure PHPUnit assertions — no database connection required. This gives:
- Fast test execution (< 2s for 510+ assertions)
- No database setup or teardown
- Trivial to add new test cases
- Easy to verify no regressions after changes

### 3.5 Decision: INSERT ID via pg_query() Fallback

**Status**: Current (may evolve)

MySQL provides `mysqli_insert_id()` to retrieve the last auto-generated ID. PostgreSQL uses `RETURNING` clauses or sequence functions (`CURRVAL()`). PG4WP currently attempts `CURRVAL()` on a sequence named `<table>_seq`, which fails for tables without sequences.

**Trade-offs**:
- **Pro**: Simple, works for the common case (tables with `SERIAL` columns)
- **Con**: Fragile — tables created by plugins may have different sequence names
- **Mitigation**: The `RETURNING *` approach (appended to INSERT statements) is preferred for new development

---

## 4. Component Architecture

### 4.1 Entry Point: `wp-content/db.php`

The WordPress drop-in plugin loaded automatically by `wp-settings.php`. Responsibilities:

1. Define `PG4WP_ROOT`, `PG4WP_DEBUG`, `PG4WP_LOG`, `PG4WP_LOG_ERRORS`
2. Load `core.php` which bootstraps the driver
3. Never loaded more than once (guarded by `defined('PG4WP_ROOT')`)

### 4.2 Bootstrapper: `pg4wp/core.php`

Responsibilities:
1. Load WordPress prerequisites (`version.php`, `cache.php`, `l10n.php`)
2. Load the selected driver (`driver_pgsql.php` or `driver_mysql.php`)
3. Apply eval-based replacements to `class-wpdb.php` to create `wpdb2`
4. Instantiate `$wpdb = new wpdb2(...)`

Architectural note: This is the most fragile component. Any change to `class-wpdb.php` in a WordPress update may break the replacement patterns.

### 4.3 Driver: `pg4wp/driver_pgsql.php`

Implements all `wpsqli_*` functions. Key function groups:

| Group | Functions | PostgreSQL Mapping |
|---|---|---|
| Connection | `wpsqli_init`, `wpsqli_real_connect`, `wpsqli_close`, `wpsqli_ping`, `wpsqli_ssl_set` | `pg_connect` / `pg_close` / `pg_ping` |
| Query | `wpsqli_query`, `wpsqli_multi_query`, `wpsqli_prepare` | `pg_query` / multi-query split / `pg_prepare` |
| Result | `wpsqli_fetch_assoc`, `wpsqli_fetch_array`, `wpsqli_fetch_row`, `wpsqli_fetch_object`, `wpsqli_free_result`, `wpsqli_data_seek`, `wpsqli_num_fields`, `wpsqli_field_count` | `pg_fetch_*` / `pg_free_result` / `pg_num_fields` |
| Metadata | `wpsqli_insert_id`, `wpsqli_affected_rows`, `wpsqli_error`, `wpsqli_errno` | `CURRVAL()` / `pg_affected_rows` / `pg_last_error` |
| Transaction | `wpsqli_autocommit`, `wpsqli_begin_transaction`, `wpsqli_commit`, `wpsqli_rollback` | `BEGIN` / `COMMIT` / `ROLLBACK` |
| Utility | `wpsqli_real_escape_string`, `wpsqli_set_charset`, `wpsqli_get_server_info`, `wpsqli_get_client_info` | `pg_escape_string` / `pg_set_client_encoding` / hardcoded version |

Functions marked **NOT YET IMPLEMENTED** throw `\Exception("PG4WP: Not Yet Implemented")` and represent gaps for Phase 2/3 of the project.

### 4.4 Reference Driver: `pg4wp/driver_mysql.php`

A pass-through driver that maps each `wpsqli_*` call directly to the corresponding `mysqli_*` function. Used for:
- Testing the driver abstraction layer independently of PostgreSQL
- Serving as a reference for expected behavior
- Allowing side-by-side comparison of MySQL vs PG behavior

### 4.5 Rewrite Engine: `pg4wp/driver_pgsql_rewrite.php`

The central SQL rewrite orchestrator:

```
pg4wp_rewrite(sql):
    1. createSQLRewriter(sql) → detects type, instantiates rewriter
    2. rewriter.rewrite() → type-specific transformation
    3. Post-processing by type:
       - UPDATE: separate SET clause, protect from further transforms
       - INSERT: separate VALUES clause, protect from further transforms
    4. Global transforms (always applied):
       - correctMetaValue: fix unquoted numeric comparisons
       - handleInterval: INTERVAL → ::interval syntax
       - cleanAndCapitalize: remove backticks, handle "ID" casing
       - correctEmptyInStatements: IN () → IN (NULL)
       - correctQuoting: escape backslash quotes
    5. Reassemble separated clauses
    6. Cache INSERT ID metadata (table, field name)
```

### 4.6 Rewriters: `pg4wp/rewriters/*.php`

Each rewriter inherits from `AbstractSQLRewriter` and implements `rewrite(): string`. Key responsibilities per rewriter:

| Rewriter | Key Transforms |
|---|---|
| `SelectSQLRewriter` | `LIMIT m,n` → `LIMIT n OFFSET m`; `FOUND_ROWS()` → COUNT query; `GROUP BY` enforcement; `ILIKE` for `LIKE`; `CAST()` type mapping; `FIELD()` → `CASE`; `RAND()` → `RANDOM()`; `UNIX_TIMESTAMP()` → `DATE_PART('epoch', ...)`; `IF()` → `CASE WHEN`; `@@SESSION.sql_mode` → `''`; `GROUP_CONCAT()` → `string_agg()`; date function extraction |
| `InsertSQLRewriter` | `ON DUPLICATE KEY UPDATE` → `ON CONFLICT DO UPDATE`; `INSERT IGNORE` → `ON CONFLICT DO NOTHING`; `ENUM('0','1')` → `smallint`; `VALUES (0,` → `VALUES ('0',`; null-date → `now() AT TIME ZONE 'gmt'` |
| `UpdateSQLRewriter` | Strip `LIMIT`; null option fix; backtick removal; `ID` quoting |
| `DeleteSQLRewriter` | Strip `ORDER BY` / `LIMIT`; `REGEXP` → `~`; multi-table `DELETE a,b FROM t1 a, t2 b` → `DELETE FROM t1 a USING t2 b WHERE` |
| `CreateTableSQLRewriter` | `AUTO_INCREMENT` → `SERIAL` + sequence; `UNSIGNED` removal; `KEY` → `CREATE INDEX`; type width stripping; charset/collation removal; `ENUM` → `smallint`; `BINARY` → `bytea`; `DATETIME` → `TIMESTAMP` |
| `AlterTableSQLRewriter` | `CHANGE COLUMN` → `ALTER COLUMN TYPE` / `RENAME COLUMN`; `ADD INDEX` → `CREATE INDEX`; `DROP INDEX` → `DROP INDEX <table_index>`; `DROP PRIMARY KEY` → `DROP CONSTRAINT` |
| `ShowFullColumnsSQLRewriter` | Replaces with `pg_catalog.pg_attribute` query replicating `Field, Type, Null, Key, Default, Extra` |
| `ShowIndexSQLRewriter` | Replaces with `pg_class` / `pg_index` query replicating `Table, Non_unique, Key_name, Column_name` |
| `ShowVariablesSQLRewriter` | Hardcodes common MySQL variables (`sql_mode`, `max_allowed_packet`, etc.) |
| `ShowTablesSQLRewriter` | `SHOW TABLES` → `SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'` |
| `DescribeSQLRewriter` | `DESCRIBE table` → `SELECT column_name, data_type, ... FROM information_schema.columns` |
| `SetNamesSQLRewriter` | `SET NAMES utf8` → no-op (PostgreSQL handles encoding at connection level) |
| `OptimizeTableSQLRewriter` | `OPTIMIZE TABLE` → `VACUUM ANALYZE` |
| `DropTableSQLRewriter` | `DROP TABLE` → standard (mostly compatible, handles cascade syntax) |

---

## 5. Data Flow

### 5.1 Query Lifecycle

```
WordPress function (e.g., get_posts())
        │
        ▼
  $wpdb->get_results("SELECT * FROM wp_posts WHERE ID = 1")
        │
        ▼
  wpdb2::get_results()  [eval-patched wpdb]
        │
        ▼
  wpsqli_query($dbh, "SELECT * FROM wp_posts WHERE ID = 1")
        │
        ▼
  driver_pgsql.php::wpsqli_query()
        │
        ├─► pg4wp_rewrite("SELECT * FROM wp_posts WHERE ID = 1")
        │       │
        │       ├─► createSQLRewriter() → SelectSQLRewriter
        │       │       │
        │       │       └─► rewrite()
        │       │           "SELECT * FROM wp_posts WHERE \"ID\" = 1"
        │       │
        │       └─► Post-processing transforms
        │           (cleanAndCapitalize, correctQuoting, etc.)
        │
        └─► pg_query($dbh, "SELECT * FROM wp_posts WHERE \"ID\" = 1")
                │
                ▼
        pg_fetch_assoc($result)  [called by wpdb2::get_results()]
                │
                ▼
        PHP array → WordPress object
```

### 5.2 INSERT ID Flow

```
$wpdb->insert("wp_posts", [...])

  wpsqli_query("INSERT INTO wp_posts ... RETURNING *")

    InsertSQLRewriter appends "RETURNING *"
    pg4wp_rewrite() caches table name and first column

  pg_query() executes INSERT

  $wpdb->insert_id
    wpsqli_insert_id() → CURRVAL('wp_posts_seq')
```

Note: The `CURRVAL()` approach fails for tables without explicit sequences. The `RETURNING *` approach (currently used for the rewriter output) is the preferred path and should replace `CURRVAL()` entirely in a future revision.

### 5.3 Check Connection Flow

WordPress calls `$wpdb->check_connection()` which issues `DO 1` on every page load when `WP_DEBUG` is enabled.

```
check_connection()
  └─► wpsqli_query($dbh, "DO 1")
        └─► pg4wp_rewrite("DO 1")
              └─► createSQLRewriter() → "DO" type detected
                    └─► Returns SelectSQLRewriter("SELECT 1")
                          └─► rewrite() → "SELECT 1"
        └─► pg_query($dbh, "SELECT 1")
```

### 5.4 Pagination Flow

WordPress pagination uses MySQL's `SQL_CALC_FOUND_ROWS` / `FOUND_ROWS()` pair:

```
SELECT SQL_CALC_FOUND_ROWS * FROM wp_posts LIMIT 0, 10
  └─► SelectSQLRewriter strips SQL_CALC_FOUND_ROWS
  └─► pg4wp_rewrite() caches the rewritten query (without LIMIT)
  └─► Result: "SELECT * FROM wp_posts LIMIT 10 OFFSET 0"

SELECT FOUND_ROWS()
  └─► SelectSQLRewriter detects FOUND_ROWS()
  └─► Replaces with COUNT query from cache:
      "SELECT COUNT(*) FROM wp_posts"
```

---

## 6. Testing Strategy

### 6.1 Unit Tests (No Database)

The primary test methodology. Each test is a JSON fixture:

```json
{
  "mysql": "SELECT * FROM wp_posts WHERE post_status = 'publish' ORDER BY post_date DESC LIMIT 5",
  "postgresql": "SELECT * FROM wp_posts WHERE post_status = 'publish' ORDER BY EXTRACT(YEAR FROM post_date) DESC, EXTRACT(MONTH FROM post_date) DESC LIMIT 5"
}
```

Executed as:
```
php tests/tools/run-tests.php tests/
```

The runner auto-detects PHP version and loads the corresponding phpunit phar (10.5.x for PHP 8.1-8.2, 11.4.x for PHP 8.3-8.4). There are currently 316 stub fixtures producing 510+ assertions.

### 6.2 Integration Tests (Live PostgreSQL)

The CI pipeline runs a subset of tests against a live PostgreSQL instance:
- Verify `wpsqli_*` functions produce correct results
- Verify connection handling works (ssl, auth, host)
- Verify error conditions return expected values

### 6.3 Test Matrix

| Dimension | Values | Purpose |
|---|---|---|
| PHP version | 8.1, 8.2, 8.3, 8.4 | Language compatibility |
| PostgreSQL | 14, 15, 16, 17 | Server version compatibility |
| WordPress | 6.4, 6.5, 6.6, latest | wpdb API compatibility |
| WooCommerce | 8.9, 9.3, latest | Plugin compatibility |

---

## 7. Migration Strategy

### 7.1 Data Migration

Moving from MySQL/MariaDB to PostgreSQL requires:

1. **Schema Conversion**: Use `pgloader` (https://pgloader.io) for automated schema migration. This converts MySQL types to PostgreSQL equivalents:
   - `INT(11) AUTO_INCREMENT` → `SERIAL` / `INTEGER DEFAULT NEXTVAL(...)`
   - `VARCHAR(255)` → `VARCHAR(255)` (same)
   - `DATETIME` → `TIMESTAMP`
   - `TINYINT(1)` → `SMALLINT` / `BOOLEAN`
   - `ENUM('a','b')` → `TEXT` with `CHECK` constraint (pgloader default)
   - `BLOB` / `LONGBLOB` → `BYTEA`

2. **Sequence Creation**: pgloader creates sequences for `AUTO_INCREMENT` columns and sets the sequence value to `MAX(id) + 1`.

3. **Function Stripping**: pgloader removes MySQL-specific functions from views, triggers, and procedures (PostgreSQL does not support MySQL's stored procedure language).

### 7.2 Deployment Steps

1. Install PG4WP files in `wp-content/` (one-time)
2. Add `DB_DRIVER=pgsql` to `wp-config.php`
3. Migrate data via pgloader
4. Update database connection parameters in `wp-config.php`
5. Verify all WordPress screens function correctly
6. Enable PG4WP debug logging during burn-in period:
   ```php
   define('PG4WP_DEBUG', true);
   define('PG4WP_LOG_ERRORS', true);
   ```

### 7.3 Rollback

To revert to MySQL:
1. Change `DB_DRIVER` to `mysql` (or remove it — defaults to pgsql in current config)
2. Restore original `wp-config.php` database credentials
3. Verify WordPress functions against MySQL

The rollback risk is minimal because no WordPress core files have been modified and no data has been permanently transformed.

---

## 8. Maintenance & Upgrade Path

### 8.1 WordPress Version Upgrades

**Risk**: The eval-based wpdb patching in `core.php` depends on exact string patterns in `class-wpdb.php`. A WordPress update that changes whitespace, renames methods, or restructures the class can break the compatibility layer.

**Mitigations**:
1. Pin a test copy of `class-wpdb.php` in the repository
2. Run integration tests against the latest WordPress version before deploying
3. Review `core.php` replacement patterns against the new `class-wpdb.php` on each WordPress update
4. Long-term: Phase 4 of the roadmap replaces eval() with an inheritance-based approach

### 8.2 PHP Version Upgrades

The compatibility layer targets PHP 8.1 through 8.4. Key considerations:
- PHP 8.1+: `PgSql\Result` and `PgSql\Connection` are objects, not resources — the `is_resource()` replacement in `core.php` must precede `mysqli_` replacement to avoid double-prefixing
- PHP 8.2+: No breaking changes for this codebase
- PHP 8.3+: No breaking changes
- PHP 8.4+: No breaking changes anticipated

### 8.3 PostgreSQL Version Upgrades

PostgreSQL 14 through 17 are supported. The rewrite engine avoids version-specific syntax where possible. When version-specific features are used (e.g., `ON CONFLICT` requires PostgreSQL 9.5+), they are noted in the affected rewriter.

---

## 9. Current Gaps and Limitations

### 9.1 NOT YET IMPLEMENTED Functions

The following `wpsqli_*` functions throw exceptions and are not yet implemented:

| Function | Impact | Priority for Next Phase |
|---|---|---|
| `wpsqli_prepare`, `wpsqli_stmt_execute`, `wpsqli_stmt_bind_param`, `wpsqli_stmt_bind_result`, `wpsqli_stmt_fetch`, `wpsqli_stmt_init`, `wpsqli_stmt_close`, `wpsqli_stmt_error`, `wpsqli_stmt_errno` | No prepared statement support | High — affects plugins using `$wpdb->prepare()` with statement objects |
| `wpsqli_fetch_field` | No column metadata retrieval | Medium — affects plugin introspection |
| `wpsqli_multi_query`, `wpsqli_store_result`, `wpsqli_use_result` | No multi-query / unbuffered query support | Medium |
| `wpsqli_host_info`, `wpsqli_thread_id`, `wpsqli_thread_safe`, `wpsqli_stat`, `wpsqli_options`, `wpsqli_connect_errno`, `wpsqli_info` | Missing connection metadata and configuration | Low — hardcoded fakes or simple wrappers |
| `wpsqli_poll`, `wpsqli_reap_async_query` | No async query support | Low |

### 9.2 Known SQL Rewrite Gaps

These SQL patterns are not yet handled by the rewrite engine:

| Pattern | Issue | Impact |
|---|---|---|
| `INSERT ... SET col=val` | Not handled | Breaks plugins using this MySQL-specific syntax |
| `FROM DUAL` | Not stripped | Breaks queries referencing `DUAL` |
| `GROUP BY` + nested WHERE subqueries | WHERE clause lost | Breaks queries with subqueries in WHERE + GROUP BY |
| `YEAR()` / `MONTH()` / nested SELECT | Clause corruption | Breaks date-based queries with complex nesting |
| `SQL_CALC_FOUND_ROWS` | Cache race condition | Pagination counts wrong under concurrent requests |
| `BINARY`/`VARBINARY` | Now handled (Phase 1) | — |

### 9.3 Performance Considerations

| Concern | Impact | Mitigation |
|---|---|---|
| SQL rewrite on every query | CPU overhead of regex matching for each query | Cache rewritten SQL by original SQL hash |
| No prepared statement reuse | PostgreSQL plans each query separately | Implement pg_prepare/pg_execute for repeated queries |
| Sequence lookups for INSERT ID | Extra query per insert | Use RETURNING * instead of CURRVAL() |
| Connection per request | Overhead of pg_connect() per page load | Enable pg_pconnect() for persistent connections |

---

## 10. Roadmap

### Phase 1: Stabilize Core (Current — In Progress)

- Fix critical SQL rewrite bugs (10 issues identified, 7 resolved)
- Implement minimum viable driver functions
- Set up CI pipeline with full matrix testing
- SonarQube quality gate enforcement

### Phase 2: WooCommerce Compatibility (Next)

- Capture WooCommerce SQL patterns via instrumentation
- Implement rewrites for failing patterns
- Hardening for WooCommerce-specific behaviors (transaction nesting, lock handling, stock management)

### Phase 3: Performance & Production Readiness

- Prepared statement caching
- Connection pooling
- SQL rewrite caching
- Error handling hardening
- Comprehensive documentation

### Phase 4: Architecture Evolution

- Replace eval() with wpdb inheritance or class-swapping via db.php
- Evaluate SQL parser (phpmyadmin/sql-parser) for v4.0
- Remove all "Not Yet Implemented" stubs

---

## 11. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| WordPress core changes break eval patching | Medium | High | Pin wp-includes/ version; CI tests against latest WP; Phase 4 removes eval |
| WooCommerce generates unsupported SQL | High | Medium | Comprehensive SQL capture in Phase 2A; incremental rewriter fixes |
| INSERT ID retrieval fails for custom tables | Medium | High | Prefer RETURNING * over CURRVAL(); add gracefule fallback |
| Concurrent request pagination race in FOUND_ROWS | Medium | Low | Key cache by connection handle; or use window functions |
| Plugin uses prepared statement API | Medium | Medium | Implement wpsqli_stmt_* functions with pg_prepare/pg_execute wrapper |
| Performance regression vs MySQL | Medium | Medium | Query rewrite caching; connection pooling; benchmark in Phase 3 |

---

## 12. References

- **PG4WP Fork**: https://github.com/wilsonify/postgresql-for-wordpress
- **PG4WP Upstream**: https://github.com/PostgreSQL-For-Wordpress/postgresql-for-wordpress
- **pgloader**: https://pgloader.io — MySQL to PostgreSQL migration tool
- **WordPress wpdb**: https://developer.wordpress.org/reference/classes/wpdb/
- **phpmyadmin/sql-parser**: https://github.com/phpmyadmin/sql-parser — candidate for v4.0 rewrite engine
