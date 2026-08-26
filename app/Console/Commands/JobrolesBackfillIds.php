<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every row that names a job role the role's ID.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * Most of this system links to a job role by its NAME. That costs three things:
 * a name is not unique (90 role names in one live tenant belong to roles in
 * more than one department), renaming a role silently orphans every row that
 * named it, and a merge cannot re-point what it cannot identify. On live,
 * s_user_skill_jobrole.jobrole_id is NULL on all 84,380 rows - so an id-based
 * merge would move nothing at all.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────
 *
 * Match on (sub_institute_id, TRIM(LOWER(name))) against live roles:
 *
 *   exactly one match   -> write the id
 *   two or more         -> leave NULL, report as AMBIGUOUS
 *   none                -> leave NULL, report as ORPHANED
 *
 * The same rule ResolvesJobRoleId and JobRoleMergeService already use. It
 * refuses to guess: an ambiguous name resolved to its first match would look
 * authoritative and be wrong about half the time.
 *
 * A NULL left behind is not a failure. Those rows keep working by name exactly
 * as they do today; this command just makes them countable, and re-running it
 * shows the number falling as people fix them.
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────
 *
 * It never writes the `jobrole` name column, and never deletes anything. Around
 * twenty screens still read the name - one whereIn('jobrole', ...) in
 * CommandCenterService feeds thirteen Command Center metrics, and the skill
 * matrix builds its whole column list from GROUP BY jobrole. This is purely
 * additive, so nothing that works today stops working.
 *
 * DRY RUN BY DEFAULT, like departments:dedupe. Nothing is written without
 * --execute, and the dry run IS the report.
 *
 *   php artisan jobroles:backfill-ids
 *   php artisan jobroles:backfill-ids --execute
 *   php artisan jobroles:backfill-ids --database=live --execute
 *   php artisan jobroles:backfill-ids --tenant=6 --details
 */
class JobrolesBackfillIds extends Command
{
    protected $signature = 'jobroles:backfill-ids
        {--execute       : Actually write the ids. Without this nothing is changed.}
        {--database=     : Connection to run against (default: the app default).}
        {--tenant=       : Restrict to one sub_institute_id.}
        {--details       : List the ambiguous and orphaned names, not just counts.}';

    protected $description = 'Key name-referenced job role rows to s_user_jobrole.id, reporting whatever cannot be keyed.';

    /**
     * table => [name column, id column]
     *
     * s_mobility_transfers and talent_mobility_requests are absent ON PURPOSE:
     * they are history. Their role names record a move that happened, and
     * keying them to a role that has since been merged away would make the
     * audit trail claim something false.
     *
     * The global catalogue (s_jobrole, s_jobrole_task, s_jobrole_skills,
     * s_jobrole_knowledge) is absent for a harder reason: it has no
     * sub_institute_id at all. It is shared by every tenant, so there is no
     * tenant-owned role to key it to.
     */
    private const TABLES = [
        's_user_skill_jobrole'                    => ['jobrole', 'jobrole_id'],
        's_user_jobrole_task'                     => ['jobrole', 'jobrole_id'],
        's_competency_frameworks'                 => ['jobrole', 'jobrole_id'],
        's_competency_development_plans'          => ['jobrole', 'jobrole_id'],
        's_competency_career_path_steps'          => ['jobrole', 'jobrole_id'],
        's_competency_assessments'                => ['jobrole', 'jobrole_id'],
        's_competency_certifications'             => ['jobrole', 'jobrole_id'],
        's_competency_certification_requirements' => ['jobrole', 'jobrole_id'],
        's_competency_mapping_reviews'            => ['jobrole', 'jobrole_id'],
        's_performance_reviews'                   => ['jobrole', 'jobrole_id'],
        's_performance_appraisals'                => ['jobrole', 'jobrole_id'],
        's_mobility_succession_plans'             => ['critical_jobrole_name', 'critical_jobrole_id'],
    ];

