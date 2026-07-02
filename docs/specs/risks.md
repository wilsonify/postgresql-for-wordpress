# Risk Register: PostgreSQL Backend for WordPress

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02

---

## Risk Scoring

| Score | Probability | Impact |
|-------|-------------|--------|
| 5 (Critical) | > 90% chance | Data loss, complete outage, security breach |
| 4 (High) | 50-90% chance | Significant functionality loss |
| 3 (Medium) | 20-50% chance | Partial functionality loss |
| 2 (Low) | 5-20% chance | Minor inconvenience |
| 1 (Very Low) | < 5% chance | Cosmetic issue |

Risk Score = Probability × Impact

---

## 1. Technical Risks

### R-01: SQL Translation Produces Incorrect Results

| Field | Value |
|-------|-------|
| **Description** | The SQL translation engine may produce syntactically valid but semantically incorrect PostgreSQL queries, leading to wrong data being returned or stored |
| **Probability** | 3 (Medium) |
| **Impact** | 5 (Critical) |
| **Risk Score** | 15 — **Critical** |
| **Root Causes** | Regex-based translation has edge cases with nested parentheses, subqueries, and function interaction |
| **Indicators** | Users report wrong post counts, missing search results, incorrect WooCommerce totals |
| **Mitigation** | Comprehensive stub test coverage (TS-02); contract tests verifying semantic equivalence; integration tests against live PostgreSQL (TS-03); debug mode for production diagnostics |
| **Contingency** | Rollback to MySQL via configuration change; disable affected plugins until fix |

### R-02: Runtime wpdb Patching Breaks After WordPress Update

| Field | Value |
|-------|-------|
| **Description** | A WordPress core update changes `class-wpdb.php` in a way that prevents the runtime patching from working, causing PHP fatal errors |
| **Probability** | 3 (Medium) |
| **Impact** | 5 (Critical) |
| **Risk Score** | 15 — **Critical** |
| **Root Causes** | Runtime patching depends on exact string patterns in `class-wpdb.php` that may change between WordPress releases |
| **Indicators** | PHP fatal errors on every page immediately after WordPress core update |
| **Mitigation** | CI tests against WordPress "latest"; patching verification step in CI; patching mechanism is accepted within scope of the current feature |
| **Contingency** | Immediate rollback to MySQL via configuration change; remove compatibility layer until compatible version is deployed |

### R-03: Plugin Incompatibility Due to Unimplemented Driver Functions

| Field | Value |
|-------|-------|
| **Description** | Plugins using certain PHP MySQLi extension functions that are not yet implemented in the compatibility layer cause PHP fatal errors |
| **Probability** | 4 (High) |
| **Impact** | 4 (High) |
| **Risk Score** | 16 — **Critical** |
| **Root Causes** | Multiple MySQLi extension functions not yet implemented in the compatibility layer |
| **Indicators** | PHP fatal errors with "Not Yet Implemented" messages; plugin functionality unavailable |
| **Mitigation** | Document known incompatible plugins; prioritize implementation of critical driver functions; graceful degradation for remaining stubs |
| **Contingency** | Deactivate incompatible plugin; use alternative plugin; implement missing function on priority |

### R-04: Performance Degradation vs MySQL Baseline

| Field | Value |
|-------|-------|
| **Description** | WordPress on PostgreSQL may be slower than on MySQL due to SQL translation overhead, suboptimal query plans, or PostgreSQL configuration differences |
| **Probability** | 3 (Medium) |
| **Impact** | 3 (Medium) |
| **Risk Score** | 9 — **Medium** |
| **Root Causes** | SQL translation adds per-query overhead; PostgreSQL configuration may not be optimized for WordPress workloads |
| **Indicators** | Page load times exceed baseline; higher database server CPU usage |
| **Mitigation** | Performance benchmarks in CI (TS-07); PostgreSQL configuration recommendations; query translation caching; connection pooling guidance |
| **Contingency** | Performance optimization phase; connection pooling; query result caching |

### R-05: Concurrent Request State Corruption

| Field | Value |
|-------|-------|
| **Description** | Request-scoped globals used for pagination and insert ID tracking may produce wrong results under concurrent requests |
| **Probability** | 1 (Very Low) |
| **Impact** | 3 (Medium) |
| **Risk Score** | 3 — **Low** |
| **Root Causes** | PHP's shared-nothing architecture means each request has its own global scope; risk only if persistence mechanisms (APCu, shared memory) are used |
| **Indicators** | Inconsistent pagination counts on high-traffic pages |
| **Mitigation** | Connection-keyed state management for pagination cache; documented architecture assumption of PHP's per-request isolation |
| **Contingency** | Implement connection-isolated state management if issue manifests |

