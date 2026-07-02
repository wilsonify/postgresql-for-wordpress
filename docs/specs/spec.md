# Specification: PostgreSQL Backend for WordPress via PG4WP

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02  
**Classification**: Architecture Specification  
**SDD Compliance**: Level 1 — Specification-Driven Development

---

## 1. Executive Summary

WordPress and its plugin ecosystem are designed exclusively for MySQL/MariaDB. Every database interaction — from core CRUD operations to plugin-specific queries — issues MySQL-dialect SQL that is incompatible with PostgreSQL. Replacing the database engine without modifying the application layer requires a compatibility layer that intercepts every database call and translates MySQL dialect to PostgreSQL dialect transparently.

This specification defines the architecture, functional and non-functional requirements, compatibility analysis, migration procedures, security considerations, operational requirements, and risk assessment for providing PostgreSQL as a first-class, production-ready database backend for WordPress.

**Fundamental constraint**: No WordPress core file, no plugin, and no theme file is ever modified. The entire compatibility layer is self-contained in `wp-content/`.

---

## 2. Vision Statement

PG4WP exists to make PostgreSQL a seamless, production-ready database backend for WordPress. Administrators should be able to deploy, operate, update, and maintain WordPress entirely on PostgreSQL without needing to understand or manage MySQL/MariaDB compatibility. A site owner should be able to focus on publishing content and running their business rather than database internals. PG4WP should feel like a native PostgreSQL backend rather than a compatibility layer.

PostgreSQL is a first-class deployment option for WordPress on par with MySQL or MariaDB. Users should not need to think about database compatibility during normal operations.

---

## 3. Problem Statement

### 3.1 Technical Context

WordPress's database abstraction layer (`wpdb`) was designed exclusively for MySQL/MariaDB. Every layer of the database interaction relies on MySQL-specific behavior:

| Layer | MySQL Dependency | Consequence for PostgreSQL |
|-------|-----------------|---------------------------|
| SQL Syntax | `LIMIT m, n`, backtick quoting, `INSERT ... SET`, `REPLACE INTO` | Every SQL statement must be translated |
| Built-in Functions | `YEAR()`, `MONTH()`, `FIELD()`, `RAND()`, `GROUP_CONCAT()`, `IF()`, `DATE_ADD()`, `UNIX_TIMESTAMP()`, `FOUND_ROWS()` | Functions do not exist in PostgreSQL |
| Type System | `AUTO_INCREMENT`, `ENUM`, `BINARY`, `UNSIGNED`, `TINYINT(1)` boolean semantics | No direct equivalents |
| Connection Semantics | `DO 1` for connection verification, `SELECT FOUND_ROWS()` for pagination, `GET_LOCK()`/`RELEASE_LOCK()` for advisory locking | PostgreSQL uses different mechanisms |
| DDL Syntax | `CREATE TABLE ... ENGINE=InnoDB`, `CHARSET=utf8`, `CHANGE COLUMN` | Different DDL grammar |
| Information Schema | `SHOW TABLES`, `SHOW COLUMNS`, `SHOW INDEX`, `SHOW VARIABLES`, `DESCRIBE` | Must be mapped to PostgreSQL catalog |
| Error Handling | MySQL-specific error codes and `mysqli_errno()` values | Must map to PostgreSQL equivalents |
| Driver API | `mysqli_*` function calls used throughout `wpdb` | Must be intercepted and redirected |

### 3.2 Business Drivers

| Driver | Description |
|--------|-------------|
| Database Consolidation | Organizations running PostgreSQL for other applications seek to eliminate separate MySQL infrastructure |
| Enterprise Compliance | PostgreSQL's audit logging, row-level security, and encryption-at-rest meet regulatory standards |
| Performance at Scale | PostgreSQL's query planner, parallel execution, and advanced indexing (GIN, GiST, BRIN) benefit large deployments |
| Cost Optimization | Reducing operational overhead of managing multiple database systems |
| Open-Source Alignment | PostgreSQL's permissive license and community-driven model align with organizational policy |

### 3.3 Deployment Context

