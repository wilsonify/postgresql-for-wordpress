# Operational Requirements: PostgreSQL Backend for WordPress

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02

---

## 1. Deployment Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-01 | The compatibility layer shall be deployable by copying files to `wp-content/` | Simple deployment encourages adoption | 1) Deploying the compatibility layer consists of copying files to `wp-content/` 2) No build step or compilation is required 3) No database schema changes are required for initial deployment |
| OPS-02 | The compatibility layer shall be removable by deleting files from `wp-content/` | Clean removal without leaving artifacts | 1) Deleting the compatibility layer files restores WordPress to standard MySQL behavior 2) No database objects created by the compatibility layer persist 3) No configuration changes are required beyond the file deletion |
| OPS-03 | Enabling PostgreSQL mode shall require only one configuration constant addition to `wp-config.php` | Minimal configuration surface | 1) Setting one configuration constant activates PostgreSQL connectivity 2) Removing the constant restores standard MySQL/MariaDB behavior 3) All other database configuration constants are unchanged |
| OPS-04 | The compatibility layer shall be deployable via standard CI/CD pipelines | Automated deployment is required for production operations | 1) File deployment works via rsync, scp, or artifact copy 2) No interactive configuration steps required 3) Deployment can be scripted |

---

## 2. Upgrade Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-05 | Upgrading the compatibility layer shall not require database changes | Upgrades should be file-only operations | 1) Replacing compatibility layer files is a valid upgrade path 2) No database migration script is required between versions 3) Version compatibility is verified before installation |
| OPS-06 | Upgrading WordPress core shall not require changes to the compatibility layer | WordPress upgrade process must be preserved | 1) Standard WordPress upgrade process (`wp-admin/update-core.php`) completes without errors 2) The compatibility layer continues to function after upgrade 3) If a compatibility issue is detected, a warning is logged |
| OPS-07 | The compatibility layer version shall be discoverable | Operators must know which version is installed | 1) The version is reported through standard WordPress information channels 2) The version is visible in the file header of the main entry point |

---

## 3. Rollback Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-08 | Rolling back to MySQL shall require only configuration changes and credential updates | Fast recovery from incompatibility | 1) Removing the PostgreSQL database driver configuration constant is sufficient to roll back 2) MySQL database credentials must be present and valid 3) The rollback requires no file changes 4) Full recovery to MySQL takes less than 5 minutes |
| OPS-09 | Rolling back shall not require restoring the MySQL database from backup unless data was written to PostgreSQL | The MySQL database is preserved during migration | 1) If no writes occurred to PostgreSQL, the MySQL database is current 2) If writes occurred, a reverse-migration or data synchronization plan is documented |

---

## 4. Backup Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-10 | The recommended backup procedure shall use PostgreSQL-native tools (`pg_dump` / `pg_restore`) | Native tools provide consistent, recoverable backups | 1) `pg_dump` produces a complete, consistent backup of the WordPress database 2) `pg_restore` restores the backup to a clean PostgreSQL instance 3) The backup can be verified without restoring to production |
| OPS-11 | Backup verification shall be automated | Backups must be regularly verified to ensure recoverability | 1) A verification procedure exists and can be automated 2) Verification includes row count comparison and sample data checks 3) Unrecoverable backups generate alerts |
| OPS-12 | Backup files shall be protected from unauthorized access | Backups contain all site data | 1) Backup file permissions are documented 2) Backup encryption is recommended and documented 3) Backup storage location is documented |

---

## 5. Restore Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-13 | Database restore from a PostgreSQL backup shall produce a fully functional WordPress installation | Recovery must be complete | 1) After restore, all WordPress pages render correctly 2) All content, users, and settings are present 3) Plugin and theme data is intact |
| OPS-14 | Restore time shall be predictable and documented | Operations teams need to plan recovery windows | 1) Restore time is proportional to database size 2) A formula for estimating restore time is documented 3) Maximum restore time for a 10 GB database is documented |

---

## 6. Disaster Recovery Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-15 | A disaster recovery plan shall be documented covering PostgreSQL failure scenarios | Preparedness reduces downtime | 1) The plan covers: PostgreSQL server failure, data corruption, configuration loss, and network partition 2) The plan includes recovery time objectives (RTO) and recovery point objectives (RPO) 3) The plan is tested at least annually |
| OPS-16 | Falling back to MySQL shall be a documented disaster recovery option | MySQL rollback is the primary DR path | 1) The DR plan includes switching the database driver back to MySQL 2) MySQL credentials must be maintained during PostgreSQL operation 3) The fallback procedure is tested before production deployment |

---

## 7. Monitoring Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-17 | SQL translation errors shall be monitorable via log aggregation | Operators must detect translation failures | 1) Translation errors are written to a log file in a known location 2) Log entries include timestamp, original query, and error description 3) Log entries do not contain database credentials 4) The log file is compatible with standard log aggregation tools |
| OPS-18 | Database connection health shall be monitorable | Connection failures must be detected quickly | 1) WordPress database error reporting works through standard channels 2) Connection failures are logged with sufficient detail for diagnosis 3) A successful connection health check is documented |
| OPS-19 | Performance degradation shall be detectable via page load time monitoring | Performance regression must trigger investigation | 1) Page load time monitoring is configured and baseline established 2) Significant deviation from baseline triggers an alert 3) PostgreSQL query performance can be analyzed via pg_stat_statements |

---

## 8. Logging Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-20 | Debug logging shall capture the original and translated form of every database query | Enables diagnosis of translation issues | 1) When debug mode is enabled, every SQL query is logged before and after translation 2) Log entries include a timestamp and query identifier 3) Debug mode is disabled by default |
| OPS-21 | Debug logging shall not degrade production performance | Debug mode is for diagnostic use only | 1) Enabling debug mode has a documented and acceptable performance impact 2) Debug mode documentation warns against use in high-traffic production environments |

---

## 9. Troubleshooting Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-22 | A troubleshooting guide shall document common failure scenarios and their resolutions | Operations teams need reference material | 1) The guide covers: database connection failures, translation errors, PHP errors, and performance degradation 2) Each scenario includes symptoms, diagnosis steps, and resolution steps 3) The guide references debug mode for detailed diagnostics |

---

## 10. Maintenance Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-23 | A routine maintenance procedure shall be documented including schedule and commands | Regular maintenance prevents issues | 1) The procedure covers: VACUUM/ANALYZE schedule, index maintenance, connection pool management 2) Maintenance operations are compatible with WordPress operation 3) Maintenance window requirements are documented |
| OPS-24 | PostgreSQL configuration recommendations shall be documented | Proper configuration is essential for performance | 1) Recommended postgresql.conf settings are documented 2) Configuration guidance covers memory, connections, and storage 3) Configuration recommendations include rationale |

---

## 11. Support Requirements

| ID | Description | Rationale | Acceptance Criteria |
|----|-------------|-----------|-------------------|
| OPS-25 | A support process shall be documented for reporting and resolving compatibility issues | Users need a clear path for reporting problems | 1) The issue reporting process is documented 2) Required information for bug reports is specified (WordPress version, PHP version, PG version, plugin versions, SQL query) 3) Response time expectations are documented |

---

### Checklist

- [x] Deployment requirements documented
- [x] Upgrade requirements documented
- [x] Rollback requirements documented
- [x] Backup requirements documented
- [x] Restore requirements documented
- [x] Disaster recovery requirements documented
- [x] Monitoring requirements documented
- [x] Logging requirements documented
- [x] Troubleshooting requirements documented
- [x] Maintenance requirements documented
- [x] Support requirements documented
- [x] All requirements are verifiable