---

## 2. Migration Risks

### R-06: Migration Failure Due to Data Incompatibility

| Field | Value |
|-------|-------|
| **Description** | Migration from MySQL to PostgreSQL via pgloader fails due to unsupported data patterns, character encoding issues, or large data volumes |
| **Probability** | 3 (Medium) |
| **Impact** | 4 (High) |
| **Risk Score** | 12 — **High** |
| **Root Causes** | WordPress-specific data patterns (serialized objects, zero dates, plugin-specific types) may not migrate cleanly |
| **Indicators** | pgloader errors during migration; data verification shows mismatched row counts |
| **Mitigation** | Pre-migration test in staging environment; comprehensive data verification after migration; documented CAST rules for WordPress-specific types |
| **Contingency** | Restore MySQL from pre-migration backup; fix migration issues and retry; phased migration approach |

### R-07: Extended Downtime During Migration

| Field | Value |
|-------|-------|
| **Description** | Database migration takes longer than planned, exceeding the maintenance window |
| **Probability** | 2 (Low) |
| **Impact** | 3 (Medium) |
| **Risk Score** | 6 — **Medium** |
| **Root Causes** | Large database size, network throughput limitations, pgloader performance |
| **Indicators** | Migration progress slower than estimated |
| **Mitigation** | Test migration in staging with production-sized data; size estimate before scheduling; documented target of < 1 hour for 10 GB |
| **Contingency** | Extend maintenance window; abort and retry with optimized configuration; use streaming replication for near-zero-downtime approach |

---

## 3. Compatibility Risks

### R-08: WooCommerce SQL Patterns Not Handled

| Field | Value |
|-------|-------|
| **Description** | WooCommerce generates complex SQL queries (date functions, subqueries, window functions) that the SQL translation engine cannot correctly translate |
| **Probability** | 3 (Medium) |
| **Impact** | 4 (High) |
| **Risk Score** | 12 — **High** |
| **Root Causes** | WooCommerce analytics and reports use MySQL-specific features not yet mapped in the translation engine |
| **Indicators** | WooCommerce admin screens show errors; analytics reports fail to load; orders not processing |
| **Mitigation** | WooCommerce-specific test environment; incremental SQL pattern capture from real WooCommerce queries; comprehensive WooCommerce test suite (TS-08) |
| **Contingency** | Revert to MySQL for WooCommerce operations; implement missing translations one at a time |

### R-09: Plugin Ecosystem Incompatibility

| Field | Value |
|-------|-------|
| **Description** | Popular WordPress plugins use MySQL-specific SQL that the translation engine does not handle, making them incompatible |
| **Probability** | 4 (High) |
| **Impact** | 3 (Medium) |
| **Risk Score** | 12 — **High** |
| **Root Causes** | Thousands of WordPress plugins, many with custom SQL; impossible to test all combinations |
| **Indicators** | Plugin-specific database errors after activation or during operation |
| **Mitigation** | Document known incompatible plugins; maintain compatibility matrix; provide debug mode for diagnosing issues; community bug reporting process |
| **Contingency** | Deactivate incompatible plugin; contribute fix to compatibility layer; use alternative plugin |

---

## 4. Operational Risks

### R-10: Operational Team Lacks PostgreSQL Expertise

| Field | Value |
|-------|-------|
| **Description** | Operations teams familiar with MySQL may not have the PostgreSQL skills required for backup, recovery, performance tuning, and troubleshooting |
| **Probability** | 3 (Medium) |
| **Impact** | 3 (Medium) |
| **Risk Score** | 9 — **Medium** |
| **Root Causes** | Organizational investment in MySQL skills; PostgreSQL administration requires different tooling and knowledge |
| **Indicators** | Backup failures; slow recovery times; suboptimal database configuration |
| **Mitigation** | Comprehensive operations documentation; PostgreSQL configuration recommendations; backup and restore guides; monitoring setup guides |
| **Contingency** | Training program for operations team; PostgreSQL DBA consulting engagement |

### R-11: Backup and Restore Procedures Not Validated

| Field | Value |
|-------|-------|
| **Description** | PostgreSQL-native backup and restore procedures are documented but not regularly tested, leading to untested recovery paths |
| **Probability** | 3 (Medium) |
| **Impact** | 4 (High) |
| **Risk Score** | 12 — **High** |
| **Root Causes** | Backup testing is often deferred in operational schedules |
| **Indicators** | Backup verification not performed; restore documented but not exercised |
| **Mitigation** | Automated backup verification requirement (OPS-11); documented restore testing schedule |
| **Contingency** | Maintain MySQL fallback as a parallel recovery path; test restore from known-good backup before needing it |

