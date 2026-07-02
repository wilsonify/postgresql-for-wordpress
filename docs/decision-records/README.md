# Architecture Decision Records: PostgreSQL Backend for WordPress

**Last Updated**: 2026-07-02  
**Version**: 3.0

---

## ADR-001: Adopt PostgreSQL as WordPress Database Backend

### Context

WordPress requires MySQL/MariaDB. The organization wants to standardize on PostgreSQL as its primary database platform. Options include running separate MySQL instances for WordPress, creating a compatibility layer, or forking WordPress core.

### Decision

Adopt PostgreSQL as the database backend for WordPress using a compatibility layer (PG4WP) that translates MySQL SQL to PostgreSQL SQL at runtime.

### Alternatives Considered

| Alternative | Reason for Rejection |
|-------------|---------------------|
| Run separate MySQL instances | Defeats database consolidation goal; increases operational overhead |
| Fork WordPress core to support PostgreSQL natively | Creates massive maintenance burden for every WordPress release; incompatible with plugin ecosystem |
| Use database proxy with MySQL protocol translation | No mature solution exists; adds network hop and latency |
| Modify wpdb class directly via core plugin | WordPress does not allow wpdb override from plugins; must use drop-in mechanism |

### Consequences

**Positive**:
- Database consolidation achieved
- No WordPress core modifications required
- Compatibility layer is isolated and replaceable

**Negative**:
- Dependency on compatibility layer performance and correctness
- SQL translation overhead on every query
- Some plugin SQL patterns may not be translatable

---

## ADR-002: Use PG4WP as the Compatibility Layer

### Context

Multiple approaches exist for adding PostgreSQL support to WordPress. PG4WP is an existing open-source project. Alternatives include the WordPress SQLite integration project, custom development, and commercial solutions.

### Decision

Use PG4WP (PostgreSQL for WordPress) as the compatibility layer.

### Evaluation

| Criterion | PG4WP | WordPress SQLite | Custom Development |
|-----------|-------|-----------------|-------------------|
| Maturity | 3+ years development | Experimental | N/A |
| Test coverage | 510+ stub tests | Minimal | Would need to build |
| WooCommerce support | Planned | Not tested | Would need to build |
| Community | Active issues | WP core team | N/A |
| Licensing | GNU GPL v2+ | GPL v2 | Would be our own |

### Consequences

**Positive**:
- Mature codebase with extensive test coverage
- Modular architecture (15 statement-type translators)
- Active fork with recent improvements

**Negative**:
- ~60 open issues, 20+ missing driver functions
- Runtime patching is fragile
- Regex-based SQL translation has known limitations

---

## ADR-003: Accept Runtime wpdb Patching as Current Solution

### Context

The compatibility layer uses runtime string replacement on `class-wpdb.php` to redirect MySQLi function calls. This approach is functional but fragile.

### Decision

Accept runtime patching within the scope of this feature. Alternative implementation approaches are outside the current scope.

### Alternatives Considered

| Alternative | Assessment | Status |
|-------------|------------|--------|
| Runtime string replacement | Working but fragile | **Selected** |
| Fork class-wpdb.php | Maintain patched copy per WP release | Rejected (high maintenance) |
| Extend wpdb via inheritance | More robust but longer implementation | Rejected (higher initial investment) |
| Replace $wpdb via db.php class swap | Cleanest approach | Rejected (requires deeper wpdb understanding) |

### Consequences

**Positive**:
- Works today without additional development
- Zero core file modifications on disk

**Negative**:
- `eval()` flagged by security scanners
- String replacements sensitive to formatting changes in WordPress core
- Must maintain pinned test copy of `class-wpdb.php`

### Mitigations

- CI tests against multiple WordPress versions
- Patching pattern verification in test suite

---

## ADR-004: Accept Regex-Based SQL Translation as Current Solution

### Context

The compatibility layer uses regex-based string transformation for SQL translation. This has known limitations compared to AST-based parsing, particularly with deeply nested SQL.

### Decision

Accept regex-based SQL translation within the scope of this feature. Alternative implementation approaches are outside the current scope.

### Alternatives Considered

| Alternative | Assessment | Status |
|-------------|------------|--------|
| Regex-based string transformation | Working but limited | **Selected** |
| phpmyadmin/sql-parser (AST-based) | Mature, handles complex SQL | Deferred (high integration effort; not required for current scope) |
| Custom recursive descent parser | Would need full implementation | Not viable |
| pg_query PHP extension | Uses PostgreSQL's own parser | Not viable (extension dependency) |

### Consequences

**Positive**:
- No additional dependencies
- Fast execution (sub-millisecond per translation)
- Easy to add simple pattern translations

**Negative**:
- Cannot handle arbitrarily nested SQL
- Parentheses-matching in regex is fragile
- Known bugs with complex queries (nested subqueries + GROUP BY, multiple date functions)

---

## ADR-005: Use Stub-Based SQL Translation Testing

### Context

Testing SQL translations requires verifying that MySQL input produces correct PostgreSQL output. Running a live database for every test is slow and requires infrastructure.

### Decision

Use JSON fixture stubs (MySQL input, expected PostgreSQL output) as the primary test methodology. Tests run without a database.

### Consequences

**Positive**:
- 510+ tests execute in < 5 seconds
- Trivial to add new test cases
- No database setup required for CI
- Prevents regressions with fast feedback

**Negative**:
- Cannot verify that translated SQL executes correctly on PostgreSQL
- Semantic equivalence must be verified separately (integration tests)

### Mitigations

- Integration tests against live PostgreSQL for critical paths
- Contract tests for semantic equivalence on key patterns

---

