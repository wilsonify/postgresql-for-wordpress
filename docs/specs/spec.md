# Feature Specification: PostgreSQL Backend for WordPress/WooCommerce

**Feature**: 001 — PG4WP Core Stabilization  
**Branch**: `001-pg4wp-core`  
**Status**: Draft  
**Last Updated**: 2026-07-02  
**Project**: PG4WP — PostgreSQL for WordPress  
**Upstream**: https://github.com/PostgreSQL-For-Wordpress/postgresql-for-wordpress  
**Fork**: https://github.com/wilsonify/postgresql-for-wordpress  

---

## 1. Executive Summary

**Problem**: WordPress and its ecosystem are designed exclusively for MySQL/MariaDB. Replacing the database engine without changing the application layer requires a database compatibility layer — a shim that intercepts every database call and translates MySQL dialect to PostgreSQL dialect transparently.

**Solution**: Extend PG4WP, an existing WordPress drop-in plugin (`wp-content/db.php`) that:
1. Intercepts all `mysqli_*` function calls made by WordPress's `wpdb` class via eval-based patching
2. Rewrites every SQL query from MySQL syntax to PostgreSQL syntax
3. Maps MySQL-specific behaviors (auto-increment, insert ID, error codes) to PostgreSQL equivalents

**Constraint**: No WordPress core files are ever modified. No WooCommerce patches. Everything lives in `wp-content/db.php` and its supporting files under `wp-content/pg4wp/`.

---

## 2. User Stories

### Core Developer

| ID | Title | Story | Acceptance Criteria |
|----|-------|-------|-------------------|
| US-01 | Install PG4WP | As a developer, I want to drop PG4WP into wp-content/ and configure DB_DRIVER=pgsql so that my WordPress site connects to PostgreSQL without core modifications. | WordPress loads and displays the dashboard with no fatal errors; the database connection uses PostgreSQL. |
| US-02 | Run WordPress CRUD | As a developer, I want all WordPress core CRUD operations (posts, pages, comments, users, terms, options) to work so that existing WordPress functionality is preserved. | Posts created, read, updated, deleted through wp-admin. Same for comments, users, terms, options. |
| US-03 | Run test suite | As a developer, I want to run `php tests/tools/run-tests.php tests/` and see all 510+ stubs pass so that I can verify no regressions. | All stub tests pass; any new stubs also pass. |
| US-04 | Debug SQL rewrites | As a developer, I want to enable `PG4WP_DEBUG` to capture original SQL, rewritten SQL, and PostgreSQL errors so that I can diagnose rewrite failures. | Log file at `pg4wp/logs/pg4wp_errors.log` contains debug information. |

### Site Administrator

| ID | Title | Story | Acceptance Criteria |
|----|-------|-------|-------------------|
| US-05 | Migrate from MySQL | As a site admin, I want to migrate my existing WordPress site from MySQL to PostgreSQL using pgloader so that my site data is preserved. | Schema and data migration succeeds; WordPress functions correctly after migration. |
| US-06 | Rollback to MySQL | As a site admin, I want to revert to MySQL by changing `DB_DRIVER` so that I can recover from issues without data loss. | Changing `DB_DRIVER` to `mysql` and restoring MySQL credentials makes WordPress work again. |
| US-07 | Run WooCommerce | As a site admin, I want WooCommerce product management, orders, cart/checkout, and analytics to work with PostgreSQL so that my e-commerce site functions. | WooCommerce admin screens load; products can be created/edited; orders process through checkout. |

### Plugin Developer

| ID | Title | Story | Acceptance Criteria |
|----|-------|-------|-------------------|
| US-08 | Prepared statements | As a plugin developer, I want `$wpdb->prepare()` and related prepared statement functions to work so that my plugin that uses these APIs functions. | Plugin code using `$wpdb->prepare()` with statement objects does not crash with "Not Yet Implemented". |
| US-09 | Custom SQL queries | As a plugin developer, I want custom SQL queries with MySQL-specific syntax to be rewritten transparently so that my plugin works without modifications. | Custom SELECT, INSERT, UPDATE, DELETE queries with MySQL-specific syntax are correctly translated. |
| US-10 | Debugging compatibility | As a plugin developer, I want standard WordPress debugging tools to report PostgreSQL errors accurately so that I can diagnose plugin issues. | `wpdb->last_error` contains meaningful PostgreSQL error messages. |

---

## 3. Functional Requirements

### FR-01: SQL Rewrite Engine

