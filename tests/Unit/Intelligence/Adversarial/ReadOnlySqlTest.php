<?php

namespace Tests\Unit\Intelligence\Adversarial;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * 3. READ-ONLY GUARANTEE and 4. PORTABILITY + SAFETY OF THE SQL ACTUALLY EXECUTED.
 *
 * The statements are not read from the source: they are captured from the connection while the real service runs every detector for every role, against a
 * database holding two tenants. What the database was asked is what is judged.
 *
 * INTROSPECTION ALLOW-LIST. The HR detectors check that an optional table/column exists before using it (AbstractHrDetector::canRead). On SQLite the schema
 * builder does that with `select * from sqlite_master ...` / `pragma table_info(...)`. Those are driver-level, read-only, reach no application data and have no
 * tenant; they are the ONLY statements exempt from the "no select *" and tenant-binding rules, and they are matched exactly, not by prefix.
 */
class ReadOnlySqlTest extends AdversarialTestCase
{
    /** Driver introspection issued by the schema builder (hasTable / hasColumn). Exact shapes only. */
    private function isIntrospection(string $sql): bool
    {
        $s = trim(preg_replace('/\s+/', ' ', $sql));

        // The three shapes Laravel 11's SQLite schema builder issues for hasTable()/hasColumn(), matched EXACTLY (table name is the only variable part):
        return (bool) preg_match('/^select exists \(select 1 from "main"\.sqlite_master where name = \'[a-z0-9_]+\' and type = \'table\'\) as "exists"$/', $s)
            || (bool) preg_match('/^select name, type, not "notnull" as "nullable", dflt_value as "default", pk as "primary", hidden as "extra" from pragma_table_xinfo\(\'[a-z0-9_]+\', \'main\'\) order by cid asc$/', $s)
            || (bool) preg_match('/^select "sql" from "main"\.sqlite_master where type = \'table\' and name = \'[a-z0-9_]+\'$/', $s);
    }

    /**
     * @return array<string, list<array{sql: string, bindings: array}>>  role => statements executed while the service ran for that role
     */
    private function capturedPerRole(): array
    {
        $world = $this->seedWorld(self::T1, self::B1);
        $this->seedWorld(self::T2, self::B2, ['manager' => self::B1 + 10, 'sentinel' => true]);
        $this->seedCrossReferences(self::T2, $world, self::T1);

        $out = [];
        foreach (self::ROLES as $role) {
            $caller = $this->callerFor($role, self::T1, self::B1);
            $out[$role] = $this->recordQueries(fn () => $this->service()->run($caller));
        }

        return $out;
    }

    public function test_every_executed_statement_is_a_select_and_nothing_opens_a_transaction(): void
    {
        $world = $this->seedWorld(self::T1, self::B1);
        $this->seedCrossReferences(self::T2, $world, self::T1);

        $transactions = 0;
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            $this->app['events']->listen($event, function () use (&$transactions) {
                $transactions++;
            });
        }

