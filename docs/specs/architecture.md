# Architecture: PostgreSQL Backend for WordPress

**Version**: 3.0  
**Status**: Draft  
**Last Updated**: 2026-07-02  
**Purpose**: Document the current and target architectures — not requirements, but architectural context

---

## 1. Current Architecture (MariaDB/MySQL)

### 1.1 Component Diagram

```mermaid
graph TB
    subgraph "Web Server Layer"
        HTTP[HTTP Request]
        WP[WordPress]
    end

    subgraph "WordPress Core"
        wpdb[wpdb Class]
        WPDB_API[Database API<br/>query, get_results,<br/>insert, update, delete]
    end

    subgraph "PHP Driver"
        MYSQLI[PHP MySQLi Extension]
    end

    subgraph "Database Server"
        MYSQL[MariaDB / MySQL Server]
        MY_SCHEMA[WordPress Schema<br/>wp_posts, wp_options,<br/>wp_usermeta, ...]
    end

    HTTP --> WP
    WP --> wpdb
    wpdb --> WPDB_API
    WPDB_API -->|mysqli_* calls| MYSQLI
    MYSQLI --> MYSQL
    MYSQL --> MY_SCHEMA
```

### 1.2 Request Flow (MariaDB)

```mermaid
sequenceDiagram
    participant WP as WordPress
    participant wpdb as wpdb
    participant MySQLi as PHP MySQLi
    participant MySQL as MariaDB

    WP->>wpdb: $wpdb->query("SELECT * FROM wp_posts")
    wpdb->>MySQLi: mysqli_query(connection, sql)
    MySQLi->>MySQL: SELECT * FROM wp_posts
    MySQL-->>MySQLi: Result Set
    MySQLi-->>wpdb: PHP result object
    wpdb-->>WP: PHP objects / arrays
```

---

## 2. Target Architecture (PostgreSQL via PG4WP)

### 2.1 Component Diagram

```mermaid
graph TB
    subgraph "Web Server Layer"
        HTTP[HTTP Request]
        WP[WordPress]
    end

    subgraph "WordPress Core (Unmodified)"
        wpdb[wpdb Class]
    end

    subgraph "Compatibility Layer"
        BOOT[Bootstrap<br/>wp-content/db.php]
        DRIVER[Database Driver<br/>wpsqli_* implementation]
        TRANSLATOR[SQL Translator<br/>MySQL → PostgreSQL]
    end

    subgraph "PHP Driver"
        PGSQL[PHP pgsql Extension]
    end

    subgraph "Database Server"
        PG[PostgreSQL Server]
        PG_SCHEMA[WordPress Schema<br/>wp_posts, wp_options,<br/>wp_usermeta, ...]
    end

    HTTP --> WP
    WP --> wpdb
    wpdb -->|intercepted calls| BOOT
    BOOT -->|loads| DRIVER
    DRIVER -->|sends SQL| TRANSLATOR
    TRANSLATOR -->|translated SQL| DRIVER
    DRIVER -->|pg_* calls| PGSQL
    PGSQL --> PG
    PG --> PG_SCHEMA
```

### 2.2 Deployment Diagram

```mermaid
graph TB
    subgraph "Web Server"
        FS[File System<br/>WordPress + PG4WP files]
        PHP_RT[PHP Runtime<br/>8.1 - 8.4]
        PHP_PG[PHP pgsql Extension]
    end

    subgraph "Database Server"
        PG[PostgreSQL Server<br/>14 - 17]
        WP_DB[(WordPress Database)]
    end

    subgraph "Administration"
        WPCLI[WP-CLI]
        PG_TOOLS[PostgreSQL Tools<br/>psql, pg_dump, pg_restore]
        MIGRATION[pgloader<br/>Migration Tool]
    end

    PHP_PG -->|pg_connect()| PG
    FS --> PHP_RT
    PHP_RT --> PHP_PG
    MIGRATION -->|MySQL → PostgreSQL| PG
    WPCLI --> PHP_RT
    PG_TOOLS --> PG
```

### 2.3 Request Flow (PostgreSQL)

```mermaid
sequenceDiagram
    participant WP as WordPress
    participant wpdb as wpdb (patched)
    participant BOOT as Bootstrap
    participant DRIVER as Driver Layer
    participant TRANS as SQL Translator
    participant PGSQL as PHP pgsql
    participant PG as PostgreSQL

    WP->>wpdb: $wpdb->query("SELECT * FROM wp_posts")
    Note over wpdb: MySQLi calls redirected<br/>to compatibility layer
    
    wpdb->>DRIVER: driver_query("SELECT * FROM wp_posts")
    
    DRIVER->>TRANS: translate_sql("SELECT * FROM wp_posts")
    Note over TRANS: MySQL → PostgreSQL<br/>syntax conversion
    TRANS-->>DRIVER: "SELECT * FROM wp_posts"
    
    DRIVER->>PGSQL: pg_query(connection, translated_sql)
    PGSQL->>PG: SELECT * FROM wp_posts
    PG-->>PGSQL: Result Set
    PGSQL-->>DRIVER: PHP result object
    
    DRIVER-->>wpdb: Result
    wpdb-->>WP: PHP objects / arrays
```

### 2.4 Startup Sequence

