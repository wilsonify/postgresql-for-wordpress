# Requirements: PostgreSQL Backend for WordPress

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02  
**SDD Compliance**: Level 1 — all requirements describe observable system behavior

---

## 1. User Stories

| ID | Role | Title | Description |
|----|------|-------|-------------|
| US-01 | Site Administrator | Install WordPress on PostgreSQL | As a site administrator, I want to install WordPress on a PostgreSQL database so that I can start using WordPress without MySQL. |
| US-02 | Site Administrator | Migrate from MySQL to PostgreSQL | As a site administrator, I want to migrate my existing WordPress site from MySQL to PostgreSQL so that I can consolidate my database infrastructure. |
| US-03 | Site Administrator | Roll back to MySQL | As a site administrator, I want to revert my WordPress site to MySQL so that I can recover from compatibility issues without data loss. |
| US-04 | Site Administrator | Manage content | As a site administrator, I want to create, edit, and delete posts, pages, comments, users, and media so that I can manage my site content. |
| US-05 | Site Administrator | Manage plugins and themes | As a site administrator, I want to install, activate, and update plugins and themes so that I can extend my site's functionality. |
| US-06 | Site Administrator | Search and paginate | As a site administrator, I want site search and pagination to return correct results so that visitors can find content. |
| US-07 | Developer | Interact via REST API | As a developer, I want to use the WordPress REST API so that I can build headless or decoupled applications. |
| US-08 | Developer | Manage site via WP-CLI | As a developer, I want to use WP-CLI commands so that I can automate site management tasks. |
| US-09 | Developer | Write plugins | As a developer, I want my plugins to use standard WordPress database APIs so that they work without modification. |
| US-10 | Operations Engineer | Back up and restore | As an operations engineer, I want to back up and restore the WordPress database so that I can recover from failures. |
| US-11 | Operations Engineer | Monitor database health | As an operations engineer, I want to monitor database performance and errors so that I can detect issues before they affect users. |
| US-12 | Site Administrator | Run an online store | As a site administrator, I want WooCommerce to function correctly so that I can sell products online. |

---

## 2. Functional Requirements

### 2.1 WordPress Installation and Configuration

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-01 | WordPress shall install successfully using PostgreSQL as the database backend | Users must be able to provision a new WordPress site on PostgreSQL | US-01 | 1) The installation wizard displays and progresses through all steps 2) When installation completes, all standard WordPress tables exist in the target PostgreSQL database 3) The initial administrator user account is created 4) The installation success screen is displayed 5) The site front-end and admin dashboard render without errors on first load |
| FR-02 | WordPress shall use the configured table prefix for all created database objects during installation | Plugins and multisite rely on table prefix conventions | US-01 | 1) All tables created during installation use the `$table_prefix` value from `wp-config.php` 2) Sequences associated with serial columns use the prefixed table name |
| FR-03 | A single configuration constant shall enable PostgreSQL mode | Minimal configuration overhead encourages adoption | US-01 | 1) Setting the database driver constant to \"pgsql\" activates PostgreSQL connectivity 2) Omitting the constant or setting it to \"mysql\" preserves standard MySQL/MariaDB behavior 3) All other standard WordPress database configuration constants continue to function as documented |
| FR-04 | The compatibility layer shall load zero additional code when PostgreSQL mode is not selected | No performance impact for sites not using PostgreSQL | US-01 | 1) When the database driver is set to \"mysql\", no compatibility layer files are loaded 2) WordPress operates identically to a standard installation |