        $statements = 0;
        foreach (self::ROLES as $role) {
            foreach ($this->recordQueries(fn () => $this->service()->run($this->callerFor($role, self::T1, self::B1))) as $q) {
                $statements++;
                $sql = strtolower(trim($q['sql']));
                $this->assertMatchesRegularExpression('/^(select\b|pragma\s+table_(x)?info\()/', $sql, "$role issued a statement that is not a SELECT: {$q['sql']}");
                $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|replace|create|drop|alter|truncate|vacuum|attach|detach|reindex|savepoint|release)\b/', $sql,
                    "$role: write keyword in: {$q['sql']}");
                $this->assertStringNotContainsString(';', rtrim($sql, ';'), 'a single statement only: ' . $q['sql']);
            }
        }

        $this->assertSame(0, $transactions, 'detectors and the service must not open, commit or roll back a transaction');
        $this->assertGreaterThan(100, $statements, 'the capture must actually have seen the detectors work');
    }

    public function test_data_is_unchanged_by_a_full_run(): void
    {
        $world = $this->seedWorld(self::T1, self::B1);
        $this->seedCrossReferences(self::T2, $world, self::T1);
        $tables = ['tbluser', 'hrms_attendances', 'hrms_emp_leaves', 'hrms_leave_approval_steps', 's_competency_certifications', 'lms_certificates', 'talent_job_applications',
            'talent_offers', 'talent_onboarding_journeys', 'talent_onboarding_tasks', 'g2g_company_opportunities', 'g2g_research_runs', 'gtm_deals', 'gtm_activities', 'gtm_contacts',
            'gtm_renewals', 'gtm_health_snapshots', 'crm_opportunities', 'crm_invoices'];
        $fingerprint = fn () => array_map(fn ($t) => md5(json_encode($this->db()->table($t)->orderBy('id')->get()->all())), $tables);

        $before = $fingerprint();
        foreach (self::ROLES as $role) {
            $this->service()->run($this->callerFor($role, self::T1, self::B1));
            $this->service()->run($this->callerFor($role, self::T2, self::B2));
        }

        $this->assertSame($before, $fingerprint(), 'a run must leave every table byte-for-byte unchanged');
    }

    public function test_the_sql_uses_no_database_specific_date_functions_ctes_windows_json_or_select_star(): void
    {
        $forbidden = [
            'DATE_SUB' => '/\bdate_sub\s*\(/i', 'DATE_ADD' => '/\bdate_add\s*\(/i', 'TIMESTAMPDIFF' => '/\btimestampdiff\s*\(/i', 'DATEDIFF' => '/\bdatediff\s*\(/i',
            'NOW()' => '/\bnow\s*\(/i', 'CURDATE()' => '/\bcurdate\s*\(/i', 'CURRENT_DATE' => '/\bcurrent_(date|timestamp|time)\b/i', 'strftime' => '/\bstrftime\s*\(/i',
            'julianday' => '/\bjulianday\s*\(/i', 'datetime()' => '/\bdatetime\s*\(/i', 'EXTRACT' => '/\bextract\s*\(/i',
            'CTE (WITH ... AS)' => '/^\s*with\b/i', 'window function (OVER)' => '/\bover\s*\(/i', 'JSON function' => '/\bjson_\w+\s*\(/i', 'JSON operator' => '/->>?\s*[\'"]/',
            'FILTER clause' => '/\bfilter\s*\(\s*where\b/i', 'RETURNING' => '/\breturning\b/i',
        ];
        $checked = 0;
        foreach ($this->capturedPerRole() as $role => $queries) {
            foreach ($queries as $q) {
                if ($this->isIntrospection($q['sql'])) {
                    continue;
                }
                $checked++;
                foreach ($forbidden as $what => $pattern) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $q['sql'], "$role: $what in: {$q['sql']}");
                }
                $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $q['sql'], "$role: SELECT * in: {$q['sql']}");
            }
        }
        $this->assertGreaterThan(100, $checked);
    }

    public function test_every_statement_on_tenant_data_carries_the_callers_tenant_in_its_bindings_once_per_table_it_reads(): void
    {
        // Tables reached ONLY THROUGH A PARENT (no tenant column of their own). The detectors read none of them today; the list is the explicit, justified
        // exception and must stay empty unless a reviewer adds a reason next to the table:
        $reachedThroughParent = [
            // g2g_event_delivery has no tenant column; it is reached ONLY by joining its parent g2g_event, whose sub_institute_id is bound to the caller's tenant.
            'g2g_event_delivery',
        ];

        $tenantTables = ['tbluser', 'hrms_attendances', 'hrms_emp_leaves', 'hrms_leave_approval_steps', 's_competency_certifications', 'lms_certificates', 'talent_job_applications',
            'talent_offers', 'talent_onboarding_journeys', 'talent_onboarding_tasks', 'g2g_company_opportunities', 'g2g_research_runs', 'gtm_deals', 'gtm_activities', 'gtm_contacts',
            'gtm_renewals', 'gtm_health_snapshots', 'gtm_accounts', 'crm_opportunities', 'crm_invoices', 'talent_offer_acceptances',
            // platform / AI governance / agentic (g2g_platform_task_runs also admits the NULL-tenant installation-wide runs: the tenant is still bound exactly once)
            'g2g_platform_task_runs', 'g2g_platform_scheduled_tasks', 'g2g_integration_credentials', 'g2g_event', 'g2g_event_delivery', 'ai_usage_events', 'ai_usage_quotas',
            'ai_policies', 'ai_policy_assignments', 'agentic_agents', 'agentic_agent_runs'];

        $checked = 0;
        $tablesSeen = [];
        foreach ($this->capturedPerRole() as $role => $queries) {
            foreach ($queries as $q) {
                if ($this->isIntrospection($q['sql'])) {
                    continue;
                }
                preg_match_all('/\b(?:from|join)\s+"([a-z0-9_]+)"/i', $q['sql'], $m);
                $refs = array_values(array_filter($m[1], static fn ($t) => true));
                foreach ($refs as $table) {
                    $tablesSeen[$table] = true;
                    if (in_array($table, $reachedThroughParent, true)) {
                        continue;
                    }
                    $this->assertContains($table, $tenantTables, "$role: statement reads a table that is not in the known tenant-bearing list ($table): {$q['sql']}");
                }
                $tenantRefs = count(array_filter($refs, static fn ($t) => ! in_array($t, $reachedThroughParent, true)));
                // A joined table whose tenant column is tied by EQUALITY to the tenant of a table that is already bound (`a.sub_institute_id = s.sub_institute_id`)
                // is covered by that equality: it can only match rows of the bound tenant. Each such equality covers one extra table.
                $linked = preg_match_all('/"[a-z0-9_]+"\."sub_institute_id" = "[a-z0-9_]+"\."sub_institute_id"/', $q['sql']);
                $tenantRefs = max(1, $tenantRefs - $linked);
                $bound = count(array_filter($q['bindings'], static fn ($b) => (string) $b === (string) self::T1));
                $this->assertGreaterThanOrEqual($tenantRefs, $bound,
                    "$role: {$tenantRefs} table reference(s) but the caller's tenant is bound only {$bound} time(s): {$q['sql']}");
                $checked++;
            }
        }

        $this->assertGreaterThan(100, $checked);
        // The whole data surface was reached, and nothing else: no payroll, performance or other table.
        $unexpected = array_diff(array_keys($tablesSeen), $tenantTables);
        $this->assertSame([], array_values($unexpected), 'detectors read tables outside the Phase 1 list: ' . implode(',', $unexpected));
        foreach (['hrms_attendances', 'hrms_emp_leaves', 'hrms_leave_approval_steps', 's_competency_certifications', 'lms_certificates', 'talent_job_applications', 'talent_offers',
            'talent_onboarding_journeys', 'talent_onboarding_tasks', 'g2g_company_opportunities', 'g2g_research_runs', 'gtm_deals', 'gtm_activities', 'gtm_contacts', 'gtm_renewals',
            'gtm_health_snapshots', 'crm_opportunities', 'crm_invoices', 'tbluser'] as $expected) {
            $this->assertArrayHasKey($expected, $tablesSeen, "the capture never exercised $expected");
        }
    }

    public function test_every_binding_is_a_bound_parameter_not_interpolated_user_text(): void
    {
        // Hostile text in the one place a caller could influence a query: the service's filters. It must be bound (or ignored), never interpolated.
        $world = $this->seedWorld(self::T1, self::B1);
        $this->assertNotEmpty($world['people']);
        $evil = "x' OR 1=1 -- ";
        $queries = $this->recordQueries(fn () => $this->service()->run($this->callerFor('administrator', self::T1, self::B1), [
            'domain' => $evil, 'severity' => $evil, 'category' => $evil, 'surface' => $evil, 'detector' => $evil,
        ]));
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('1=1', $q['sql']);
            $this->assertStringNotContainsString($evil, $q['sql']);
        }
    }
}