### R-12: PostgreSQL Connection Pool Exhaustion

| Field | Value |
|-------|-------|
| **Description** | High-traffic WordPress site exhausts PostgreSQL connection pool, causing "too many connections" errors |
| **Probability** | 2 (Low) |
| **Impact** | 4 (High) |
| **Risk Score** | 8 — **Medium** |
| **Root Causes** | Each PHP process opens a database connection; default PostgreSQL max_connections may be insufficient |
| **Indicators** | PostgreSQL error log shows connection refusal; intermittent "database connection" errors on the site |
| **Mitigation** | Connection pooling recommendations in operations documentation; PostgreSQL configuration guidance for max_connections |
| **Contingency** | Deploy connection pooler (pgbouncer); increase max_connections; reduce PHP child processes |

---

## 5. Maintenance Risks

### R-13: Upstream Project Maintenance Stalls

| Field | Value |
|-------|-------|
| **Description** | The upstream PG4WP project may become inactive, leaving the codebase without security or compatibility updates for future WordPress/PHP/PostgreSQL versions |
| **Probability** | 2 (Low) |
| **Impact** | 4 (High) |
| **Risk Score** | 8 — **Medium** |
| **Root Causes** | External dependency on community-maintained open-source project |
| **Indicators** | No commits or releases for extended period; unanswered issues; growing incompatibility with new WordPress/PHP/PG versions |
| **Mitigation** | Internal fork maintained; modular architecture allows per-component maintenance; current implementation is accepted within scope |
| **Contingency** | Take over maintenance of internal fork; recruit additional maintainers; transition to alternative solution |

---

## Risk Heat Map

```
Probability
   4 (High)    │  R-03 (16)   │             │  R-09 (12)
   3 (Medium)  │  R-01 (15)   │  R-06 (12)  │  R-08 (12)
               │  R-02 (15)   │  R-11 (12)  │
   2 (Low)     │              │  R-07 (6)   │  R-12 (8)
               │              │  R-13 (8)   │
   1 (V.Low)   │              │  R-05 (3)   │
               │  Critical    │  High       │  Medium
               │  (Impact 5)  │  (Impact 4) │  (Impact 3)
               ────────────────────────────────────────────
                          Impact →
```

---

## Risk Response Plan

| Priority | Risk | Score | Response | Owner | Timeline |
|----------|------|-------|----------|-------|----------|
| P0 | R-03 — Plugin incompatibility (unimplemented functions) | 16 | Mitigate: Implement critical driver functions, document workarounds | Development | Current sprint |
| P0 | R-01 — Incorrect SQL translation | 15 | Mitigate: Comprehensive test coverage, fix known gaps | Development | Current sprint |
| P0 | R-02 — Runtime patching breaks | 15 | Mitigate: CI tests against latest WordPress, patching verification | Development | Each WordPress release |
| P1 | R-06 — Migration failure | 12 | Mitigate: Test migration in staging, verify data integrity | Operations | Before production migration |
| P1 | R-08 — WooCommerce incompatibility | 12 | Mitigate: WooCommerce test environment, incremental pattern capture | Development | Phase 2 |
| P1 | R-09 — Plugin incompatibility | 12 | Accept with monitoring: Compatibility matrix, community reporting | Development | Ongoing |
| P1 | R-11 — Backup validation | 12 | Mitigate: Automated verification, documented testing schedule | Operations | Before production |
| P2 | R-04 — Performance degradation | 9 | Mitigate: Benchmarks, configuration docs, connection pooling | Development | Phase 3 |
| P2 | R-10 — Team lacks PG expertise | 9 | Mitigate: Documentation, training, runbook | Operations | Pre-migration |
| P2 | R-12 — Connection pool exhaustion | 8 | Mitigate: Configuration guidance, pooling recommendations | Operations | Phase 3 |
| P2 | R-13 — Upstream maintenance stalls | 8 | Accept: Internal fork, modular architecture | Development | Ongoing |
| P3 | R-07 — Extended migration downtime | 6 | Accept: Staging testing, size estimation | Operations | Pre-migration |
| P3 | R-05 — Concurrent request state corruption | 3 | Accept: Low probability, low impact | Development | If manifested |

---

### Checklist

- [x] Technical risks identified and assessed
- [x] Migration risks identified and assessed
- [x] Compatibility risks identified and assessed
- [x] Operational risks identified and assessed
- [x] Maintenance risks identified and assessed
- [x] Each risk has probability, impact, and risk score
- [x] Each risk has root causes, indicators, mitigation, and contingency
- [x] Risk heat map visualizes priority
- [x] Risk response plan assigns priority, owner, and timeline
- [x] Risks are specific to this specification, not generic
