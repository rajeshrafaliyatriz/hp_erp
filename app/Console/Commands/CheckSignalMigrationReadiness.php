<?php

namespace App\Console\Commands;

use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ ONLY pre-flight for the two Signals scope migrations (2026_12_07_090000 and 2026_12_07_100000). It runs SELECT / SHOW statements against the
 * database this application is currently connected to, and changes nothing. It prints which database that is first, so the person running it
 * can see whether it is the one they meant.
 *
 *   php artisan signals:migration-check
 *
 * It reports: the connection, which of the tables and columns the migrations need exist, which of their changes are already applied, and every
 * group of rows that would stop a unique index being added. It never deletes or merges anything.
 */
class CheckSignalMigrationReadiness extends Command
{
    protected $signature = 'signals:migration-check {--limit=20 : how many duplicate groups to list per table}';

    protected $description = 'Read-only: show which database this is, what the Signals scope migrations still need, and any duplicate rows that would block their unique indexes';

    private const NEEDS = [
        'g2g_company_opportunities' => ['id', 'sub_institute_id', 'fingerprint', 'research_run_id'],
        'g2g_research_runs' => ['id', 'sub_institute_id', 'product_key', 'customer_model', 'offer_id', 'region_scope', 'scope_key', 'icp_profile_id'],
        'g2g_opportunity_matches' => ['id', 'sub_institute_id', 'signal_type', 'signal_id', 'offer_id'],
    ];

    private const ADDS = [
        'g2g_company_opportunities' => ['product_key', 'offer_id', 'customer_model', 'icp_profile_id', 'icp_version', 'region_scope'],
        'g2g_research_runs' => ['icp_version'],
    ];

    public function handle(): int
    {
        $conn = (array) config('database.connections.' . config('database.default'));
        $this->line('Connected to: host=' . ($conn['host'] ?? '-') . ' port=' . ($conn['port'] ?? '-') . ' database=' . ($conn['database'] ?? '-') . '  (read-only check; nothing is changed)');

        $blocked = false;
        $this->newLine();
        $this->info('1. What the migrations need (must exist before they run)');
        foreach (self::NEEDS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->error("   MISSING table {$table}");
                $blocked = true;

                continue;
            }
            $missing = array_values(array_filter($columns, fn ($c) => ! ColumnExists::has($table, $c)));
            if ($missing === []) {
                $this->line("   ok      {$table}");
            } else {
                $this->error("   MISSING {$table}: " . implode(', ', $missing) . ' (run the earlier Signals migrations first)');
                $blocked = true;
            }
        }

        $this->newLine();
        $this->info('2. What they add (already applied = nothing left to do for that part)');
        foreach (self::ADDS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $c) {
                $this->line('   ' . (ColumnExists::has($table, $c) ? 'present ' : 'to add  ') . "{$table}.{$c}");
            }
        }
        foreach (['g2g_company_opportunities' => 'g2g_opps_tenant_fingerprint_uq', 'g2g_opportunity_matches' => 'g2g_opp_matches_signal_offer_uq'] as $table => $index) {
            if (Schema::hasTable($table)) {
                $this->line('   ' . ($this->hasIndex($table, $index) ? 'present ' : 'to add  ') . "unique index {$index} on {$table}");
            }
        }

        $this->newLine();
        $this->info('3. Duplicate rows that would stop a unique index (the migration skips that index and deletes nothing)');
        $limit = max(1, (int) $this->option('limit'));
        $groups = [
            'g2g_company_opportunities' => ['sub_institute_id', 'fingerprint'],
            'g2g_opportunity_matches' => ['sub_institute_id', 'signal_type', 'signal_id', 'offer_id'],
        ];
        $duplicates = 0;
        foreach ($groups as $table => $columns) {
            if (! Schema::hasTable($table) || array_diff($columns, ColumnExists::all($table)) !== []) {
                continue;
            }
            $rows = DB::table($table)->select($columns)->selectRaw('COUNT(*) AS copies')->groupBy($columns)->havingRaw('COUNT(*) > 1')->orderByDesc('copies')->limit($limit)->get();
            if ($rows->isEmpty()) {
                $this->line("   none    {$table} (" . implode(', ', $columns) . ')');

                continue;
            }
            $duplicates += $rows->count();
            $this->error("   DUPLICATES in {$table} (" . implode(', ', $columns) . "), first {$rows->count()} group(s):");
            foreach ($rows as $r) {
                $this->line('     ' . json_encode((array) $r));
            }
        }

        $this->newLine();
        $this->info('4. For information only (no index is added for it): leads linked to more than one copy of the same opportunity');
        if (Schema::hasTable('crm_leads') && ColumnExists::has('crm_leads', 'source_company_opportunity_id')) {
            $leadDupes = DB::table('crm_leads')->whereNotNull('source_company_opportunity_id')->select('sub_institute_id', 'source_company_opportunity_id')
                ->selectRaw('COUNT(*) AS leads')->groupBy('sub_institute_id', 'source_company_opportunity_id')->havingRaw('COUNT(*) > 1')->limit($limit)->get();
            $this->line($leadDupes->isEmpty() ? '   none    crm_leads' : '   ' . $leadDupes->count() . ' opportunity(ies) already have more than one lead: ' . $leadDupes->map(fn ($r) => json_encode((array) $r))->implode(' '));
        } else {
            $this->line('   crm_leads.source_company_opportunity_id does not exist here');
        }

        $this->newLine();
        if ($blocked) {
            $this->error('NOT READY: run the earlier Signals migrations that create the missing tables / columns first.');

            return self::FAILURE;
        }
        $this->line($duplicates > 0
            ? 'READY with a caveat: 2026_12_07_090000 can run. 2026_12_07_100000 will SKIP the unique index(es) above until those duplicates are resolved by hand.'
            : 'READY: both migrations can run; no duplicates stand in the way of the unique indexes.');

        return self::SUCCESS;
    }

    private function hasIndex(string $table, string $name): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::select('SHOW INDEX FROM `' . str_replace('`', '', $table) . '` WHERE Key_name = ?', [$name]) !== [];
        }

        return collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? null) === $name);
    }
}
