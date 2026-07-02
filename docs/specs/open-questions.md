# Open Questions: PostgreSQL Backend for WordPress

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02

---

## 1. Resolved Questions

The following questions have been resolved by the project vision and specification decisions:

| Original Question | Resolution | Rationale |
|------------------|------------|-----------|
| Does the installation process create the database? | **No** — Standard WordPress requires the database to pre-exist. The compatibility layer does not change this. | WordPress's `wp-config.php` setup process always assumes a pre-created database. The `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` constants must point to an existing PostgreSQL database. |
| Are there existing PostgreSQL deployments in the organization? | **Does not affect specification** — Whether or not existing PostgreSQL infrastructure exists, the compatibility layer operates identically. The configuration will vary (connection string, authentication), but this is a deployment detail, not a specification requirement. | Moved from open question to deployment context. |
| Should migration be performed with plugin active or inactive? | **Inactive** — Migration should be performed with the compatibility layer not yet activated (using standard MySQL-to-PostgreSQL migration tools). The compatibility layer is activated only after the data resides in PostgreSQL. | pgloader migrates from MySQL to PostgreSQL directly; the compatibility layer activates when WordPress connects to PostgreSQL. |
| Which plugin compatibility testing is required? | **Determined by compatibility matrix** — A fixed set of plugins (All in One SEO, WP Statistics, Complianz GDPR, Broken Link Checker, Yoast SEO, Solid Security, Relevanssi, WooCommerce) are identified for compatibility testing. Additional plugins are addressed via community issue reporting. | Documented in compatibility.md and testing.md. |
| What are the recovery time objective (RTO) and recovery point objective (RPO)? | **Deployment-specific** — Recovery objectives are defined by the deploying organization. The compatibility layer supports configurable backup and recovery procedures. | Documented in NFR-23. |
| What is the target timeline for architecture evolution? | **Outside scope** — Runtime patching and regex-based SQL translation are accepted within scope of this feature. Alternative implementation approaches are outside the current scope. | Documented in NG-01, NG-02. |
| What is the expected concurrency level? | **Safe under concurrent requests** — The system shall be safe under concurrent requests typical of supported WordPress deployments. | Documented in NFR-11. |
| Should `wp db export` produce PostgreSQL-compatible or MySQL-compatible SQL dumps? | **PostgreSQL-compatible** — When WordPress is running on PostgreSQL, `wp db export` produces PostgreSQL-compatible SQL restorable with standard PostgreSQL tooling. | Documented in FR-33. |
| What level of plugin ecosystem compatibility is in scope? | **Tiered support policy** — WordPress Core and supported Database APIs are Fully Supported. Standard themes and plugins using the DB API correctly are Expected to Work. Raw MySQL-specific SQL is Best Effort. MySQL server-specific behavior is Not Guaranteed. | Documented in Compatibility Policy section of compatibility.md and requirements.md. |
| Should PostgreSQL support be considered production-ready? | **Production supported** — PostgreSQL is a first-class, production-ready deployment option for WordPress on par with MySQL or MariaDB. | Vision Statement in spec.md; Compatibility Policy in requirements.md. |
| What compatibility guarantee is required? | **Documented compatibility with tiered guarantees** — WordPress Core is fully supported. Plugins using standard APIs are expected to work. Behavior outside WordPress's database abstraction is best-effort with documented limitations. | Compatibility Policy in compatibility.md and requirements.md. |
| What compatibility model should PG4WP provide? | **Native PostgreSQL behavior with documented differences** — PostgreSQL should feel like a native backend. When MySQL and PostgreSQL behavior diverge, the compatibility layer lets PostgreSQL behave naturally and documents the difference. | Vision Statement in spec.md. |
| What is the supported upgrade strategy? | **Routine WordPress upgrades work without database migration** — PG4WP maintains compatibility with supported WordPress releases. Upgrading WordPress is operationally equivalent to a standard WordPress installation. | Documented in NFR-19, NFR-24. |
| What stability guarantees are expected? | **Configuration and API stability within a major version** — Public configuration interfaces remain stable within a major version. Database migrations introduced by PG4WP upgrades are reversible. | Documented in NFR-24. |

---

## 2. Questions Resolved by This Specification

The following questions were implicitly answered during the specification process:

| Question | Answer | Location |
|----------|--------|----------|
| Is async query support needed? | No — not used by WordPress core | NG-09 |
| Is stored procedure support needed? | No — WordPress and its ecosystem do not use them | NG-03 |
| Is a GUI management interface needed? | No — CLI and configuration-file only | NG-08 |
| What PHP versions are supported? | 8.1, 8.2, 8.3, 8.4 | SC-18 |
| What PostgreSQL versions are supported? | 14, 15, 16, 17 | SC-19 |
| Should multisite be supported? | Yes — Goal G-08, FR-28 | Requirements |
| Should WooCommerce be supported? | Yes — Goal G-09, FR-29 through FR-32 | Requirements |
| Are performance optimizations part of this scope? | No — parity is the target; optimization deferred | NG-04 |
| Is eval replacement part of this scope? | No — accepted within scope of current feature | NG-01 |
| Is AST SQL parser evaluation part of this scope? | No — accepted within scope of current feature | NG-02 |

---

### Checklist

- [x] All previous NEEDS CLARIFICATION items reviewed
- [x] All questions resolved with rationale
- [x] Decisions are documented with traceable references to specification sections
- [x] Questions are architectural or product decisions, not implementation details
- [x] Specification does not invent requirements to fill gaps
