<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A logical backup (CREATE TABLE + INSERTs, one file per table) of every table Signals reads or writes, plus the
 * `migrations` table. Read-only on the database. For use right before a schema change or an import, on a server
 * where mysqldump is not installed.
 *
 *   php artisan signals:backup-tables
 *   php artisan signals:backup-tables --dir=/safe/place/signals-backup
 *
 * Every table's row count is checked against what was written; the command fails loudly if they differ. Restore a table
 * by running its .sql file (it drops and recreates that table only).
 */
class BackupSignalsTables extends Command
{
    protected $signature = 'signals:backup-tables {--dir= : folder to write to (default storage/app/backups/signals-<time>)}
        {--tables= : comma-separated tables (default: every Signals table that exists)}';

    protected $description = 'Back up every Signals table (and the migrations table) to .sql files. Read-only.';

    public const TABLES = [
        'g2g_product_profiles', 'g2g_research_runs', 'g2g_research_sources', 'g2g_companies', 'g2g_company_aliases',
        'g2g_company_opportunities', 'g2g_opportunity_events', 'g2g_opportunity_matches', 'g2g_signal_scan_log',
        'g2g_signal_import_rejections', 'g2g_product_offers', 'g2g_offer_readiness_log', 'g2g_partners',
        'g2g_ingestion_sources', 'g2g_ingestion_analyses', 'g2g_ingestion_findings', 'g2g_signals', 'g2g_signal_runs',
        'g2g_target_accounts', 'g2g_icp_profiles', 'g2g_icp_profile_versions', 'migrations',
    ];

    public function handle(): int
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('This backup is for MySQL / MariaDB.');

            return self::FAILURE;
        }
        $dir = (string) ($this->option('dir') ?: storage_path('app/backups/signals-' . date('Ymd-His')));
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }
        $wanted = $this->option('tables') ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tables'))))) : self::TABLES;
        $pdo = DB::connection()->getPdo();
        $failed = 0;
        $total = 0;

        foreach ($wanted as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! Schema::hasTable($table)) {
                $this->line(sprintf('  %-32s (not in this database, skipped)', $table));

                continue;
            }
            $file = $dir . DIRECTORY_SEPARATOR . $table . '.sql';
            $out = fopen($file, 'w');
            fwrite($out, "-- backup of `{$table}` at " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($out, DB::selectOne("SHOW CREATE TABLE `{$table}`")->{'Create Table'} . ";\n");

            $written = 0;
            $batch = [];
            $cols = null;
            foreach (DB::table($table)->cursor() as $row) {
                $row = (array) $row;
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                $written++;
                if (count($batch) >= 100) {
                    fwrite($out, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                fwrite($out, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($out);

            $inDb = DB::table($table)->count();
            $ok = $inDb === $written;
            $failed += $ok ? 0 : 1;
            $total += $written;
            $this->line(sprintf('  %-32s rows in database %-6d written %-6d %-5s %s', $table, $inDb, $written, $ok ? 'OK' : 'DIFFERS', number_format(filesize($file)) . ' bytes'));
        }

        $this->line('');
        $this->line("Backup folder: {$dir}");
        if ($failed > 0) {
            $this->error("{$failed} table(s) did not match their row count. Do NOT rely on this backup.");

            return self::FAILURE;
        }
        $this->info("All tables match. {$total} rows backed up. Restore one table by running its .sql file.");

        return self::SUCCESS;
    }
}
