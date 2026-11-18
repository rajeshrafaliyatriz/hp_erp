# Handoff Report: MariaDB 10.1 Safety & Model Guards Strategy

**Workspace**: `c:\Users\MILAN\Downloads\hp_erp` (Active branch: `Milan-2`)  
**Milestone**: Milestone 1: MariaDB 10.1 Safety & Model Guards Strategy (Feature F8)  
**Author**: teamwork_explorer (`explorer_m1_3`)  
**Recipient**: Orchestrator (`dbac4f0e-56d8-4ce7-80fd-20503080d664`)  
**Date**: 2026-11-06T07:27:00Z  

---

## 1. Observation

Direct observations from codebase inspection, schema migrations, unit/feature tests, and live database reflection against `hp_erp` (dev host `202.47.117.220:3306` running MariaDB 10.11.9 and production baseline host `128.199.17.97:3306` running MariaDB 10.1.48):

### 1.1 `ColumnExists` Implementation and `Schema::hasColumn()` Defect
- **Implementation File**: `app/Support/ColumnExists.php:1-67`
  - In `ColumnExists::has(string $table, string $column, string|Connection|null $connection = null): bool`:
    ```php
    $conn = $connection instanceof Connection ? $connection : DB::connection($connection);
    $driver = $conn->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        $connName = $connection instanceof Connection ? $connection->getName() : $connection;
        return Schema::connection($connName)->hasColumn($table, $column);
    }

    $pdo = $conn->getPdo();
    $rows = $conn->select(self::showColumnsLikeSql($table, $column, fn (string $v): string => (string) $pdo->quote($v)));

    // LIKE treats "_" and "%" as wildcards, so keep only the exact column name.
    foreach ($rows as $row) {
        if (strcasecmp((string) ($row->Field ?? ''), $column) === 0) {
            return true;
        }
    }

    return false;
    ```
  - In `ColumnExists::showColumnsLikeSql(string $table, string $column, callable $quote): string`:
    ```php
    return 'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $quote($column);
    ```
  - In `ColumnExists::all(string $table): array`:
    ```php
    $driver = DB::connection()->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        return Schema::getColumnListing($table);
    }
    return array_map(fn ($c) => (string) $c->Field, DB::select('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`'));
    ```
- **Verbatim Error of `Schema::hasColumn()` on MariaDB 10.1**:
  ```
  SQLSTATE[42S22]: Column not found: 1054 Unknown column 'generation_expression' in 'field list'
  ```
  Source: `app/Database/Schema/LegacyMariaDbSchemaGrammar.php:16-17` and `tests/Unit/MariaDb101SafetyTest.php:15`.
  Cause: Laravel's `Illuminate\Database\Schema\Grammars\MySqlGrammar::compileColumns()` explicitly queries `generation_expression` from `information_schema.columns`. That column was introduced in MariaDB 10.2 / MySQL 5.7. On MariaDB 10.1.48 (the live host), `generation_expression` does not exist in `information_schema.columns`.
- **SHOW COLUMNS Parameterization Failure (Defect D20, commit `02d4d896`)**:
  - Executing `SHOW COLUMNS FROM <table> LIKE ?` with PDO prepared statements (`$db->select(..., [$column])`) fails on MariaDB 10.1 with:
    ```
    SQLSTATE[42000] [1064]: You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near '?'
    ```
  - `ColumnExists::has()` completely circumvents this by using `$pdo->quote($column)` to create a quoted string literal (`SHOW COLUMNS FROM \`t\` LIKE 'col'`), completely avoiding bound placeholders.
- **LIKE Wildcard Pitfall in SHOW COLUMNS**:
  - SQL `LIKE` treats underscore (`_`) as a single-character wildcard and percent (`%`) as a multi-character wildcard. For example, `LIKE 'lead_score_basis'` could hypothetically match `lead-score-basis` or `leadxscorexbasis`.
  - `ColumnExists::has()` guards against false positives with exact post-filtering:
    `strcasecmp((string) ($row->Field ?? ''), $column) === 0`.

---

### 1.2 Forensic Audit of Eloquent Models & Factories

We audited all models and factories designated in the Milestone 1 mission specification:

#### 1. `App\Domain\OrgAccounts\OrgAccount`
- **File**: `app/Domain/OrgAccounts/OrgAccount.php`
- **Table**: `g2g_org_accounts`
- **Class**: Extends `Illuminate\Database\Eloquent\Model`
- **Guarded Definition** (line 53): `protected $guarded = ['id'];`
- **Fillable Definition**: None
- **Trait Inclusion** (line 25): `use \App\Models\Concerns\SkipsGuardableColumnCheck;`
- **Audit Verdict**: **PASS / COMPLIANT**.

