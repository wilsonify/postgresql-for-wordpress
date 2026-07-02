# Security Specification: PostgreSQL Backend for WordPress

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02

---

## 1. Security Principles

| Principle | Application |
|-----------|-------------|
| Least Privilege | Database credentials used by WordPress must have the minimum privileges required for operation |
| Defense in Depth | Multiple layers of security: network, database, application, credential management |
| No Secrets in Logs | Database credentials must never appear in application logs, error messages, or debug output |
| Encrypt in Transit | All communication between WordPress and PostgreSQL must use TLS |
| Auditability | All security-relevant events must be logged |

---

## 2. Requirements

### 2.1 Database Credentials

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-01 | Database credentials shall be stored only in `wp-config.php` and never in the database or file system outside the web root | Credentials must be protected from exposure | 1) Credentials are defined as PHP constants in `wp-config.php` 2) No credential values are stored in the WordPress database 3) No credential values are written to log files |
| SEC-02 | Database credentials shall never appear in error messages, debug output, or exception traces | Error messages may be displayed to users or logged | 1) When a database connection fails, the error message does not contain the password 2) Debug mode output does not include credential values 3) Stack traces do not include connection parameters |
| SEC-03 | The compatibility layer shall not introduce any mechanism for storing or transmitting credentials outside standard WordPress configuration | No additional credential attack surface | 1) No credential storage mechanism beyond `wp-config.php` constants 2) No credential transmission outside the standard PHP-to-database connection |

### 2.2 Authentication and Authorization

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-04 | The database user specified in `wp-config.php` shall have only the privileges required for WordPress operation | Limits damage from credential compromise | 1) The database user has CONNECT, SELECT, INSERT, UPDATE, DELETE on the WordPress database 2) The database user has USAGE on all sequences 3) The database user has CREATE on the public schema (for plugin table creation) 4) The database user does not have SUPERUSER or CREATEDB privileges 5) Documented privilege configuration is included in the specification |
| SEC-05 | The compatibility layer shall not require or use the PostgreSQL SUPERUSER role | Administrative access should be reserved for database administrators | 1) WordPress operates with a non-superuser database account 2) Administrative operations (CREATE DATABASE, CREATE USER) are performed by a separate privileged account |

### 2.3 Transport Layer Security (TLS)

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-06 | The connection between WordPress and PostgreSQL shall support TLS encryption | Protects data in transit between web server and database | 1) The connection string supports `sslmode` parameter 2) When `sslmode=require` is configured, the connection fails if TLS cannot be established 3) When `sslmode=disable` is configured, the connection proceeds without TLS (for trusted networks) 4) The default behavior is documented and configurable |
| SEC-07 | Server certificate verification shall be supported | Prevents man-in-the-middle attacks | 1) `sslmode=verify-full` enables certificate hostname verification 2) `sslcert`, `sslkey`, and `sslrootcert` connection parameters are supported |

### 2.4 SQL Injection Protection

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-08 | The SQL translation engine shall not introduce SQL injection vulnerabilities | The translation layer must not create new attack vectors | 1) String values in translated SQL are properly escaped 2) Identifier quoting does not introduce unquoted user-controlled values 3) The translation layer is tested against known SQL injection patterns |
| SEC-09 | User-supplied values in database queries shall continue to be parameterized through WordPress's existing `$wpdb->prepare()` mechanism | WordPress applications depend on parameterized queries for SQL injection defense | 1) `$wpdb->prepare()` works correctly with PostgreSQL 2) Placeholder syntax (sprintf-style `%s`, `%d`) is supported 3) Parameterized queries are not corrupted by the SQL translation layer |

### 2.5 Audit Logging

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-10 | Database connection failures and authentication errors shall be logged | Auditing connection issues is essential for security monitoring | 1) Failed connection attempts are logged with timestamp and error type 2) Authentication failures from PostgreSQL are captured and logged 3) Error logs do not contain credential values |
| SEC-11 | The debug logging mechanism shall not be enabled in production by default | Debug logs may expose query patterns and data | 1) Debug logging is opt-in via configuration constant 2) The constant defaults to disabled 3) Enabling debug logging produces a visible log file in a known location |

### 2.6 Backup Security

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-12 | The recommended backup procedure shall include encryption of backup files | Backups contain all site data and must be protected at rest | 1) The backup procedure document includes encryption instructions 2) Encryption uses standard tools (GPG, OpenSSL) 3) Decryption procedure is documented |
| SEC-13 | The recommended backup procedure shall restrict access to backup files | Only authorized personnel should be able to access database backups | 1) File system permissions for backup files are documented 2) Access control recommendations are included in the operations documentation |

### 2.7 Patch Management

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| SEC-14 | The compatibility layer shall be subject to the same vulnerability management process as other production software | Security vulnerabilities must be tracked and resolved | 1) A documented process exists for reporting security issues 2) Security fixes are prioritized and released in a timely manner 3) Compatibility layer versions with known vulnerabilities are documented |

---

## 3. PostgreSQL Security Configuration

### 3.1 pg_hba.conf Recommendations

The following `pg_hba.conf` configuration provides a baseline security posture:

| Connection Type | Database | User | Address | Method |
|----------------|----------|------|---------|--------|
| hostssl | wordpress | wp_user | web_server_ip/32 | scram-sha-256 |
| host | wordpress | wp_user | localhost | scram-sha-256 |
| host | all | all | 0.0.0.0/0 | reject |

### 3.2 Database User Privileges

```sql
-- Recommended privilege set for WordPress database user
GRANT CONNECT ON DATABASE wordpress TO wp_user;
GRANT USAGE ON SCHEMA public TO wp_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO wp_user;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO wp_user;
GRANT CREATE ON SCHEMA public TO wp_user;

-- Default privileges for future objects
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO wp_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE ON SEQUENCES TO wp_user;
```

---

## 4. Security Verification

| Requirement | Verification Method | Frequency |
|-------------|-------------------|-----------|
| SEC-01 (credentials in config only) | Code review | Each release |
| SEC-02 (no credentials in errors) | Automated scan of error messages | Each release |
| SEC-03 (no additional credential storage) | Code review | Each release |
| SEC-04 (least privilege) | Integration test verifying privilege requirements | Each release |
| SEC-05 (no superuser) | Integration test | Each release |
| SEC-06 (TLS support) | Integration test with SSL modes | Each release |
| SEC-07 (certificate verification) | Integration test with verify-full | Each release |
| SEC-08 (no injection in translation) | Security test suite | Each release |
| SEC-09 (parameterized queries) | Integration test with $wpdb->prepare() | Each release |
| SEC-10 (connection logging) | Log inspection integration test | Each release |
| SEC-11 (debug disabled by default) | Verification test | Each release |
| SEC-12 (backup encryption) | Documentation review | Documentation updates |
| SEC-13 (backup access control) | Documentation review | Documentation updates |

---

### Checklist

- [x] Database credential management specified
- [x] Authentication and authorization requirements documented
- [x] TLS requirements documented
- [x] SQL injection protection requirements documented
- [x] Audit logging requirements documented
- [x] Backup security requirements documented
- [x] Patch management process referenced
- [x] PostgreSQL security configuration recommendations included
- [x] Database user privilege model documented
- [x] Each security requirement has verification method and frequency