| ID | Requirement | Priority | Notes |
|----|-------------|----------|-------|
| FR-01.1 | Rewrite `LIMIT m, n` → `LIMIT n OFFSET m` | P0 | SelectSQLRewriter |
| FR-01.2 | Rewrite `ON DUPLICATE KEY UPDATE` → `ON CONFLICT DO UPDATE` | P0 | InsertSQLRewriter |
| FR-01.3 | Rewrite `INSERT IGNORE` → `ON CONFLICT DO NOTHING` | P0 | InsertSQLRewriter |
| FR-01.4 | Rewrite `AUTO_INCREMENT` → `SERIAL` + sequence | P0 | CreateTableSQLRewriter |
| FR-01.5 | Rewrite backtick quoting → double-quote or remove | P0 | Global post-processing |
| FR-01.6 | Rewrite `YEAR()` / `MONTH()` → `EXTRACT(YEAR\|MONTH FROM ...)` | P1 | SelectSQLRewriter — currently corrupts complex queries |
| FR-01.7 | Rewrite `INSERT ... SET col=val` → standard INSERT | P1 | Currently not handled |
| FR-01.8 | Rewrite `FROM DUAL` → strip clause | P1 | Currently not handled |
| FR-01.9 | Rewrite `GROUP BY` with nested WHERE subqueries | P1 | WHERE clause lost in current implementation |
| FR-01.10 | Rewrite `SQL_CALC_FOUND_ROWS` / `FOUND_ROWS()` | P1 | Cache race condition under concurrent requests |
| FR-01.11 | Rewrite `GET_LOCK(name, timeout)` → `pg_try_advisory_lock(hashtext(name))` | P1 | Needed for WooCommerce duplicate order prevention |
| FR-01.12 | Rewrite `RELEASE_LOCK(name)` → `pg_advisory_unlock(hashtext(name))` | P1 | Mirrors GET_LOCK |
| FR-01.13 | Rewrite `ENUM('a','b')` → `smallint` / `text` | P0 | Handled for common cases |
| FR-01.14 | Rewrite `BINARY(n)` / `VARBINARY(n)` → `bytea` | P0 | Handled |
| FR-01.15 | Rewrite `BOOL DEFAULT 0` → `BOOL DEFAULT FALSE` | P0 | Handled |
| FR-01.16 | Rewrite `DO 1` → `SELECT 1` | P0 | Handled — check_connection flow |

### FR-02: Driver Functions

| ID | Requirement | Priority | Notes |
|----|-------------|----------|-------|
| FR-02.1 | `wpsqli_prepare`, `wpsqli_stmt_execute`, `wpsqli_stmt_bind_param`, `wpsqli_stmt_bind_result`, `wpsqli_stmt_fetch`, `wpsqli_stmt_init`, `wpsqli_stmt_close`, `wpsqli_stmt_error`, `wpsqli_stmt_errno` | P1 | Prepared statement support via `pg_prepare`/`pg_execute` |
| FR-02.2 | `wpsqli_fetch_field` | P2 | Column metadata via `pg_field_*` |
| FR-02.3 | `wpsqli_multi_query`, `wpsqli_more_results`, `wpsqli_next_result` | P2 | Split on `;`, execute sequentially |
| FR-02.4 | `wpsqli_store_result`, `wpsqli_use_result` | P2 | No-ops returning true |
| FR-02.5 | `wpsqli_host_info`, `wpsqli_thread_id`, `wpsqli_thread_safe`, `wpsqli_stat`, `wpsqli_options`, `wpsqli_connect_errno`, `wpsqli_info`, `wpsqli_client_info` | P2 | Thin wrappers or hardcoded fakes |

### FR-03: Testing & CI

| ID | Requirement | Priority | Notes |
|----|-------------|----------|-------|
| FR-03.1 | All JSON fixture stubs must be verifiable without a database | P0 | Pure PHPUnit |
| FR-03.2 | CI must run lint + sql-rewrite + pgsql-integration | P0 | GitHub Actions matrix |
| FR-03.3 | CI must test PHP 8.1-8.4 | P0 | Full matrix |
| FR-03.4 | CI must test PG 14-17 | P0 | Integration |
| FR-03.5 | SonarQube quality gate must pass | P0 | No new hotspots |
| FR-03.6 | WordPress 6.4+ install job must pass in CI | P1 | Currently blocked |
| FR-03.7 | WooCommerce 9.x install job must pass in CI | P2 | Future |

---

## 4. Non-Functional Requirements