#### 2. `App\Domain\Gtm\GtmOutreachMessage`
- **File**: `app/Domain/Gtm/GtmOutreachMessage.php`
- **Table**: `gtm_outreach_messages`
- **Class**: Extends `Illuminate\Database\Eloquent\Model`
- **Guarded Definition** (line 14): `protected $guarded = ['id'];`
- **Fillable Definition**: None
- **Trait Inclusion** (line 10): `use \App\Models\Concerns\SkipsGuardableColumnCheck;`
- **Audit Verdict**: **PASS / COMPLIANT**.

#### 3. `App\Domain\Gtm\GtmAccount`
- **File**: `app/Domain/Gtm/GtmAccount.php`
- **Table**: `gtm_accounts`
- **Class**: Extends `Illuminate\Database\Eloquent\Model`
- **Guarded Definition** (line 14): `protected $guarded = ['id'];`
- **Fillable Definition**: None
- **Trait Inclusion** (line 10): `use \App\Models\Concerns\SkipsGuardableColumnCheck;`
- **Audit Verdict**: **PASS / COMPLIANT**.

#### 4. `App\Domain\Gtm\GtmContact`
- **File**: `app/Domain/Gtm/GtmContact.php`
- **Table**: `gtm_contacts`
- **Class**: Extends `Illuminate\Database\Eloquent\Model`
- **Guarded Definition** (line 14): `protected $guarded = ['id'];`
- **Fillable Definition**: None
- **Trait Inclusion** (line 10): `use \App\Models\Concerns\SkipsGuardableColumnCheck;`
- **Audit Verdict**: **PASS / COMPLIANT**.

#### 5. `App\Domain\Crm\Leads\LeadFactory`
- **File**: `app/Domain/Crm\Leads\LeadFactory.php`
- **Class**: `final class LeadFactory` (Service class, NOT an Eloquent model).
- **Persistence Method** (line 41):
  ```php
  $id = (int) DB::table('crm_leads')->insertGetId($attributes);
  ```
- **Guarded Definition**: None (does not extend `Model`, has no `$guarded`).
- **Eloquent Guard Introspection**: Does not invoke Eloquent mass assignment or `isGuardableColumn()`.
- **Column Checks** (line 7, line 74):
  ```php
  use App\Support\ColumnExists;
  ...
  $present ??= ColumnExists::all('crm_leads');
  if (! in_array($column, $present, true) || $value <= 0 || ! $visible($tenantId, $value)) {
      unset($attributes[$column]);
  }
  ```
- **Audit Verdict**: **PASS / COMPLIANT** (Safe by architectural design, strictly uses `ColumnExists::all`).

#### Additional Entities Involved in the Lifecycle
- `App\Domain\ProductMaster\ProductMaster`:
  - File: `app/Domain/ProductMaster/ProductMaster.php`
  - Table: `g2g_product_master`
  - Line 15: `use \App\Models\Concerns\SkipsGuardableColumnCheck;`
  - Line 32: `protected $guarded = ['id'];`
  - Verdict: **PASS / COMPLIANT**.
- Tables `crm_signal_inbox`, `crm_organizations`, `crm_contacts`, and `crm_leads`:
  - Do NOT have Eloquent models in `app/Domain/` or `app/Models/`.
  - All access is conducted directly via `DB::table(...)` query builder operations and domain services (`LeadFactory`, `LeadLinks`, `OrgEnsure`).
  - Verdict: **PASS / COMPLIANT** (Zero Eloquent mass-assignment introspection overhead).

---

### 1.3 `SkipsGuardableColumnCheck` Mechanism
- **File**: `app/Models/Concerns/SkipsGuardableColumnCheck.php:43-59`:
  ```php
  trait SkipsGuardableColumnCheck
  {
      /**
       * Whether a given column can be mass-assigned.
       *
       * The parent implementation calls getColumnListing() to decide; that query
       * is what fails on MariaDB 10.1. Every real column is guardable, so this
       * answers true without asking the database.
       *
       * @param  string  $key
       * @return bool
       */
      protected function isGuardableColumn($key)
      {
          return true;
      }
  }
  ```
- By overriding `isGuardableColumn($key)` to return `true`, Eloquent never calls `getColumnListing()`.
- Attributes listed in `$guarded = ['id']` are still protected by `isGuarded($key)`. Non-guarded real attributes are mass-assigned without querying `information_schema.columns`.