    public function handle(): int
    {
        $db = $this->option('database')
            ? DB::connection($this->option('database'))
            : DB::connection();

        $execute = (bool) $this->option('execute');
        $tenant  = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $this->line('');
        $this->info($execute ? 'WRITING job role ids' : 'DRY RUN - nothing will be written');
        $this->line('  connection: ' . $db->getName() . ' (' . $db->getDatabaseName() . ')');
        if ($tenant) {
            $this->line('  tenant:     ' . $tenant);
        }
        $this->line('');

        $rows = [];
        $totals = ['rows' => 0, 'keyed' => 0, 'fixable' => 0, 'ambiguous' => 0, 'orphaned' => 0];
        $residue = [];

        foreach (self::TABLES as $table => [$nameColumn, $idColumn]) {
            if (!$this->hasColumn($db, $table, $nameColumn) || !$this->hasColumn($db, $table, $idColumn)) {
                // Absent on this database. Skipped, not failed - the two
                // schemas have drifted before, and a missing table is not a
                // reason to abandon the other eleven.
                $rows[] = [$table, '—', '—', '—', '—', '—', 'not on this database'];
                continue;
            }

            $stats = $this->measure($db, $table, $nameColumn, $idColumn, $tenant);

            if ($execute && $stats['fixable'] > 0) {
                $written = $this->write($db, $table, $nameColumn, $idColumn, $tenant);
                $stats['keyed']  += $written;
                $stats['fixable'] -= $written;
            }

            $rows[] = [
                $table,
                number_format($stats['rows']),
                number_format($stats['keyed']),
                $stats['fixable'] ? number_format($stats['fixable']) : '-',
                $stats['ambiguous'] ? number_format($stats['ambiguous']) : '-',
                $stats['orphaned'] ? number_format($stats['orphaned']) : '-',
                '',
            ];

            foreach (['rows', 'keyed', 'fixable', 'ambiguous', 'orphaned'] as $key) {
                $totals[$key] += $stats[$key];
            }

            if ($this->option('details') && ($stats['ambiguous'] || $stats['orphaned'])) {
                $residue[$table] = $this->residue($db, $table, $nameColumn, $idColumn, $tenant);
            }
        }

        $this->table(
            ['table', 'rows w/ a role', 'keyed', $execute ? 'still fixable' : 'can be keyed', 'ambiguous', 'orphaned', ''],
            $rows
        );

        $coverage = $totals['rows'] > 0 ? $totals['keyed'] / $totals['rows'] * 100 : 0;
        $this->line('');
        $this->line(sprintf('  keyed:     %s of %s rows (%.1f%%)',
            number_format($totals['keyed']), number_format($totals['rows']), $coverage));

        if (!$execute && $totals['fixable'] > 0) {
            $this->line(sprintf('  would key: %s more rows  ->  run again with --execute',
                number_format($totals['fixable'])));
        }

        if ($totals['ambiguous'] || $totals['orphaned']) {
            $this->line('');
            $this->warn(sprintf('  %s row(s) cannot be keyed by any rule:',
                number_format($totals['ambiguous'] + $totals['orphaned'])));
            $this->line(sprintf('     %s AMBIGUOUS - the name belongs to roles in more than one department',
                number_format($totals['ambiguous'])));
            $this->line(sprintf('     %s ORPHANED  - the name matches no live role (already a dead link today)',
                number_format($totals['orphaned'])));
            $this->line('  These keep working by name. Nothing is guessed.');
            if (!$this->option('details')) {
                $this->line('  Re-run with --details to see the actual names.');
            }
        }

        foreach ($residue as $table => $names) {
            $this->line('');
            $this->line('  ' . $table . ':');
            foreach ($names as $row) {
                $this->line(sprintf('     %-9s tenant %-4s %-46s %s row(s)%s',
                    $row->kind, $row->sub_institute_id, mb_strimwidth((string) $row->name, 0, 44, '…'),
                    $row->row_count, $row->kind === 'AMBIG' ? '  matches ' . $row->matches . ' roles' : ''));
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Count what is keyed, keyable, ambiguous and orphaned in one pass.
     *
     * A correlated subquery per row took minutes against live; this joins a
     * single GROUP BY of role names instead and runs in seconds.
     */
    private function measure($db, string $table, string $nameColumn, string $idColumn, ?int $tenant): array
    {
        /*
         * The denominator is rows that REFERENCE a role, not every row in the
         * table. s_performance_reviews has 235 rows of which 13 name a job
         * role; reporting "13 of 235 keyed" would read as a 94% failure when
         * the other 222 rows simply have nothing to key.
         */
        $base = fn () => $db->table($table)
            ->when($tenant, fn ($q) => $q->where('sub_institute_id', $tenant))
            ->where(fn ($q) => $q->whereNotNull($nameColumn)->where($nameColumn, '<>', '')
                ->orWhere(fn ($w) => $w->whereNotNull($idColumn)->where($idColumn, '>', 0)));

        $stats = [
            'rows'  => (clone $base())->count(),
            'keyed' => (clone $base())->whereNotNull($idColumn)->where($idColumn, '>', 0)->count(),
            'fixable' => 0, 'ambiguous' => 0, 'orphaned' => 0,
        ];

        $counts = $db->select(
            "SELECT COALESCE(g.n, 0) AS matches, COUNT(*) AS row_count
               FROM `$table` x
               LEFT JOIN (
                    SELECT sub_institute_id AS sid, TRIM(LOWER(jobrole)) AS nm, COUNT(*) AS n
                      FROM s_user_jobrole WHERE deleted_at IS NULL
                     GROUP BY sub_institute_id, TRIM(LOWER(jobrole))
               ) g ON g.sid = x.sub_institute_id AND g.nm = TRIM(LOWER(x.`$nameColumn`))
              WHERE x.`$nameColumn` IS NOT NULL AND x.`$nameColumn` <> ''
                AND (x.`$idColumn` IS NULL OR x.`$idColumn` = 0)
                " . ($tenant ? "AND x.sub_institute_id = " . (int) $tenant : '') . "
              GROUP BY COALESCE(g.n, 0)"
        );

        foreach ($counts as $row) {
            $matches = (int) $row->matches;
            if ($matches === 1)      { $stats['fixable']   += (int) $row->row_count; }
            elseif ($matches === 0)  { $stats['orphaned']  += (int) $row->row_count; }
            else                     { $stats['ambiguous'] += (int) $row->row_count; }
        }

        return $stats;
    }

    /**
     * Write the ids, IN BATCHES.
     *
     * One UPDATE ... JOIN covering all 79,081 skill rows is correct and it is
     * also how you take a production database down: it held row locks long
     * enough to hit `Lock wait timeout exceeded` on live and rolled the whole
     * thing back. A multi-table UPDATE cannot take a LIMIT, so the rows are
     * resolved first and then written by primary key, a few thousand at a time.
     *
     * Each statement is short, so ordinary traffic keeps running between
     * batches. Nothing is transactional across batches ON PURPOSE: a batch that
     * lands is progress kept, and re-running only ever picks up rows still
     * NULL.
     *
     * The GROUP BY / HAVING COUNT(*) = 1 in the resolve step is what enforces
     * "exactly one" - an ambiguous name produces more than one role row and is
     * excluded by the HAVING rather than being quietly reduced to its first
     * match.
     */
    private function write($db, string $table, string $nameColumn, string $idColumn, ?int $tenant): int
    {
        $batchSize = 2000;
        $written = 0;

        while (true) {
            $batch = $db->select(
                "SELECT x.id AS row_id, g.role_id
                   FROM `$table` x
                   JOIN (
                        SELECT sub_institute_id AS sid, TRIM(LOWER(jobrole)) AS nm, MIN(id) AS role_id
                          FROM s_user_jobrole
                         WHERE deleted_at IS NULL
                         GROUP BY sub_institute_id, TRIM(LOWER(jobrole))
                        HAVING COUNT(*) = 1
                   ) g ON g.sid = x.sub_institute_id AND g.nm = TRIM(LOWER(x.`$nameColumn`))
                  WHERE x.`$nameColumn` IS NOT NULL AND x.`$nameColumn` <> ''
                    AND (x.`$idColumn` IS NULL OR x.`$idColumn` = 0)
                    " . ($tenant ? "AND x.sub_institute_id = " . (int) $tenant : '') . "
                  LIMIT $batchSize"
            );

            if ($batch === []) {
                break;
            }

            // Grouped so each statement sets ONE value across many primary
            // keys - the shortest lock a bulk update can hold.
            $byRole = [];
            foreach ($batch as $row) {
                $byRole[(int) $row->role_id][] = (int) $row->row_id;
            }

            foreach ($byRole as $roleId => $ids) {
                $written += $db->table($table)->whereIn('id', $ids)->update([$idColumn => $roleId]);
            }
        }

        return $written;
    }

    /** The actual names behind the counts, so they can be acted on. */
    private function residue($db, string $table, string $nameColumn, string $idColumn, ?int $tenant): array
    {
        return $db->select(
            "SELECT CASE WHEN COALESCE(g.n, 0) = 0 THEN 'ORPHAN' ELSE 'AMBIG' END AS kind,
                    x.sub_institute_id, x.`$nameColumn` AS name,
                    COALESCE(g.n, 0) AS matches, COUNT(*) AS row_count
               FROM `$table` x
               LEFT JOIN (
                    SELECT sub_institute_id AS sid, TRIM(LOWER(jobrole)) AS nm, COUNT(*) AS n
                      FROM s_user_jobrole WHERE deleted_at IS NULL
                     GROUP BY sub_institute_id, TRIM(LOWER(jobrole))
               ) g ON g.sid = x.sub_institute_id AND g.nm = TRIM(LOWER(x.`$nameColumn`))
              WHERE x.`$nameColumn` IS NOT NULL AND x.`$nameColumn` <> ''
                AND (x.`$idColumn` IS NULL OR x.`$idColumn` = 0)
                AND COALESCE(g.n, 0) <> 1
                " . ($tenant ? "AND x.sub_institute_id = " . (int) $tenant : '') . "
              GROUP BY kind, x.sub_institute_id, x.`$nameColumn`, COALESCE(g.n, 0)
              ORDER BY row_count DESC
              LIMIT 25"
        );
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasColumn() throws. */
    private function hasColumn($db, string $table, string $column): bool
    {
        return $db->select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
}