| ID | Requirement | Target | Verification |
|----|-------------|--------|-------------|
| NFR-01 | Test execution time | < 5s for full stub suite | `php tests/tools/run-tests.php tests/` |
| NFR-02 | PHP compatibility | 8.1, 8.2, 8.3, 8.4 | CI matrix |
| NFR-03 | PostgreSQL compatibility | 14, 15, 16, 17 | CI integration tests |
| NFR-04 | No WordPress core modifications | Zero modified core files | Git diff check |
| NFR-05 | SonarQube cognitive complexity | < 15 per method | SonarQube scan |
| NFR-06 | Max function length | < 40 lines per `wpsqli_*` | SonarQube scan |
| NFR-07 | Max nesting depth | < 4 levels | SonarQube scan |
| NFR-08 | Regression test coverage | 100% of known edge cases | Stub count tracking |

---

## 5. Acceptance Scenarios

### AS-01: WordPress Installation
```
Given a fresh WordPress installation configured to use PostgreSQL
When the WordPress installation wizard is run
Then all database tables are created successfully
And the installation completes without SQL errors
And the admin dashboard loads without fatal errors
```

### AS-02: Post CRUD
```
Given a running WordPress site on PostgreSQL
When I create a new post via wp-admin
Then the post is saved and visible on the front end
When I edit the post title and content
Then the changes are persisted
When I delete the post
Then it is removed from the database
And the post list no longer shows it
```

### AS-03: WooCommerce Order
```
Given a running WordPress + WooCommerce site on PostgreSQL
When a customer completes a purchase through the checkout
Then the order is created in the database
And the order total matches the cart total
And stock levels are decremented correctly
```

### AS-04: Regression Test Suite
```
Given the PG4WP source code
When I run `php tests/tools/run-tests.php tests/`
Then all 510+ stub tests pass
And no existing stubs are broken by new changes
```

### AS-05: Prepared Statement API
```
Given a PHP file using $wpdb->prepare() with parameterized queries
When the query is executed
Then it does not throw "Not Yet Implemented"
And the result is equivalent to a direct SQL query
```

### AS-06: INSERT with MySQL-specific syntax
```
Given a plugin that uses INSERT ... SET col=val syntax
When the SQL reaches pg4wp_rewrite()
Then it is correctly transformed to standard INSERT with column/value lists
And data is inserted correctly
```

---

## 6. Out of Scope

- [NEEDS CLARIFICATION: Is async query support (wpsqli_poll, wpsqli_reap_async_query) needed?]
- Full WooCommerce compatibility testing — scoped to SQL rewrite patterns only
- Removal of eval-based wpdb patching — scoped to Phase 4
- SQL parser replacement (phpmyadmin/sql-parser) — scoped to Phase 4 evaluation
- Performance benchmarking vs MySQL — scoped to Phase 3
- Documentation generation — scoped to Phase 3

---

## 7. Glossary

| Term | Definition |
|------|------------|
| PG4WP | PostgreSQL for WordPress — the drop-in compatibility plugin |
| `wpsqli_*` | Namespace of compatibility functions mapping MySQLi API to PostgreSQL |
| Rewriter | A class in `pg4wp/rewriters/` that transforms one SQL statement type |
| Stub | A JSON fixture `{"mysql": "...", "postgresql": "..."}` used for regression testing |
| `eval()` patching | Runtime string replacement of `class-wpdb.php` to redirect `mysqli_*` calls |

---

## 8. Open Questions

[NEEDS CLARIFICATION: Should the prepared statement implementation use pg_prepare/pg_execute or a simpler wrapper?]
[NEEDS CLARIFICATION: Is multi-query support required for WooCommerce compatibility, or only for plugin compatibility?]
[NEEDS CLARIFICATION: What is the target timeline for Phase 2 (WooCommerce) — immediately after Phase 1 or with a gap?]

---

### Checklist

#### Requirement Completeness

- [x] All user stories have clear acceptance criteria
- [ ] No [NEEDS CLARIFICATION] markers remain (intentional — needs team review)
- [ ] Requirements are testable and unambiguous
- [ ] Success criteria are measurable
- [ ] Non-functional requirements have specific targets
- [ ] Out-of-scope items are explicitly documented
- [ ] Edge cases are addressed (null, empty, error conditions)
- [ ] Glossary covers domain-specific terms
- [ ] Dependencies on external systems are documented

#### Constraint Awareness

- [ ] No speculative or "might need" features included
- [ ] Each requirement traces to a concrete user story
- [ ] Each user story has clear value to a specific user type
- [ ] Acceptance scenarios cover happy path and error cases
- [ ] Error handling is specified at requirement level
