## PostgreSQL for WordPress (PG4WP) — v3.0

### Description

PostgreSQL for WordPress (PG4WP) gives you the possibility to install and use WordPress with a PostgreSQL database as a backend. This branch (v3) is a comprehensive engineering overhaul targeting production readiness for WordPress 6.4+.

#### Use Cases

- Run WordPress on your existing PostgreSQL cluster
- Run WordPress with georeplication using [EDB Postgres Distributed](https://www.enterprisedb.com/products/edb-postgres-distributed) or [CockroachDB](https://www.cockroachlabs.com/serverless/) for highly available WordPress infrastructure

### Design

PG4WP works by intercepting calls to the `mysqli_*` driver in WordPress's `wpdb` class, replacing them with `wpsqli_*` calls implemented by the PostgreSQL driver files in this plugin.

![PG4WP Design](docs/images/pg4wp_design.png)

### Supported Versions

| Component | Versions |
|-----------|----------|
| WordPress | 6.4+ |
| PHP | 8.1, 8.2, 8.3, 8.4 |
| PostgreSQL | 14, 15, 16, 17 |

### Plugin and Theme Support

PG4WP provides SQL-level compatibility for all WordPress plugins and themes. No plugin-specific code is integrated (except plugins shipped with WordPress such as Akismet).

| Plugin | Version | Status |
|--------|---------|--------|
| Debug Bar | 1.1.4 | Confirmed |
| Yoast Duplicate Post | 4.2 | Confirmed |
| Akismet | Latest | Confirmed |
| All in One SEO | Latest | Partial |
| Complianz GDPR | Latest | Partial |
| WP Statistics | Latest | Partial |

| Theme | Version | Status |
|-------|---------|--------|
| Twenty Twenty-Four | 1.0 | Confirmed |
| Twenty Twenty-Three | 1.2 | Confirmed |
| Twenty Twenty-Two | 1.5 | Confirmed |
| Twenty Twenty-One | 1.9 | Confirmed |

### SQL Rewrite Coverage

- **DML**: SELECT, INSERT (incl. SET syntax, IGNORE, ON DUPLICATE KEY), UPDATE, DELETE
- **DDL**: CREATE TABLE, ALTER TABLE, DROP TABLE (all with reserved-word quoting)
- **Meta queries**: SHOW TABLES, SHOW COLUMNS, SHOW FULL COLUMNS, SHOW INDEX, SHOW VARIABLES, DESCRIBE
- **MySQL functions**: YEAR, MONTH, DAY, DATE_ADD, DATE_SUB, FIELD, IF, RAND, GROUP_CONCAT, UNIX_TIMESTAMP, UTC_TIMESTAMP, REGEXP, CONVERT, GET_LOCK, RELEASE_LOCK, SQL_CALC_FOUND_ROWS
- **Driver API**: Full `mysqli_` compatibility (connection, query, prepared statements, result fetching, transactions, async, error handling — zero unimplemented functions)
- **Regression tests**: 520+ SQL rewrite test stubs, 18 PHPUnit methods

### Installation

Install PG4WP *before* configuring WordPress (the database must be operational before any plugin loads):

1. Place your WordPress files on your web server
2. Download the latest release from the [releases page](https://github.com/PostgreSQL-For-Wordpress/postgresql-for-wordpress/releases)
3. Unzip PG4WP and put the `pg4wp` directory in your `wp-content/` directory
4. Copy `pg4wp/db.php` to `wp-content/db.php`
5. Create `wp-config.php` from `wp-config-sample.php` (if not already present)
6. Point your browser to your WordPress installation and follow the standard installation routine

### Contributing

Contributions welcome. Open a pull request with your changes and ensure tests pass:

```
./tests/tools/phpunit-11.phar tests/
```

If you find a failing scenario, please add a test. PRs that fix a scenario without a test will not be accepted.

### License

PG4WP is provided "as-is" with no warranty. Licensed under the [GNU GPL](http://www.gnu.org/licenses/gpl.html) v2 or any newer version at your choice.

### Contributors

Code originally by Hawk\_\_ (http://www.hawkix.net/)
Modifications by @kevinoid and @mattbucci
Engineering overhaul by the PG4WP v3 team