### 2.2 Content Management

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-05 | All standard post CRUD operations shall produce correct results | Core content management must function correctly | US-04 | 1) Creating a post of any standard type (post, page, attachment, revision, nav_menu_item, custom post type) persists the post and all its metadata 2) Reading a post returns all fields and metadata identically to MySQL 3) Updating a post changes only the specified fields 4) Moving a post to trash, restoring from trash, and permanently deleting all work correctly 5) Post revisions are created and accessible |
| FR-06 | All standard comment CRUD operations shall produce correct results | Community engagement features must function | US-04 | 1) Creating a comment persists the comment and links it to the correct post 2) Comment status transitions (hold, approve, spam, trash) work correctly 3) Comment counts on posts are accurate 4) Nested/threaded comments maintain correct parent-child relationships |
| FR-07 | All standard user CRUD operations shall produce correct results | User management must function | US-04 | 1) User registration creates a user record with correct role and metadata 2) User profile updates persist 3) User deletion removes the user and optionally reassigns content 4) Password reset flow generates a valid reset link and allows setting a new password |
| FR-08 | All standard term and taxonomy operations shall produce correct results | Content categorization must function | US-04 | 1) Creating, editing, and deleting categories, tags, and custom taxonomies work 2) Assigning terms to posts and removing terms update the term count correctly 3) Hierarchical taxonomies maintain parent-child relationships |
| FR-09 | All standard metadata operations shall produce correct results | Plugin and theme functionality depends on metadata | US-04 | 1) Post metadata, user metadata, term metadata, and comment metadata can be added, read, updated, and deleted 2) Metadata values of all types (strings, integers, arrays, serialized objects) are stored and retrieved correctly 3) Bulk metadata queries (meta_query) return correct results |

### 2.3 Media Library

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-10 | Media file upload, storage, and retrieval shall function correctly | Media is core WordPress functionality | US-04 | 1) Uploading an image creates an attachment post with correct metadata (file URL, MIME type, dimensions, file size) 2) Image thumbnails and intermediate sizes are generated 3) Media library list and grid views display correctly 4) Deleting media removes the attachment post and optionally the file 5) Image editing (crop, rotate, resize) updates metadata correctly |
| FR-11 | Date-based media archive queries shall return correct results | Media archives use MySQL date functions on post_date | US-04 | 1) Media archive pages by year, month, and day display correct results 2) Media library date filter controls return correct counts and content |

### 2.4 Plugin and Theme Management

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-12 | Standard plugin lifecycle operations shall succeed | Users must be able to extend WordPress | US-05 | 1) Plugin installation via wp-admin completes without database errors 2) Plugin activation executes any required database table creation or schema changes 3) Plugin deactivation does not corrupt existing data 4) Plugin deletion removes plugin-specific database tables 5) Plugin upgrade process runs schema changes without errors |
| FR-13 | Standard theme lifecycle operations shall succeed | Users must be able to customize appearance | US-05 | 1) Theme installation via wp-admin completes without errors 2) Theme activation applies theme settings stored in the database 3) Theme switching preserves content across themes 4) Theme customizer settings are saved and retrieved correctly |
| FR-14 | MySQL-specific SQL generated by plugins and themes shall be handled transparently | Plugins assume MySQL syntax; no plugin code changes should be needed | US-09 | 1) When a plugin or theme issues MySQL-specific SQL, the query executes without error 2) If a query cannot be translated, the error is reported through standard WordPress error channels, not a system crash 3) The plugin or theme has no mechanism to detect that the database backend is PostgreSQL |

### 2.5 User Authentication

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-15 | All WordPress authentication mechanisms shall function correctly | Authentication is database-backed and must work across database backends | US-04 | 1) Username and password login succeeds with correct credentials and fails with incorrect credentials 2) \"Remember Me\" cookie authentication persists across browser sessions 3) Password reset flow generates a valid reset link, allows setting a new password, and invalidates the old password 4) Application passwords (for REST API and XML-RPC) are created, used, and revoked correctly 5) Session tokens stored in user metadata are created on login and invalidated on logout |
| FR-16 | Passwords hashed on MySQL shall remain valid after migration to PostgreSQL | Password hashing is application-level (wp_hash_password), not database-dependent | US-02 | 1) A user whose password was hashed while the site ran on MySQL can log in after migration 2) New password hashes created on PostgreSQL are compatible with future migrations |

