# Migration Specification: MySQL/MariaDB to PostgreSQL for WordPress

**Version**: 2.0  
**Status**: Draft  
**Last Updated**: 2026-07-02  
**Derived from**: `specs/postgresql-support/spec.md`, `compatibility-analysis.md`

---

## 1. Overview

This specification defines the process for migrating a WordPress deployment from MySQL/MariaDB to PostgreSQL using PG4WP as the compatibility layer and pgloader as the migration tool.

### 1.1 Migration Philosophy

The migration follows a "truck and bus" strategy: export from MySQL, transform, import to PostgreSQL, validate, and switch. Rollback is always available by retaining the MySQL database and switching `DB_DRIVER`.

### 1.2 Constraints

- Migration must not modify WordPress core, plugin, or theme files
- Migration must support both bare-metal and containerized deployments
- Migration must be repeatable and reversible
- Downtime target: < 1 hour for a 10 GB database
- Zero data loss is required

---

## 2. Prerequisites

### 2.1 Environment Prerequisites

| Requirement | Version | Notes |
|-------------|---------|-------|
| PHP | 8.1+ | `pgsql` extension required |
| PostgreSQL | 14-17 | Server accessible from web tier |
| pgloader | Latest | MySQL→PostgreSQL migration tool |
| MySQL/MariaDB | Existing | Source database |
| WordPress | 6.4+ | Fully up-to-date before migration |
| PG4WP | v3.0+ | Files deployed but not yet activated |

### 2.2 Pre-Migration Verification

Before beginning migration:

- [ ] WordPress site is fully backed up (files + MySQL database)
- [ ] All WordPress core, plugins, and themes are up-to-date
- [ ] MySQL database has no corruption (CHECK TABLE all tables)
- [ ] PostgreSQL server is provisioned and accessible
- [ ] PG4WP files are deployed to `wp-content/`
- [ ] A test environment exists with a copy of the production data
- [ ] Rollback plan is documented and tested
- [ ] Maintenance mode plugin is installed and ready

### 2.3 Required Access

| Access | Required For | Duration |
|--------|-------------|----------|
| MySQL root or equivalent | pgloader connection | Migration window |
| PostgreSQL superuser or database owner | Schema creation, pgloader | Migration window |
| WordPress admin | Maintenance mode, verification | Entire process |
| File system (SFTP/SSH) | PG4WP file deployment | Pre-migration |
| Web server management | Potential restart | Migration window |

---

## 3. Migration Steps

### 3.1 Phase 1: Assessment

1. **Inventory the WordPress installation**
   - WordPress version: `wp core version`
   - Active plugins: `wp plugin list`
   - Active theme: `wp theme list`
   - Database size: Check MySQL database size
   - Table count: Count tables and verify against known WordPress schema

2. **Check for known incompatibilities**
   - Review plugins against the compatibility matrix
   - Identify any plugins that use the prepared statement API (`$wpdb->prepare()` with statement objects)
   - Identify any themes or plugins with custom MySQL-specific SQL
   - Check for custom `wpdb` subclasses

3. **Test migration in staging**
   - Clone production database to staging PostgreSQL using pgloader
   - Install PG4WP on staging with PostgreSQL
   - Run through all WordPress admin screens
   - Execute WP-CLI commands
   - Test REST API endpoints
   - Test front-end rendering

### 3.2 Phase 2: Preparation

1. **Provision PostgreSQL server**
   ```ini
   # Recommended postgresql.conf settings for WordPress
   max_connections = 100           # Adjust based on traffic
   shared_buffers = 256MB          # 25% of RAM for dedicated server
   effective_cache_size = 1GB      # 50-75% of available RAM
   work_mem = 16MB                 # Per-operation memory
   maintenance_work_mem = 256MB    # For VACUUM, CREATE INDEX
   wal_buffers = 16MB
   effective_io_concurrency = 200  # SSD
   random_page_cost = 1.1          # SSD
   ```

2. **Create PostgreSQL database and user**
   ```sql
   CREATE USER wp_user WITH PASSWORD 'strong_password';
   CREATE DATABASE wordpress OWNER wp_user;
   GRANT ALL PRIVILEGES ON DATABASE wordpress TO wp_user;
   \c wordpress
   GRANT ALL ON SCHEMA public TO wp_user;
   ```

3. **Configure WordPress for PostgreSQL** (do not activate yet)
   ```php
   // wp-config.php additions:
   define('DB_DRIVER', 'pgsql');
   define('DB_HOST', 'localhost:5432');        // or socket path
   define('DB_NAME', 'wordpress');
   define('DB_USER', 'wp_user');
   define('DB_PASSWORD', 'strong_password');
   define('PG4WP_LOG_ERRORS', true);
   ```

4. **Prepare pgloader configuration**
   ```lisp
   -- pgloader.load
   LOAD DATABASE
        FROM mysql://root:password@localhost/wordpress
        INTO postgresql://wp_user:strong_password@localhost/wordpress

   WITH include drop, create tables, create indexes, reset sequences,
        -- Drop MySQL-specific objects that can't be migrated
        exclude extension ['mysql', 'mysqldb'],
        -- Set PostgreSQL-specific options
        batch rows = 10000, batch concurrency = 1,
        prefetch rows = 1000

   SET PostgreSQL PARAMETERS
        maintenance_work_mem = '256MB',
        work_mem = '16MB'

   SET MySQL PARAMETERS
        net_read_timeout = '300',
        net_write_timeout = '300'

   CAST type datetime to timestamp drop default drop not null using zero-dates-to-null,
        type date drop default drop not null using zero-dates-to-null;

   BEFORE LOAD DO
        $$ CREATE EXTENSION IF NOT EXISTS pgcrypto; $$;
   ```

### 3.3 Phase 3: Migration