---

### 1.4 MariaDB 10.1 Specific Edge Cases & Potential Hazards

From forensic analysis of `tests/Unit/MariaDb101SafetyTest.php`, `config/database.php`, `AppServiceProvider.php`, and database logs:

| # | Hazard / Edge Case | MariaDB 10.1 Mechanism / Error | Mitigation & Rule in hp_erp |
|---|---|---|---|
| 1 | `Schema::hasColumn()` / `getColumnListing()` | `SQLSTATE[42S22]: Unknown column 'generation_expression' in 'field list'` | Strictly use `App\Support\ColumnExists::has($table, $col)` and `ColumnExists::all($table)`. Never call `Schema::hasColumn()`. |
| 2 | Bound parameter in SHOW statements | `SHOW COLUMNS ... LIKE ?` throws `SQLSTATE 42000 / error 1064 (syntax error)` (Defect D20) | Use `ColumnExists::has()` which quotes string literals with `$pdo->quote()` instead of PDO bound parameters. |
| 3 | LIKE wildcard matching | `_` and `%` match arbitrary characters in `SHOW COLUMNS LIKE ...` | `ColumnExists::has()` post-filters rows with `strcasecmp($row->Field, $column) === 0`. |
| 4 | Eloquent `$guarded` introspection | Triggers `getColumnListing()` which fails with `generation_expression` | All `$guarded` models include `\App\Models\Concerns\SkipsGuardableColumnCheck`. |
| 5 | JSON column types and JSON functions | MariaDB 10.1 lacks native JSON (introduced in 10.2.7). `JSON_EXTRACT`, `->>`, `whereJsonContains` throw syntax errors | Store JSON as `TEXT` / `LONGTEXT` (e.g. `crm_signal_inbox.tags`, `crm_leads.lead_score_basis`). Encode/decode purely in PHP (`json_encode`/`json_decode`). |
| 6 | InnoDB 767-byte Index Key Limit | `utf8mb4` uses 4 bytes/char. Indexes on strings > 191 chars (`191 * 4 = 764 <= 767`) throw `Index column size too large` | String columns in indexes must be <= 191 chars (`VARCHAR(191)`). Schema default string length is set to 191 in `AppServiceProvider.php:43`. |
| 7 | Column modification syntax | `$table->string(...)->change()` fails without `doctrine/dbal` | Use raw `ALTER TABLE ... MODIFY` with column guards. |
| 8 | Generated columns & expressions | `virtualAs()`, `storedAs()`, and expression defaults (`default(DB::raw(...))`) fail on 10.1 | Use static defaults or compute attributes in PHP/application layer. |
| 9 | Row locking clauses | `SKIP LOCKED` / `NOWAIT` throw syntax errors on MariaDB 10.1 | Never use `skipLocked()` or `NOWAIT`. Standard `lockForUpdate()` is supported. |
| 10 | Window functions and CTEs | `OVER (PARTITION BY ...)` and `WITH ... AS (...)` fail on 10.1 | Use standard joins and subqueries. |
| 11 | `ColumnExists::all()` Multi-Connection Pitfall | `ColumnExists::all($table)` calls `DB::connection()` (default connection), whereas `ColumnExists::has()` accepts `$connection` | When targeting a non-default connection (e.g. `'db3'` or `'live'`), set `DB::setDefaultConnection($targetConn)` or use `ColumnExists::has($table, $col, $conn)`. |
| 12 | Transaction Boundaries & DDL Implicit Commits | DDL statements (`CREATE`, `ALTER`, `DROP`) trigger an immediate implicit commit in MariaDB | Never execute DDL statements inside `DB::transaction()` blocks. |

---

## 2. Logic Chain

1. **Safety Constraint Enforcement (`Schema::hasColumn` vs `ColumnExists::has`)**:
   - *Premise*: The production environment runs MariaDB 10.1.48 (Host `128.199.17.97`), while development runs MariaDB 10.11.9 (Host `202.47.117.220`).
   - *Observation*: Laravel's `Schema::hasColumn()` selects `generation_expression` from `information_schema.columns` (Obs 1.1). On MariaDB 10.1, this produces `SQLSTATE[42S22]: Unknown column 'generation_expression' in 'field list'` (Obs 1.1).
   - *Observation*: While `App\Database\Schema\LegacyMariaDbSchemaGrammar` patches this query at application boot for MySQL connections in `AppServiceProvider`, direct reliance on `Schema::hasColumn()` creates latent failure modes if grammar swapping is bypassed (e.g. ad-hoc connections, CLI scripts, reconnects) and carries significant `information_schema` table-scan latency.
   - *Inference*: Any proposed seeder (`database/seeders/Tenant6SignalOutreachLifecycleSeeder.php`) or verification script (`scripts/verify_tenant6_lifecycle.php`) must strictly invoke `App\Support\ColumnExists::has()` and `ColumnExists::all()`, with exactly zero calls to `Schema::hasColumn()`.