### 2.6 REST API

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-17 | All standard WordPress REST API endpoints shall return correct HTTP status codes and response bodies | The REST API is essential for headless WordPress and the block editor | US-07 | 1) Collection endpoints (`GET /wp/v2/posts`, `/wp/v2/pages`, `/wp/v2/users`, `/wp/v2/media`, `/wp/v2/types`, `/wp/v2/categories`, `/wp/v2/tags`) return correct data 2) Single-resource endpoints (`GET /wp/v2/posts/:id`) return the correct resource 3) Create endpoints (`POST /wp/v2/posts`) persist the resource and return it 4) Update endpoints (`PUT /wp/v2/posts/:id`) modify the resource 5) Delete endpoints (`DELETE /wp/v2/posts/:id`) remove the resource and return confirmation 6) All endpoints return correct HTTP status codes (200, 201, 400, 401, 403, 404) |
| FR-18 | REST API query parameters that generate complex SQL shall return correct results | Parameters like `_embed`, `per_page`, `offset`, `orderby`, and `search` map to SQL clauses | US-07 | 1) Pagination via `per_page` and `offset` returns the correct subset of resources 2) `orderby` with date fields (post_date, modified) returns correctly ordered results 3) `search` parameter returns matching results 4) Embedded data via `_embed` loads without errors 5) Meta queries via `meta_key`/`meta_value` return correct results |
| FR-19 | REST API authentication mechanisms shall function correctly | Protected endpoints require authentication | US-07 | 1) Cookie-authenticated requests from a logged-in session access protected endpoints 2) Application-password-authenticated requests access protected endpoints 3) Unauthenticated requests to protected endpoints return 401 Unauthorized |

### 2.7 WP-CLI

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-20 | Standard WP-CLI information and management commands shall execute without database errors | WP-CLI is the primary automation tool for WordPress | US-08 | 1) `wp post list`, `wp post get`, `wp post create`, `wp post update`, `wp post delete` all succeed 2) `wp user list`, `wp user get`, `wp user create`, `wp user update`, `wp user delete` all succeed 3) `wp comment list`, `wp comment approve`, `wp comment spam`, `wp comment unapprove`, `wp comment delete` all succeed 4) `wp db query` executes SQL statements against PostgreSQL 5) `wp search-replace` correctly updates serialized data in the database 6) `wp option get`, `wp option update`, `wp option delete` all succeed |
| FR-21 | WP-CLI database maintenance commands shall produce correct PostgreSQL equivalents | Commands like `wp db check` and `wp db optimize` must work | US-08 | 1) `wp db check` runs appropriate database diagnostics without errors 2) `wp db optimize` performs storage optimization appropriate to PostgreSQL 3) `wp db size` returns the database size |

### 2.8 Scheduled Tasks (WP-Cron)

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-22 | The WordPress cron system shall execute scheduled tasks correctly | Scheduled tasks are database-backed via the options table | US-04 | 1) Scheduled posts publish at the correct time 2) Plugin-registered cron hooks execute on schedule 3) Core maintenance tasks (expired transient cleanup, scheduled event cleanup) run correctly 4) Cron event storage and retrieval via the options table works correctly |

### 2.9 Database Migration

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-23 | Migration from MySQL/MariaDB to PostgreSQL shall transfer all schema and data without loss | Migration is the primary adoption path | US-02 | 1) All database tables from the MySQL source are created in PostgreSQL 2) All rows from every MySQL table are present in the corresponding PostgreSQL table 3) Row counts match exactly between source and target 4) Sequence/auto-increment starting values are set to MAX(id) + 1 for each table 5) Serialized PHP data in options and metadata tables is preserved byte-for-byte 6) Primary keys, foreign keys, indexes, and unique constraints are created in PostgreSQL |
| FR-24 | Migration shall preserve WordPress-specific schema patterns | Meta tables, options storage, and multisite tables have specific requirements | US-02 | 1) Serialized PHP arrays and objects in wp_options and wp_postmeta are preserved identically 2) Multisite table names (wp_2_posts, wp_3_options, etc.) are handled correctly 3) Plugin-specific tables with custom schemas are migrated without data loss |
| FR-25 | Migration shall be reversible | If PostgreSQL compatibility has issues, operators must be able to revert | US-03 | 1) A rollback procedure exists and is documented 2) Reverting to MySQL restores the site to full functionality 3) No data is lost during the switch from MySQL to PostgreSQL (the MySQL database is preserved) |

### 2.10 Backup and Restore

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-26 | PostgreSQL-native backup and restore procedures shall produce consistent, recoverable backups | Operations teams need reliable backup mechanisms | US-10 | 1) A complete backup of the WordPress database produced with standard PostgreSQL tools can be restored to a clean PostgreSQL instance 2) After restore, all WordPress functions operate correctly 3) Backup verification can be automated |
| FR-27 | Third-party backup plugins that use standard WordPress database APIs shall function correctly | Some deployments rely on backup plugins | US-10 | 1) Backup plugins that read data via `$wpdb` methods produce correct output 2) Backup plugins that write data via `$wpdb` methods restore correctly |