**Note**: This is an implementation outline, not executable code.

1. **Enable maintenance mode**
   - Activate a maintenance mode plugin or place a `.maintenance` file
   - Verify that non-admin users see the maintenance page

2. **Export MySQL schema structure for verification**
   - Capture `SHOW CREATE TABLE` for every table
   - Document current auto_increment values
   - Document current row counts

3. **Execute pgloader migration**
   ```bash
   pgloader pgloader.load
   ```

4. **Verify migration output**
   - Check pgloader report for errors
   - Compare row counts between source and target
   - Verify sequences are set to correct values

5. **Set sequences to correct values**
   ```sql
   -- For each SERIAL column, set sequence to MAX(id) + 1
   SELECT setval('wp_posts_id_seq', COALESCE(MAX(id), 0) + 1) FROM wp_posts;
   -- Repeat for all tables with SERIAL columns
   ```

6. **Verify schema integrity**
   - Confirm all tables exist in PostgreSQL
   - Confirm primary keys, foreign keys, and indexes are created
   - Check that ENUM columns are converted to SMALLINT or TEXT

### 3.4 Phase 4: Activation

1. **Configure wp-config.php database credentials** for PostgreSQL
2. **Activate PG4WP** by ensuring `DB_DRIVER` is set to `pgsql`
3. **Test basic functionality**:
   - Load WordPress admin dashboard
   - View a few posts/pages
   - Check user list
   - Run `wp post list` via WP-CLI
4. **Disable maintenance mode**
5. **Monitor error logs** for the first hour of production traffic

### 3.5 Phase 5: Validation

| Check | Method | Expected Result |
|-------|--------|-----------------|
| Page load | Browse multiple pages | No fatal errors, correct content |
| Post CRUD | Create, edit, delete post | Operations succeed |
| User auth | Login, logout | Works correctly |
| Search | Search for known content | Returns correct results |
| Pagination | Browse post list pages | Correct counts |
| Plugin function | Activate/deactivate plugin | Works correctly |
| Media upload | Upload image | Works, metadata stored |
| REST API | GET /wp/v2/posts | Returns JSON |
| WP-CLI | `wp post list --format=count` | Matches expected count |
| PHP error log | Check logs | No PHP errors from PG4WP |

---

## 4. Rollback Plan

### 4.1 Quick Rollback (Switch to MySQL)

If the PostgreSQL deployment has issues, immediate rollback to MySQL:

1. **Edit `wp-config.php`**:
   - Remove `DB_DRIVER=pgsql` (or set to `mysql`)
   - Restore MySQL database credentials

2. **No data loss**: The MySQL database was not modified during migration
3. **Verify**: Load WordPress and confirm MySQL functionality

### 4.2 Full Rollback (Restore from Backup)

If the MySQL database was modified or deleted:

1. **Restore MySQL from backup**: `mysql wordpress < backup.sql`
2. **Edit `wp-config.php`**: Restore MySQL credentials
3. **Remove `DB_DRIVER`** constant
4. **Flush caches**: `wp cache flush`
5. **Verify**: Full functional check

### 4.3 Rollback Window

| Condition | Rollback Feasibility |
|-----------|---------------------|
| Before first PostgreSQL write | Full — MySQL database is unchanged |
| After PostgreSQL writes (admin operations) | Partial — data written to PostgreSQL is lost if not synced back |
| After 24+ hours of PostgreSQL operation | Complex — requires reverse migration or dual-write period |

---

## 5. Recovery Procedures

### 5.1 Restoring from PostgreSQL Backup

```bash
# Drop and recreate the database
dropdb -U wp_user wordpress
createdb -U wp_user wordpress

# Restore from backup
pg_restore -U wp_user -d wordpress --clean --if-exists wordpress_backup.dump
```

### 5.2 Data Integrity Verification

After any restore:

```bash
# Verify row counts against documented baseline
psql -U wp_user -d wordpress -c "SELECT count(*) FROM wp_posts;"
psql -U wp_user -d wordpress -c "SELECT count(*) FROM wp_users;"
psql -U wp_user -d wordpress -c "SELECT count(*) FROM wp_options;"
```

---

## 6. Verification Checklist

### Pre-Migration

- [ ] Complete backup of MySQL database and WordPress files
- [ ] Staging migration tested successfully
- [ ] PostgreSQL server provisioned and configured
- [ ] PG4WP files deployed
- [ ] Rollback plan documented
- [ ] Monitoring tools configured

### Post-Migration

- [ ] WordPress admin dashboard loads
- [ ] All admin pages accessible
- [ ] Front-end pages render correctly
- [ ] Post CRUD works in admin
- [ ] Comment moderation works
- [ ] User management works
- [ ] Media upload works
- [ ] Search returns correct results
- [ ] REST API responds correctly
- [ ] WP-CLI commands work
- [ ] Plugin activation/deactivation works
- [ ] Theme switching works
- [ ] Error logs show no database-related errors
- [ ] Performance is within acceptable range of MySQL baseline

---

## 7. Post-Migration Tasks

- [ ] Configure PostgreSQL backup schedule (`pg_dump` cron job)
- [ ] Set up PostgreSQL monitoring (connection count, query performance, disk usage)
- [ ] Remove wp-config.php MySQL credentials (or secure them)
- [ ] Update runbook to reflect PostgreSQL procedures
- [ ] Document any site-specific compatibility workarounds
- [ ] Monitor error logs for 1 week post-migration

---

### Checklist

- [ ] Migration prerequisites documented
- [ ] Migration phases defined
- [ ] Validation procedures specified
- [ ] Rollback plan documented
- [ ] Recovery procedures specified
- [ ] Verification checklist for pre and post migration
- [ ] Post-migration tasks documented
- [ ] All sections at specification level (no implementation scripts)