The compatibility layer is designed to operate with any PostgreSQL 14–17 deployment. Whether or not existing PostgreSQL infrastructure exists in the target organization, the compatibility layer behaves identically. Connection configuration (host, port, authentication) is provided via standard WordPress configuration constants and does not affect the functional behavior of the system.

---

## 4. Scope

### 4.1 In Scope

- PostgreSQL as the database backend for a standard WordPress installation
- All WordPress core CRUD operations (posts, pages, comments, users, terms, options, metadata)
- WordPress installation, upgrade, and administration workflows
- The WordPress REST API (all standard endpoints)
- WP-CLI commands that interact with the database
- WordPress multisite deployments
- WooCommerce e-commerce functionality
- Migration from MySQL/MariaDB to PostgreSQL
- Backup, restore, and disaster recovery procedures

### 4.2 Out of Scope

| Item | Rationale |
|------|-----------|
| Modifying WordPress core, plugin, or theme files | Fundamental architectural constraint |
| Replacing the eval-based wpdb patching mechanism | Accepted within scope of this feature |
| Replacing regex-based SQL rewriting with an AST parser | Accepted within scope of this feature |
| Supporting MySQL-specific stored procedures or triggers | WordPress does not use them |
| Performance optimization beyond MySQL baseline | Parity is the target; optimization is a separate phase |
| Supporting PHP versions below 8.1 | Minimum PHP version required by the compatibility layer |
| Supporting PostgreSQL versions below 14 | Minimum target version |
| Providing a GUI management interface for the compatibility layer | CLI and configuration-file only |
| Supporting async query execution | Not used by WordPress core |
| Replacing standard WordPress backup plugins | PostgreSQL-native backup is recommended |

---

## 5. Assumptions

| ID | Assumption | Rationale |
|----|-----------|-----------|
| A-01 | The WordPress site is running WordPress 6.4 or later | Core database schema changes before 6.4 are not tested |
| A-02 | The PHP runtime has the `pgsql` extension installed | Required for PostgreSQL connectivity |
| A-03 | The PostgreSQL server is accessible from the web server | Network connectivity between tiers |
| A-04 | The WordPress file system is writable for drop-in plugin placement | Required to install `wp-content/db.php` |
| A-05 | The target audience has PostgreSQL administration skills | Documentation assumes familiarity with `pg_dump`, `pg_restore`, `psql` |
| A-06 | No plugin or theme uses MySQL-specific stored procedures or triggers | WordPress and its ecosystem do not use these |
| A-07 | Migration will be performed during a scheduled maintenance window | Data migration requires downtime |
| A-08 | A MySQL/MariaDB backup exists and is verifiable before migration begins | Rollback safety requires a known-good backup |

---

## 6. Constraints

| ID | Constraint | Source | Impact |
|----|-----------|--------|--------|
| C-01 | Zero modifications to WordPress core files | Project principle | Compatibility layer must operate entirely in `wp-content/` |
| C-02 | Zero modifications to plugin or theme files | Project principle | All SQL translation must be transparent |
| C-03 | The compatibility layer must work within the standard WordPress drop-in mechanism | WordPress architecture | Limited to `wp-content/db.php` loading |
| C-04 | SQL translation must preserve semantic equivalence | Data integrity requirement | Type mappings must not lose or corrupt data |
| C-05 | The system must support PHP 8.1 through 8.4 | Industry standard | No PHP-version-specific workarounds |
| C-06 | The system must support PostgreSQL 14 through 17 | Industry standard | No version-specific PostgreSQL features |
| C-07 | Database credentials must never be exposed in logs or error messages | Security requirement | Credential handling must be isolated from debug output |

---

## 7. Goals

### 7.1 Primary Goals

| ID | Goal | Priority | Rationale |
|----|------|----------|-----------|
| G-01 | WordPress shall operate on PostgreSQL without any modifications to WordPress core files | P0 | Maintains upgrade compatibility |
| G-02 | All standard WordPress CRUD operations (posts, pages, comments, users, terms, options, metadata) shall produce correct results | P0 | Core functionality is non-negotiable |
| G-03 | WordPress installation and upgrade shall complete without database errors | P0 | Foundational operation |
| G-04 | Existing plugins and themes shall continue to function without modification | P0 | Ecosystem compatibility |
| G-05 | SQL translation shall be transparent to all application code | P0 | No code changes in the WordPress layer |
| G-06 | The WordPress REST API shall return correct data | P1 | Critical for headless and block editor |
| G-07 | WP-CLI commands shall execute without database errors | P1 | Required for site management |
| G-08 | WordPress multisite deployments shall function correctly | P2 | Enterprise requirement |
| G-09 | WooCommerce e-commerce functionality shall operate correctly | P2 | E-commerce use case |
| G-10 | Migration from MySQL/MariaDB to PostgreSQL shall be reversible with minimal downtime | P1 | Operational necessity |