### 2.11 WP-CLI Database Export

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-33 | When WordPress is running on PostgreSQL, `wp db export` shall produce PostgreSQL-compatible SQL output | Users should be able to back up and restore using standard PostgreSQL tooling without thinking about MySQL compatibility | US-08 | 1) Running `wp db export` on a PostgreSQL-backed WordPress produces output restorable via `psql` 2) The output contains PostgreSQL-compatible SQL syntax (no MySQL-specific backtick quoting, ENGINE clauses, or AUTO_INCREMENT) 3) The exported dump can be restored to a clean PostgreSQL database with pg_restore or psql and produces a fully functional WordPress installation |

### 2.12 Multisite

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-28 | WordPress multisite shall function correctly on PostgreSQL | Multisite is a core WordPress feature | US-04 | 1) Site creation in a multisite network creates the correct table set with the correct prefix (e.g., `wp_2_posts`) 2) Site deletion removes the site-specific tables 3) Global tables (`wp_users`, `wp_usermeta`, `wp_blogs`, `wp_sitemeta`) are shared correctly across sites 4) Content in one site is isolated from other sites in the network |

### 2.12 WooCommerce

| ID | Description | Rationale | User Story | Acceptance Criteria |
|----|-------------|-----------|------------|-------------------|
| FR-29 | WooCommerce product management shall function correctly | Products are the core WooCommerce entity | US-12 | 1) Simple, variable, grouped, and external products can be created, read, updated, and deleted 2) Product categories, tags, and attributes are assigned and retrieved correctly 3) Product stock levels are tracked and updated 4) Product images and galleries work |
| FR-30 | WooCommerce order lifecycle shall function correctly | Order processing is critical for e-commerce | US-12 | 1) Orders are created during checkout with correct line items, totals, and status 2) Order status transitions (pending, processing, completed, refunded, cancelled) work correctly 3) Order emails are triggered on status change 4) Order reports and analytics display correct data |
| FR-31 | WooCommerce cart and checkout shall function correctly | The purchase flow must be reliable | US-12 | 1) Adding items to the cart persists correctly 2) Coupon application affects the cart total correctly 3) Checkout completes without database errors 4) Order confirmation page displays correct information |
| FR-32 | WooCommerce REST API shall function correctly | API access is required for headless commerce and integrations | US-12 | 1) Product, order, customer, and coupon API endpoints return correct data 2) API pagination and filtering work correctly |

---

## 3. Non-Functional Requirements

### 3.1 Performance

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-01 | Page load time shall not degrade beyond an acceptable threshold relative to MySQL baseline | < 120% of MySQL baseline time for equivalent pages | Load benchmark suite on identical hardware with both databases |
| NFR-02 | Query translation overhead shall not introduce perceptible latency | < 5 ms average per query translation | Microbenchmark measuring translation time for 1,000 representative queries |
| NFR-03 | Database connection establishment time shall be within acceptable range | < 2x the connection time of MySQL on the same host | Measure connection establishment time for both databases |
| NFR-04 | The full test suite shall complete within a time budget suitable for CI | < 5 seconds | Timed execution of the full test suite |

### 3.2 Reliability

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-05 | All SQL translations shall produce correct PostgreSQL output for supported query patterns | 100% accuracy on documented test cases | Full test suite execution |
| NFR-06 | No SQL translation shall silently produce incorrect results | Zero silent failures | Comprehensive edge-case test coverage |
| NFR-07 | Transaction semantics shall match MySQL behavior | WordPress transaction patterns produce the same results | Integration tests with live PostgreSQL |
| NFR-08 | Database connection failures shall not cause PHP fatal errors | Graceful error messages through standard WordPress channels | Simulate disconnection scenarios |

### 3.3 Availability

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-09 | The compatibility layer shall not introduce additional database downtime | < 5 minutes additional downtime per month outside the database layer | Uptime comparison with and without the compatibility layer |
| NFR-10 | The compatibility layer shall load without errors even if configuration is incomplete | Graceful degradation with logged warning message | Test with missing or invalid configuration constants |
| NFR-23 | The system shall support configurable backup and recovery procedures | Recovery objectives (RTO/RPO) are deployment-specific and shall be definable by the deploying organization without modifying the compatibility layer | Demonstrate that backup and recovery procedures can be configured via external tooling (e.g., pg_dump schedule, WAL archiving config) without changes to the compatibility layer |