2. **Model Mass Assignment Safety**:
   - *Observation*: When an Eloquent model declares `$guarded` without `$fillable`, `Model::create()` or `Model::fill()` executes `isGuardableColumn()`. Base `isGuardableColumn()` calls `getColumnListing()`, which issues the same failing query selecting `generation_expression` (Obs 1.1, Obs 1.3).
   - *Observation*: Models `OrgAccount`, `GtmOutreachMessage`, `GtmAccount`, and `GtmContact` all declare `protected $guarded = ['id'];` and each includes `use \App\Models\Concerns\SkipsGuardableColumnCheck;` (Obs 1.2).
   - *Observation*: `LeadFactory` is a service class rather than an Eloquent model; it uses `DB::table('crm_leads')->insertGetId()` and verifies columns via `ColumnExists::all('crm_leads')` (Obs 1.2).
   - *Observation*: Entities `crm_signal_inbox`, `crm_organizations`, `crm_contacts`, and `crm_leads` do not use Eloquent models and operate entirely via raw query builders, bypassing Eloquent guard checks (Obs 1.2).
   - *Inference*: All domain models and factory pathways touched during the Tenant 6 lifecycle are 100% compliant with MariaDB 10.1 mass-assignment safety.

3. **Hazard Mitigation Strategy for Seeder & Verification Implementers**:
   - *Observation*: MariaDB 10.1 rejects bound parameters in `SHOW COLUMNS` (Obs 1.1). `ColumnExists::has()` handles this by quoting the column name with `$pdo->quote()` (Obs 1.1).
   - *Observation*: `crm_signal_inbox.tags` and `crm_leads.lead_score_basis` must store complex payloads without MariaDB 10.1 JSON column syntax (Obs 1.4).
   - *Observation*: `ColumnExists::all()` queries the default database connection (Obs 1.1, Obs 1.4).
   - *Inference*: The seeder and verification harness must:
     a) Only call `ColumnExists::has($table, $column, $conn)` or set `DB::setDefaultConnection($conn)` before calling `ColumnExists::all($table)`.
     b) Use native PHP `json_encode()` and `json_decode()` exclusively for JSON fields.
     c) Avoid any advanced SQL syntax (`SKIP LOCKED`, `NOWAIT`, `JSON_EXTRACT`, CTEs).
     d) Avoid executing DDL within transaction blocks to maintain transactional integrity.

---

## 3. Caveats

1. **Non-Default Connection with `ColumnExists::all()`**:
   - `ColumnExists::has()` accepts an optional `$connection` parameter (`public static function has(string $table, string $column, string|Connection|null $connection = null)`).
   - However, `ColumnExists::all(string $table)` uses `DB::connection()->getDriverName()` and `DB::select(...)` without accepting a connection parameter.
   - If a runner or script specifies `--database=db3` or `--database=live` without calling `DB::setDefaultConnection($target)`, `ColumnExists::all()` will inspect the default connection (`mysql`) rather than the target connection. Implementers must ensure `DB::setDefaultConnection()` is invoked when switching database connections.
2. **Schema::hasTable() vs Schema::hasColumn()**:
   - `Schema::hasTable()` queries `information_schema.tables`, which does NOT mention `generation_expression` and is completely safe on MariaDB 10.1. However, to guarantee 100% adherence to project rules, all column existence checks must exclusively use `ColumnExists::has()`.
3. **Database Version Variance**:
   - Dev host (`202.47.117.220`) runs MariaDB 10.11.9, which is permissive (e.g. accepts bound SHOW parameters, supports JSON, etc.). Therefore, unit tests (`MariaDb101SafetyTest`) and strict code audit are required to ensure code that works on dev does not fail on live MariaDB 10.1.48.

---

## 4. Conclusion

1. **Feature F8 Database Safety Verification**:
   - **Column Existence**: All database column existence checks in the upcoming seeder (`Tenant6SignalOutreachLifecycleSeeder.php`) and verification script (`verify_tenant6_lifecycle.php`) must strictly use `App\Support\ColumnExists::has()` and `App\Support\ColumnExists::all()`. **Zero** calls to `Schema::hasColumn()` or `Schema::getColumnListing()` are permitted.