### 7.2 Non-Goals (explicitly excluded from this specification)

| ID | Non-Goal | Rationale |
|----|----------|-----------|
| NG-01 | Replacing the eval-based wpdb patching mechanism | Accepted within scope of this feature. Alternative implementation approaches are outside the current scope. |
| NG-02 | Replacing regex-based SQL rewriting with a SQL parser | Accepted within scope of this feature. Alternative implementation approaches are outside the current scope. |
| NG-03 | Supporting MySQL-specific stored procedures or triggers | WordPress does not use them |
| NG-04 | Performance optimization beyond MySQL baseline | Parity is the target; optimization deferred |
| NG-05 | Supporting PHP versions below 8.1 | Below minimum test coverage |
| NG-06 | Supporting PostgreSQL versions below 14 | Below minimum test coverage |
| NG-07 | Modifying WordPress core, plugins, or themes | Fundamental architectural constraint |
| NG-08 | Providing a GUI management interface | CLI and configuration-file only |
| NG-09 | Supporting async or parallel query execution | Not used by WordPress core |
| NG-10 | Replacing or modifying standard WordPress backup plugins | PostgreSQL-native backup is the supported approach |

---

## 8. Success Criteria

### 8.1 Functional Success Criteria

| ID | Criterion | Measurement | Target |
|----|-----------|-------------|--------|
| SC-01 | WordPress installation completes without error | Install wizard reaches success screen; all standard tables created | 100% |
| SC-02 | WordPress dashboard loads without fatal errors | All admin page types render | 100% |
| SC-03 | All post CRUD operations succeed | Create, read, update, delete, and restore from trash for each post type | 100% |
| SC-04 | All comment CRUD operations succeed | Create, approve, mark as spam, edit, delete | 100% |
| SC-05 | All user CRUD operations succeed | Create, edit, delete, password reset | 100% |
| SC-06 | Media library uploads and displays | Upload JPEG, PNG, WebP; verify metadata stored; generate thumbnails | 100% |
| SC-07 | Theme management succeeds | Install, preview, activate, switch | 100% |
| SC-08 | Plugin management succeeds | Install, activate, deactivate, delete | 100% |
| SC-09 | WordPress upgrade process preserves data | Run core update; verify all content, users, and settings unchanged | 100% |
| SC-10 | User authentication works | Login, logout, "remember me", password reset, cookie authentication | 100% |
| SC-11 | REST API endpoints return correct HTTP status codes and data | GET/POST/DELETE on `/wp/v2/posts`, `/wp/v2/users`, `/wp/v2/media`, `/wp/v2/types` | 100% |
| SC-12 | WP-CLI commands execute without database errors | `wp post list`, `wp user list`, `wp db query`, `wp search-replace` | 100% |
| SC-13 | Site search returns results matching MySQL baseline | Search for known content across posts, pages, and custom post types | Matches MySQL |
| SC-14 | Pagination displays correct page counts | Browse post lists, comment lists, user lists; verify page N of M | Matches MySQL |
| SC-25 | WP-CLI wp db export produces PostgreSQL-compatible output | Run wp db export; verify output can be restored via psql | 100% |

### 8.2 Non-Functional Success Criteria