### 3.4 Scalability

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-11 | The compatibility layer shall be safe under concurrent requests typical of supported WordPress deployments | Correct results under 10 simultaneous requests with no shared-state corruption | Concurrent request load test; performance testing determines whether additional synchronization is required |
| NFR-12 | The system shall support multisite networks with hundreds of sites | Correct isolation between sites | Multisite test with generated table prefixes |

### 3.5 Observability

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-13 | All translation failures shall be recorded for diagnostic purposes | Zero untranslated queries that fail silently | Integration testing with known-unsupported SQL patterns |
| NFR-14 | Debug mode shall capture original query text and translated query text for every database interaction | Complete diagnostic information available | Manual verification of debug output |
| NFR-15 | Error messages shall be reported through standard WordPress error channels | Error messages accessible via `$wpdb->last_error` and WordPress admin | Test with various error conditions |
| NFR-16 | Database credentials shall never appear in logs, error messages, or debug output | Zero credential leakage | Code review and automated scan |

### 3.6 Portability

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-17 | The compatibility layer shall work on any WordPress 6.4+ installation | No host-specific dependencies | Test on multiple operating systems and hosting environments |
| NFR-18 | The compatibility layer shall not depend on web-server-specific features | Compatible with Apache, Nginx, and IIS | CI pipeline test on each web server type |

### 3.7 Upgradeability

| ID | Description | Target | Verification Method |
|----|-------------|--------|-------------------|
| NFR-19 | Routine WordPress upgrades shall not require database migration or changes to the compatibility layer. Upgrading WordPress on PostgreSQL shall be operationally equivalent to upgrading a standard WordPress installation on MySQL | Forward compatibility with WordPress updates; database compatibility does not become an additional maintenance burden for administrators | CI tests against WordPress \"latest\"; verify that a WordPress upgrade on PostgreSQL completes without database errors and all content is preserved |
| NFR-20 | PHP version upgrades shall not require changes to the compatibility layer | PHP 8.1 through 8.4 | CI matrix tests |
| NFR-21 | PostgreSQL version upgrades shall not require changes to the compatibility layer | PG 14 through 17 | CI matrix tests |
| NFR-22 | The compatibility layer shall detect and report core file incompatibilities that would cause it to malfunction | Warning or error logged when expected patterns are not found | CI pipeline verifying against each WordPress version |
| NFR-24 | Public configuration interfaces shall remain stable within a major version. Database migrations introduced by PG4WP upgrades shall be reversible | Operational stability and predictable upgrade paths | Verify that configuration from version N works on version N+1 without changes; verify that any schema migration can be rolled back |

---

## 4. Compatibility Policy

The following table defines the support level for each category of WordPress code:

| Category | Support Level | Description |
|----------|--------------|-------------|
| WordPress Core | Fully Supported | All core features operate correctly on PostgreSQL. Known differences from MySQL behavior are documented in the compatibility matrix. |
| Supported WordPress Database APIs | Fully Supported | Code using `$wpdb` methods, `wpdb::prepare()`, `WP_Query`, `WP_User_Query`, `WP_Meta_Query`, `WP_Comment_Query`, and standard WordPress database abstractions works without modification. |
| Standard Themes | Expected to Work | Themes distributed through WordPress.org or custom themes using only provided WordPress APIs function correctly. |
| Plugins using the WordPress Database API correctly | Expected to Work | Plugins that interact with the database exclusively through standard WordPress APIs (CRUD functions, WP_Query, `$wpdb` with standard queries) operate correctly. |
| Plugins issuing raw MySQL-specific SQL | Best Effort | Plugins that construct and execute raw MySQL-dialect SQL bypassing WordPress abstractions are handled on a best-effort basis. Known incompatibilities are documented in the compatibility matrix. Support may require plugin-specific translation rules. |
| Plugins depending on MySQL server-specific behavior | Not Guaranteed | Plugins that depend on MySQL-specific server features (e.g., stored procedures, MySQL-specific functions not supported by PostgreSQL, MySQL-specific index types, MySQL error code dependencies) are not guaranteed to function. |

---

## 5. Requirements Traceability Matrix