2. **Eloquent Model Audit**:
   - `App\Domain\OrgAccounts\OrgAccount`: Verified. Uses `protected $guarded = ['id']` and includes `\App\Models\Concerns\SkipsGuardableColumnCheck`.
   - `App\Domain\Gtm\GtmOutreachMessage`: Verified. Uses `protected $guarded = ['id']` and includes `\App\Models\Concerns\SkipsGuardableColumnCheck`.
   - `App\Domain\Gtm\GtmAccount`: Verified. Uses `protected $guarded = ['id']` and includes `\App\Models\Concerns\SkipsGuardableColumnCheck`.
   - `App\Domain\Gtm\GtmContact`: Verified. Uses `protected $guarded = ['id']` and includes `\App\Models\Concerns\SkipsGuardableColumnCheck`.
   - `App\Domain\Crm\Leads\LeadFactory`: Verified. A domain service that creates leads via `DB::table('crm_leads')` with column guards via `ColumnExists::all('crm_leads')`. Safe by architecture.
   - `App\Domain\ProductMaster\ProductMaster`: Verified. Uses `protected $guarded = ['id']` and includes `\App\Models\Concerns\SkipsGuardableColumnCheck`.
   - CRM records (`crm_signal_inbox`, `crm_organizations`, `crm_contacts`, `crm_leads`): Accessed directly via `DB::table(...)` query builder.
3. **Hazard Mitigation**:
   - No prepared parameter placeholders in `SHOW COLUMNS` (prevented by `ColumnExists::has`).
   - No SQL LIKE wildcard false matches (prevented by `strcasecmp` check).
   - No native MySQL/MariaDB JSON functions or column types (text columns with PHP JSON serialization used).
   - No `SKIP LOCKED` or `NOWAIT` row locks.
   - Set `DB::setDefaultConnection()` if running against non-default database connections.

---

## 5. Verification Method

To independently verify all findings and MariaDB 10.1 database safety compliance, run the following commands from the workspace root:

1. **Run Unit Tests for MariaDB 10.1 Safety & ColumnExists**:
   ```powershell
   php artisan test tests/Unit/MariaDb101SafetyTest.php tests/Unit/ColumnExistsTest.php
   ```
   *Expected Result*: All 10 tests pass (28 assertions), confirming:
   - Zero MariaDB 10.1 forbidden constructs in application PHP domains or migrations.
   - `ColumnExists::has()` generates unparameterized quoted literals (`SHOW COLUMNS FROM \`t\` LIKE 'col'`).
   - `ColumnExists::has()` and `ColumnExists::all()` function accurately on both MySQL and SQLite test pathways.

2. **Verify Live Column Existence Probes**:
   ```powershell
   php .agents/teamwork/explorer_m1_2/test_column_exists.php
   ```
   *Expected Result*: All 10 column existence probes on live tables (`crm_signal_inbox`, `gtm_outreach_messages`, `g2g_org_accounts`, `crm_organizations`, `crm_contacts`, `crm_leads`, `gtm_accounts`, `gtm_contacts`) return exact matches with `[OK]`.

3. **Verify Zero `Schema::hasColumn()` Calls in Seeder and Verification Files**:
   ```powershell
   Get-ChildItem -Path database\seeders\Tenant6SignalOutreachLifecycleSeeder.php,scripts\verify_tenant6_lifecycle.php -ErrorAction SilentlyContinue | Select-String -Pattern "Schema::hasColumn"
   ```
   *Expected Result*: 0 occurrences.

4. **Verify Model Trait Inclusion**:
   ```powershell
   Get-ChildItem -Path app\Domain\OrgAccounts\OrgAccount.php,app\Domain\Gtm\GtmOutreachMessage.php,app\Domain\Gtm\GtmAccount.php,app\Domain\Gtm\GtmContact.php | Select-String -Pattern "SkipsGuardableColumnCheck"
   ```
   *Expected Result*: Exactly 4 matching lines, confirming every guarded model includes `\App\Models\Concerns\SkipsGuardableColumnCheck`.

5. **Invalidation Conditions**:
   - Any use of `Schema::hasColumn()` or `Schema::getColumnListing()` in executable code.
   - Any Eloquent model using `$guarded` without `SkipsGuardableColumnCheck` or explicit `$fillable`.
   - Any SQL query utilizing `SHOW ... LIKE ?`, `JSON_EXTRACT`, `whereJsonContains`, `SKIP LOCKED`, or `NOWAIT`.