| ID | Criterion | Measurement | Target |
|----|-----------|-------------|--------|
| SC-15 | All SQL translation tests pass | Full test suite execution | 100% pass rate |
| SC-16 | No unhandled exceptions during normal operation | Error log monitoring | 0 exceptions |
| SC-17 | SQL translation error rate | Application log analysis | < 0.1% of queries |
| SC-18 | PHP version compatibility | CI pipeline across matrix | Green on PHP 8.1-8.4 |
| SC-19 | PostgreSQL version compatibility | CI pipeline across matrix | Green on PG 14-17 |
| SC-20 | Source code quality gate | Static analysis scan | No new issues above severity INFO |
| SC-21 | Migration downtime | Timed migration window | < 1 hour for 10 GB database |
| SC-22 | Page load time | Comparative benchmark | < 120% of MySQL baseline |
| SC-23 | Test suite execution time | Timed test run | < 5 seconds |
| SC-24 | Query translation overhead | Microbenchmark | < 5 ms per query on average |

---

## 9. Business Justification

### 9.1 Cost-Benefit Summary

| Factor | MySQL/MariaDB Baseline | PostgreSQL + PG4WP | Net Effect |
|--------|----------------------|-------------------|------------|
| Database licensing | Free (MariaDB) or paid (MySQL Enterprise) | Free (PostgreSQL) | Cost-neutral to reduced |
| Operational overhead | Separate database stack management | Unified with existing PostgreSQL infrastructure | Reduction |
| Migration tooling | N/A | pgloader (free, mature) | One-time investment |
| Team skills | WordPress-specific DBA skills | Leverage existing PostgreSQL expertise | Efficiency gain |
| Compliance readiness | Limited audit capabilities | Built-in audit logging, row-level security | Improvement |

### 9.2 Risk-Benefit Summary

| Benefit | Associated Risk | Mitigation |
|---------|----------------|------------|
| Database consolidation | Compatibility layer introduces failure modes | Comprehensive test suite |
| Advanced PostgreSQL features | SQL translation gaps for edge cases | Incremental stub-based testing |
| Enterprise compliance | eval-based mechanism flagged by scanners | Documented exception in security scanner configuration |
| Open-source alignment | Plugin/theme incompatibilities | Compatibility testing matrix |

---

## 10. Specification Structure

This specification is organized into the following documents:

| Document | Contents |
|----------|----------|
| `spec.md` (this file) | Executive summary, scope, assumptions, constraints, goals, success criteria |
| `requirements.md` | Functional and non-functional requirements with traceability |
| `architecture.md` | Current and target architecture, diagrams, deployment model |
| `compatibility.md` | Database compatibility analysis, supported versions, SQL differences |
| `security.md` | Security requirements across all layers |
| `testing.md` | Test categories, acceptance criteria, requirement-to-test mapping |
| `operations.md` | Operational requirements for deployment, monitoring, recovery |
| `risks.md` | Risk register with assessment and mitigation |
| `adr/` | Architecture Decision Records |
| `open-questions.md` | Resolved architectural questions and decisions log |

---

## 11. Requirements Traceability

```
G-01 (no core modifications)    → FR-01, FR-03, FR-04
G-02 (CRUD operations)          → FR-05, FR-06, FR-07, FR-08, FR-09, FR-10
G-03 (installation/upgrade)     → FR-01
G-04 (plugin/theme compat)      → FR-12, FR-13, FR-14
G-05 (transparent translation)  → FR-14
G-06 (REST API)                 → FR-17, FR-18, FR-19
G-07 (WP-CLI)                   → FR-20, FR-21, FR-33
G-08 (multisite)                → FR-28
G-09 (WooCommerce)              → FR-29, FR-30, FR-31, FR-32
G-10 (migration)                → FR-23, FR-24, FR-25, FR-26, FR-27
```

[Full traceability including non-functional requirements is maintained in `requirements.md`.]

---

### Checklist

- [x] Vision statement defines the project's long-term goal and design philosophy
- [x] Executive summary describes the problem and approach without implementation detail
- [x] Problem statement identifies technical gaps between MySQL and PostgreSQL
- [x] Scope clearly distinguishes in-scope from out-of-scope
- [x] Assumptions are explicit and verifiable
- [x] Constraints are documented with sources and impacts
- [x] Goals are prioritized with rationales
- [x] Non-goals have explicit rationales
- [x] Success criteria are measurable with specific targets
- [x] Business justification includes cost-benefit and risk-benefit
- [x] Requirements traceability is documented at the goal level
- [x] No implementation details (function names, class names, specific file paths) appear in specification content