| Goal | Functional Requirements | Non-Functional Requirements | Success Criteria |
|------|----------------------|---------------------------|------------------|
| G-01 | FR-01, FR-03, FR-04 | NFR-17, NFR-18 | SC-01, SC-02 |
| G-02 | FR-05, FR-06, FR-07, FR-08, FR-09, FR-10 | NFR-05, NFR-06, NFR-07 | SC-03, SC-04, SC-05, SC-06 |
| G-03 | FR-01 | NFR-19 | SC-01, SC-09 |
| G-04 | FR-12, FR-13, FR-14 | NFR-05 | SC-07, SC-08 |
| G-05 | FR-14 | NFR-05, NFR-13 | SC-15 |
| G-06 | FR-17, FR-18, FR-19 | NFR-05 | SC-11 |
| G-07 | FR-20, FR-21, FR-33 | NFR-05 | SC-12, SC-25 |
| G-08 | FR-28 | NFR-12 | SC-02 |
| G-09 | FR-29, FR-30, FR-31, FR-32 | NFR-05 | WooCommerce workflows pass |
| G-10 | FR-23, FR-24, FR-25, FR-26, FR-27 | NFR-03, NFR-23 | SC-21 |

---

## 6. Requirement-to-Test Mapping

| Requirement | Unit Test | Integration Test | Acceptance Test |
|-------------|-----------|-----------------|-----------------|
| FR-01 | — | TS-02 (WordPress installation) | SC-01 |
| FR-02 | — | TS-02 (verify table prefix) | SC-01 |
| FR-03 | — | TS-05 (configuration variants) | SC-01 |
| FR-04 | — | TS-05 (verify no load when mysql) | SC-02 |
| FR-05 | — | TS-07 (post CRUD) | SC-03 |
| FR-06 | — | TS-07 (comment CRUD) | SC-04 |
| FR-07 | — | TS-07 (user CRUD) | SC-05 |
| FR-08 | — | TS-07 (term CRUD) | SC-03 |
| FR-09 | — | TS-07 (metadata CRUD) | SC-03 |
| FR-10 | — | TS-08 (media lifecycle) | SC-06 |
| FR-11 | TS-01 (YEAR() rewrite) | TS-08 (media archives) | Matches MySQL |
| FR-12 | — | TS-09 (plugin lifecycle) | SC-08 |
| FR-13 | — | TS-10 (theme lifecycle) | SC-07 |
| FR-14 | TS-01 (all stubs) | TS-11 (plugin SQL patterns) | SC-15 |
| FR-15 | — | TS-12 (authentication flows) | SC-10 |
| FR-16 | — | TS-13 (migration auth) | SC-10 |
| FR-17 | — | TS-14 (REST API endpoints) | SC-11 |
| FR-18 | TS-01 (complex queries) | TS-14 (REST API params) | SC-11 |
| FR-19 | — | TS-14 (REST API auth) | SC-11 |
| FR-20 | — | TS-15 (WP-CLI commands) | SC-12 |
| FR-21 | — | TS-15 (WP-CLI maintenance) | SC-12 |
| FR-22 | — | TS-16 (cron execution) | No error |
| FR-23 | — | TS-17 (end-to-end migration) | SC-21 |
| FR-24 | — | TS-17 (serialized data) | SC-21 |
| FR-25 | — | TS-18 (rollback procedure) | Documented |
| FR-26 | — | TS-19 (backup/restore) | Verification |
| FR-27 | TS-01 (INSERT patterns) | TS-19 (plugin backup) | — |
| FR-28 | — | TS-20 (multisite operations) | NFR-12 |
| FR-29 | — | TS-21 (WooCommerce CRUD) | — |
| FR-30 | — | TS-21 (WooCommerce orders) | — |
| FR-31 | — | TS-21 (WooCommerce checkout) | — |
| FR-32 | — | TS-21 (WooCommerce API) | — |

[Full test specification in `testing.md`]

---

### Checklist

- [x] Every requirement describes observable system behavior
- [x] No implementation details appear in requirement descriptions
- [x] Every requirement has a unique identifier
- [x] Every requirement includes description, rationale, user story trace, and acceptance criteria
- [x] No duplicate, conflicting, or overlapping requirements
- [x] Acceptance criteria are measurable and verifiable
- [x] Non-functional requirements have specific targets and verification methods
- [x] Requirements trace to goals and success criteria
- [x] Requirements trace to test specifications
- [x] No references to internal implementation details (function names, class names, file paths)