```mermaid
sequenceDiagram
    participant WP as WordPress Bootstrap
    participant DROP as Drop-in (db.php)
    participant BOOT as Bootstrap
    participant DRIVER as Driver
    participant PGSQL as PHP pgsql
    participant PG as PostgreSQL

    WP->>WP: Load wp-config.php
    WP->>DROP: Load wp-content/db.php
    
    DROP->>BOOT: Load compatibility layer
    Note over BOOT: Detect DB_DRIVER constant
    
    BOOT->>BOOT: Prepare patched wpdb class
    BOOT->>DRIVER: Initialize driver
    
    DRIVER->>PGSQL: pg_connect(connection_string)
    PGSQL->>PG: TCP Connection
    PG-->>PGSQL: Connected
    PGSQL-->>DRIVER: Connection handle
    
    DRIVER-->>BOOT: Connection established
    BOOT-->>DROP: $wpdb instance ready
    
    WP->>WP: Normal WordPress bootstrap continues
    WP->>WP: Load plugins, theme, execute request
```

### 2.5 Database Schema Lifecycle

```mermaid
stateDiagram-v2
    [*] --> MySQL_Existing: Before migration
    MySQL_Existing --> Pg_Schema: pgloader schema conversion
    Pg_Schema --> Pg_Data: pgloader data transfer
    Pg_Data --> Pg_Active: Switch DB_DRIVER to pgsql
    
    state Pg_Active {
        [*] --> Normal_Ops: WordPress boots on PostgreSQL
        Normal_Ops --> Plugin_Install: Plugin creates tables
        Plugin_Install --> Normal_Ops
        Normal_Ops --> Core_Upgrade: WordPress upgrade
        Core_Upgrade --> Normal_Ops
    }
    
    Pg_Active --> Pg_Backup: Scheduled backup
    Pg_Backup --> Pg_Active: Restore if needed
    Pg_Active --> MySQL_Existing: Rollback to MySQL
```

---

## 3. Architectural Considerations

### 3.1 WordPress Drop-in Plugin Architecture

WordPress's `wp-settings.php` automatically loads `wp-content/db.php` if it exists, allowing the database driver and connection to be replaced before any WordPress code uses the database. This is the architectural hook that enables the compatibility layer without core modifications.

### 3.2 Runtime wpdb Patching

The compatibility layer reads the WordPress core file `class-wpdb.php`, applies string replacements to redirect all MySQLi function calls to compatibility equivalents, and evaluates the result in memory. This produces a patched runtime class without modifying the files on disk.

**Risks**: The patching is sensitive to formatting changes in `class-wpdb.php`. See ADR-002 for detailed analysis.

### 3.3 Statement-Type Translation Factory

SQL translation follows a delegation pattern:
1. The raw SQL string is classified by statement type (SELECT, INSERT, etc.) using pattern matching
2. A type-specific translator performs the MySQL-to-PostgreSQL conversion
3. Global post-processing normalizes quoting, capitalization, and edge cases

**Benefit**: Each SQL statement type has an isolated translator, making them independently testable and maintainable.

### 3.4 Stub-Based Translation Verification

Translations are verified using input/output pairs: a MySQL SQL string and its expected PostgreSQL equivalent. Tests run without a database, enabling fast, comprehensive regression coverage.

### 3.5 State Management

The compatibility layer maintains request-scoped state in globally accessible variables:
- The current result set from the last query
- Metadata about the last INSERT (table name, field name) for insert ID retrieval
- A cached count query for pagination support
- Connection error state

**Risk**: State shared across operations within a single request must be carefully managed. See ADR-007.

---

## 4. Architectural Constraints

| Constraint | Source | Rationale |
|-----------|--------|-----------|
| No WordPress core file modifications | Project principle | Maintains upgrade compatibility |
| All compatibility code in `wp-content/` | WordPress convention | Clean isolation, easy enable/disable, standard drop-in mechanism |
| Translation must be stateless except for explicitly documented operations | Scalability | Prevents cross-query interference under concurrent requests |
| Errors must propagate through standard WordPress channels | Observability | Debugging tools and plugins must work unchanged |
| Must support standard deployment topologies | Portability | Single server, load-balanced, containerized |

---

## 5. Component Interaction Matrix

| Component | Interacts With | Interface | Data Format |
|-----------|---------------|-----------|-------------|
| WordPress Core | Compatibility layer (via patched wpdb) | PHP function calls | PHP scalars, arrays, objects |
| Compatibility Driver | PHP pgsql extension | PHP function calls | PHP scalars, connection handles |
| SQL Translator | Compatibility Driver | PHP function calls | SQL strings |
| PHP pgsql | PostgreSQL server | PostgreSQL wire protocol (TCP 5432) | Binary/text protocol |
| Logging subsystem | File system | File write | Text log entries |

---

### Checklist

- [x] Current architecture documented with component diagram
- [x] Target architecture documented with component diagram
- [x] Deployment diagram for target architecture
- [x] Request flow diagrams for both architectures
- [x] Startup sequence diagram
- [x] Schema lifecycle diagram
- [x] Architectural considerations documented (not requirements)
- [x] Architectural constraints documented
- [x] Component interaction matrix documented
- [x] No implementation-specific internal APIs exposed at the specification level
