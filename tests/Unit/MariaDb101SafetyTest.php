<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Live (the production database) is MariaDB 10.1.48. Everything this effort adds must run there, and the migrations will be run there one file at a time
 * after the owner's go, so the constructs 10.1 cannot run are found HERE, offline, instead of on live (DB-RULES.md section 4).
 *
 * What 10.1 cannot do (each from a real failure or from the engine's release notes):
 *  - a JSON column or a JSON function (needs 10.2.7+): `$table->json()`, `JSON_EXTRACT`, `whereJson...`, the `->>` operator;
 *  - `->change()` on a column (Laravel needs doctrine/dbal for it, which this project does not have): use a guarded raw `ALTER TABLE ... MODIFY`;
 *  - a bound parameter in `SHOW COLUMNS ... LIKE ?` (10.1 refuses it; `ColumnExists::has()` quotes a literal since commit 02d4d896);
 *  - NOT in this list on purpose: `Schema::hasColumn()` and `Schema::getColumnListing()` also fail on 10.1 (they select `generation_expression`), but
 *    `App\Database\Schema\LegacyMariaDbSchemaGrammar` (registered in AppServiceProvider, on origin/main) corrects that query once the code is deployed. That is a DEPLOY
 *    DEPENDENCY of every live migration here (they guard with Schema::hasColumn): the runbook says the code is deployed before `migrate` runs, and a test below pins
 *    that the grammar is still registered;
 *  - a DEFAULT that is an expression (10.2.1+), a generated column (`virtualAs`, `storedAs`);
 *  - `SKIP LOCKED` / `NOWAIT` row locks, window functions (`OVER (`), common table expressions (`WITH ... AS (`, `WITH RECURSIVE`) (10.2+ or later).
 *
 * Scope: the migrations of this effort that are NOT on live yet (2026_12_09 and later: the identity tables, the agent, facts, leads, Ask, campaigns, pipeline and
 * outreach-gate tables) and the PHP of the domains it added. Older migrations are not in scope: the team's JSON tables (2026_12_03, defect D16) are a known,
 * separate matter listed in the hand-over, and the Signals migrations that already ran on live were rehearsed on 10.1 on 3 Nov.
 * Comments are stripped before scanning, so a comment that says "no JSON column" does not trip it.
 */
class MariaDb101SafetyTest extends TestCase
{
    private const FIRST_MIGRATION_NOT_ON_LIVE = '2026_12_09';

    /** Directories of PHP this effort added (new domains and new controllers); app/Domain/Crm/Leads and Crm/Sales hold older team files too, so only OUR files in them are named. */
    private const DOMAINS = [
        'app/Domain/Facts', 'app/Domain/OrgAccounts', 'app/Domain/AgentGateway', 'app/Domain/Ask', 'app/Domain/Campaigns', 'app/Domain/OutreachGate',
        'app/Domain/Numbering', 'app/Domain/ProductMaster', 'app/Http/Controllers/Api/Agent', 'app/Http/Controllers/Api/Facts', 'app/Http/Controllers/Api/Outreach',
    ];

    private const OUR_FILES = [
        'app/Domain/Crm/Leads/LeadNumber.php', 'app/Domain/Crm/Leads/LeadLinks.php', 'app/Domain/Crm/Leads/LeadLinkNames.php', 'app/Domain/Crm/Leads/LeadSignalEvidence.php',
        'app/Domain/Crm/Leads/LeadFacts.php', 'app/Domain/Crm/Leads/LeadConsentFilter.php', 'app/Domain/Crm/Leads/LeadLinkBackfill.php', 'app/Domain/Crm/Leads/LeadDescription.php',
        'app/Domain/Crm/Sales/GtmSyncGuard.php', 'app/Domain/Crm/Sales/GtmResync.php', 'app/Domain/Crm/Sales/OpportunityStageHistory.php', 'app/Domain/Crm/Sales/OpportunityRules.php',
        'app/Domain/Crm/Sales/OpportunityLinkBackfill.php', 'app/Domain/Crm/Sales/ForecastSnapshots.php',
    ];

    /** @var array<string, string> the migration constructs 10.1 refuses: label => regex (run on code with comments removed) */
    private const MIGRATION_RULES = [
        'a JSON column' => '/->\s*jsonb?\s*\(/',
        '->change() (needs doctrine/dbal: use a guarded raw ALTER TABLE ... MODIFY)' => '/->\s*change\s*\(\s*\)/',
        'a generated column' => '/->\s*(virtualAs|storedAs)\s*\(/',
        'a DEFAULT that is an expression (10.2.1+)' => '/->\s*default\s*\(\s*(DB\s*::\s*raw|new\s+Expression)\s*\(/',
    ];

    /** @var array<string, string> constructs 10.1 refuses anywhere in SQL the app writes */
    private const SQL_RULES = [
        'a JSON function' => '/\bJSON_(EXTRACT|UNQUOTE|CONTAINS|SEARCH|OBJECT|ARRAY|SET|VALUE|LENGTH|TYPE|VALID|KEYS|MERGE)\b/i',
        'a JSON query helper' => '/->\s*(whereJsonContains|whereJsonLength|whereJsonDoesntContain|orWhereJsonContains)\s*\(/',
        'a JSON path arrow in SQL (->>)' => '/[`\w\)]\s*->>\s*[\'"`]/',
        'a bound parameter in SHOW ... LIKE ?' => '/\bSHOW\s+(FULL\s+)?(COLUMNS|INDEX|INDEXES|KEYS|TABLES)\b[^;"\']*\bLIKE\s*\?/i',
        'SKIP LOCKED / NOWAIT' => '/\b(SKIP\s+LOCKED|NOWAIT)\b|->\s*skipLocked\s*\(/i',
        'a window function' => '/\bOVER\s*\(\s*(PARTITION|ORDER)\b/i',
        'a common table expression' => '/\bWITH\s+(RECURSIVE\s+)?[a-z_][a-z0-9_]*\s+AS\s*\(/i',
    ];

    /** The code of a PHP file with every comment removed (so documentation that names a forbidden construct is not a use of it). */
    private function code(string $path): string
    {
        return $this->strip((string) file_get_contents($path));
    }

    /** The same, for source held in a string. */
    private function strip(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function migrations(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [];

        return array_values(array_filter($files, fn (string $f) => strcmp(basename($f), self::FIRST_MIGRATION_NOT_ON_LIVE) >= 0 && str_starts_with(basename($f), '2026_12_')));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::DOMAINS as $dir) {
            $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($found as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach (self::OUR_FILES as $rel) {
            if (is_file($root . '/' . $rel)) {
                $files[] = $root . '/' . $rel;
            }
        }

        return $files;
    }

    public function test_there_are_migrations_in_scope_so_the_scan_cannot_pass_by_looking_at_nothing(): void
    {
        $this->assertGreaterThan(20, count($this->migrations()), 'the agentic CRM migrations from 2026_12_09 on');
        $this->assertGreaterThan(40, count($this->phpFiles()), 'the PHP of the domains this effort added');
    }

    public function test_no_migration_not_yet_on_live_uses_what_mariadb_101_cannot_run(): void
    {
        $offences = [];
        foreach ($this->migrations() as $file) {
            $code = $this->code($file);
            foreach ([...self::MIGRATION_RULES, ...self::SQL_RULES] as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $offences[] = basename($file) . ': ' . $label;
                }
            }
        }

        $this->assertSame([], $offences, "MariaDB 10.1 (live) would refuse these migrations; fix them before any live run:\n" . implode("\n", $offences));
    }

    public function test_no_migration_indexes_a_long_string_column_without_a_prefix(): void
    {
        // utf8mb4 needs 4 bytes a character and a 10.1 InnoDB key is at most 767 bytes: a string over 191 characters cannot be in a normal index (the project's rule: a raw prefix index).
        $offences = [];
        foreach ($this->migrations() as $file) {
            $code = $this->code($file);
            preg_match_all('/->\s*string\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(\d+)\s*\)/', $code, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                if ((int) $hit[2] <= 191) {
                    continue;
                }
                $column = preg_quote($hit[1], '/');
                if (preg_match('/->\s*(index|unique|primary)\s*\(\s*(\[[^\]]*[\'"]' . $column . '[\'"][^\]]*\]|[\'"]' . $column . '[\'"])/', $code) === 1) {
                    $offences[] = basename($file) . ": {$hit[1]} ({$hit[2]} characters) is in a normal index";
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", $offences));
    }

    public function test_the_php_of_the_domains_this_effort_added_writes_no_sql_that_mariadb_101_cannot_run(): void
    {
        $offences = [];
        foreach ($this->phpFiles() as $file) {
            $code = $this->code($file);
            foreach (self::SQL_RULES as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $offences[] = str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $file) . ': ' . $label;
                }
            }
        }

        $this->assertSame([], $offences, "MariaDB 10.1 (live) would refuse this SQL:\n" . implode("\n", $offences));
    }

    public function test_the_legacy_grammar_that_makes_schema_hascolumn_work_on_mariadb_101_is_still_registered(): void
    {
        // Every migration of this effort guards with Schema::hasColumn, which 10.1 cannot run without this grammar: if it is ever unregistered, live breaks silently.
        $provider = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Providers/AppServiceProvider.php');
        $this->assertStringContainsString('setSchemaGrammar(new LegacyMariaDbSchemaGrammar(', $provider);
        $this->assertFileExists(dirname(__DIR__, 2) . '/app/Database/Schema/LegacyMariaDbSchemaGrammar.php');
    }

    public function test_the_scan_really_finds_each_forbidden_construct(): void
    {
        // The scan must be able to fail: every rule fires on a sample that uses the construct, and stays quiet on a comment that only names it.
        foreach ([
            '$table->json(\'x\');', '$table->jsonb(\'x\');', '$table->string(\'a\')->change();','$table->string(\'a\')->virtualAs(\'1\');',
            '$table->string(\'a\')->default(DB::raw(\'(uuid())\'));',
        ] as $sample) {
            $this->assertTrue($this->firesOn(self::MIGRATION_RULES, $sample), "no migration rule fires on: {$sample}");
        }
        foreach ([
            'SELECT JSON_EXTRACT(a, \'$.b\') FROM t', '$q->whereJsonContains(\'a\', 1);', 'SELECT a->>\'$.b\' FROM t', 'SELECT * FROM t FOR UPDATE SKIP LOCKED',
            'SELECT ROW_NUMBER() OVER (PARTITION BY a ORDER BY b) FROM t', 'SHOW COLUMNS FROM t LIKE ?', 'WITH RECURSIVE r AS (SELECT 1) SELECT * FROM r', 'WITH x AS (SELECT 1) SELECT * FROM x',
        ] as $sample) {
            $this->assertTrue($this->firesOn(self::SQL_RULES, $sample), "no SQL rule fires on: {$sample}");
        }
        $comment = "<?php\n// no json() column, no ->change(), no JSON_EXTRACT, no SKIP LOCKED\n/* SHOW COLUMNS FROM t LIKE ? */\n\$a = 1;\n";
        $this->assertFalse($this->firesOn([...self::MIGRATION_RULES, ...self::SQL_RULES], $this->strip($comment)), 'a comment that only names a construct is not a use of it');
    }

    /** @param array<string, string> $rules */
    private function firesOn(array $rules, string $code): bool
    {
        foreach ($rules as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return false;
    }
}