## ADR-006: Use Native PHP pgsql Extension (Not PDO)

### Context

The compatibility layer requires a PHP extension for PostgreSQL connectivity. Options are the native `pgsql` extension or `pdo_pgsql`.

### Decision

Use the native `pgsql` extension.

### Alternatives Considered

| Alternative | Reason |
|-------------|--------|
| Native `pgsql` extension | Closest mapping to MySQLi API; connection and result types match instanceof checks in patched wpdb |
| PDO (`pdo_pgsql`) | Would require abstraction over PDO; connection types differ |
| Database abstraction library | Heavy dependency; violates simplicity principle |

### Consequences

**Positive**:
- Direct function mapping (mysqli_query → pg_query)
- Connection and result types (`\PgSql\Connection`, `\PgSql\Result`) match patched wpdb instanceof checks
- No dependency beyond PHP's built-in extension

**Negative**:
- Not all hosting environments have `pgsql` extension
- PDO is more widely available on managed WordPress hosts

---

## ADR-007: Accept Request-Scoped Global State

### Context

The compatibility layer uses PHP global variables to track state across a single request: current result set, INSERT ID metadata, pagination count query, and connection error state.

### Decision

Accept global variable usage for the current version, with documented state lifecycle.

### Alternatives Considered

| Alternative | Reason |
|-------------|--------|
| Global variables | Simplest implementation; works for PHP's single-request model | **Selected** |
| Class-based state management | Would require refactoring procedural architecture | Deferred |
| Thread-local storage | Not applicable to PHP's model | Not viable |

### Consequences

**Positive**:
- Minimal code changes
- Works correctly for WordPress's single-request-per-process model

**Negative**:
- Pagination count query caching uses global that could be overwritten by nested queries
- Not compatible with concurrent programming models (not applicable to standard PHP)

---

## ADR-008: Use pgloader for MySQL-to-PostgreSQL Migration

### Context

Migrating from MySQL to PostgreSQL requires a tool to transfer schema and data. Multiple tools exist.

### Decision

Use pgloader as the primary migration tool.

### Alternatives Considered

| Alternative | Reason |
|-------------|--------|
| pgloader | Mature, actively maintained, handles MySQL-specific types | **Selected** |
| Manual export/import | Error-prone, requires manual schema conversion | Backup option |
| AWS DMS | Cloud-specific | Option for cloud deployments |

### Consequences

**Positive**:
- Automated schema and type conversion
- Batching and error recovery for large datasets
- Customizable CAST rules for WordPress-specific types

**Negative**:
- Separate tool installation required (not PHP)
- May need CAST customizations for WordPress data patterns

---

## ADR-009: PostgreSQL-Native Backup as Primary Strategy

### Context

WordPress backup plugins produce MySQL-compatible SQL dumps. After migration to PostgreSQL, these dumps may not restore correctly.

### Decision

Recommend PostgreSQL-native backup (`pg_dump`/`pg_restore`) as the primary backup strategy. Third-party backup plugins that use standard WordPress database APIs continue to work for data export but are not recommended for full database backup.

### Consequences

**Positive**:
- Consistent, verifiable backups using standard PostgreSQL tools
- Point-in-time recovery available if WAL archiving configured

**Negative**:
- Operations team must learn PostgreSQL backup tooling
- WordPress backup plugins that produce SQL dumps cannot be used for PostgreSQL restore

---

## ADR-010: Phased Feature Rollout

### Context

The compatibility layer has known gaps across different areas (core CRUD, WooCommerce, performance, security hardening). Delivering all features simultaneously would delay the initial release.

### Decision

Deliver the compatibility layer in phases:

| Phase | Scope | Version |
|-------|-------|---------|
| 1 | Core stabilization — fix critical SQL translation bugs, implement missing driver functions | v3.5.0 |
| 2 | WooCommerce compatibility — capture and handle WooCommerce SQL patterns | v3.6.0 |
| 3 | Performance optimization — query caching, connection pooling, benchmarks | v3.7.0 |

### Consequences

**Positive**:
- Incremental delivery provides value sooner
- Each phase has clear scope and success criteria
- Customer feedback can inform later phases

**Negative**:
- Production deployments before Phase 3 may have performance gaps
- WooCommerce deployments must wait for Phase 2

---

## ADR-011: WooCommerce Compatibility Through SQL Pattern Capture

### Context

WooCommerce generates complex SQL that may not be fully handled by the initial compatibility layer. Pre-emptively implementing all possible WooCommerce SQL translations would be speculative.

### Decision

Address WooCommerce compatibility through systematic SQL pattern capture: set up a full WooCommerce test environment, capture every SQL query during key workflows, identify failing patterns, and implement translations incrementally.

### Consequences

**Positive**:
- Only patterns that actually occur are translated
- Every fix is validated by a captured test case
- No speculative development effort

**Negative**:
- WooCommerce compatibility is not available in the initial release
- Some edge-case patterns may only appear under specific WooCommerce configurations

---

### Checklist

- [x] ADR-001: PostgreSQL adoption
- [x] ADR-002: PG4WP selection
- [x] ADR-003: Runtime patching (accepted within scope)
- [x] ADR-004: Regex SQL translation (accepted within scope)
- [x] ADR-005: Stub-based testing
- [x] ADR-006: Native pgsql extension vs PDO
- [x] ADR-007: Global state management
- [x] ADR-008: Migration tool selection
- [x] ADR-009: PostgreSQL-native backup strategy
- [x] ADR-010: Phased feature rollout
- [x] ADR-011: WooCommerce SQL pattern capture
- [x] Each ADR documents context, decision, alternatives, and consequences
- [x] Rejected alternatives have documented rationale
