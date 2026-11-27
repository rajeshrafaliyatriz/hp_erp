<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductPointers;
use Illuminate\Console\Command;

/**
 * READ ONLY proof that the products are still wired together (S15 plan section 2.1; see ProductIntegrity for the invariants).
 *
 *   php artisan product-master:check                                   the checks, on the dev database, every organisation
 *   php artisan product-master:check --tenant=6                        one organisation
 *   php artisan product-master:check --snapshot=before.json            also write the census of the pointer tables (rows, sums, rows per product)
 *   php artisan product-master:check --compare=before.json             also compare the database now with that census; any difference exits 1
 *
 * Warnings W1 and W2 are about rows; W3 is about the schema: a column of crm_products, g2g_product_offers, g2g_product_master or g2g_signal_products that the host has
 * and no migration defines, or that a migration defines and the host lacks (named by table and column). W3 only reads SHOW COLUMNS and the migration files.
 *
 * Exit code: 0 clean (warnings are only printed), 1 an error invariant failed or a census differs, 2 the command could not run (bad option, refused host,
 * unreadable or existing file). It never writes to a table: only SELECT statements are issued, and on MySQL / MariaDB the session is made read-only first so the
 * server would refuse a write anyway. Without --database it reads only the dev host; any other host must be named with --database.
 */
class ProductMasterCheckCommand extends Command
{
    /** The dev database. Without --database the command reads this host (or the sqlite test database) and nothing else. */
    public const DEV_HOST = '202.47.117.220';

    /** Findings printed per code (the count is always exact). */
    private const PRINT_LIMIT = 25;

    protected $signature = 'product-master:check
        {--tenant= : One organisation (sub_institute_id; 0 = the shared rows). Every organisation when omitted}
        {--database= : Connection name. Without it only the dev connection is read; any other host must be named here}
        {--snapshot= : Also write a JSON census of the pointer tables (rows, sums, rows per product id) to this file; never overwrites a file}
        {--compare= : Also compare the database now with a census file written earlier; exits 1 when anything differs}';

    protected $description = 'READ ONLY. Prove that every pointer at a product resolves and the three product stores agree; write or compare a before/after census of the quote, order and invoice lines.';

    public function handle(): int
    {
        $explicit = $this->option('database');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;

        $tenant = null;
        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null && $tenantOption !== '') {
            if (! ctype_digit((string) $tenantOption)) {
                $this->error('--tenant must be a whole number (0 means the shared rows).');

                return self::INVALID;
            }
            $tenant = (int) $tenantOption;
        }

        $refusal = self::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }

        $snapshotPath = $this->pathOption('snapshot');
        if ($snapshotPath !== null) {
            if (file_exists($snapshotPath)) {
                $this->error("Refusing to overwrite {$snapshotPath}: a census is the baseline of a comparison. Delete the file first if you mean to replace it.");

                return self::INVALID;
            }
            if (! is_dir(dirname($snapshotPath)) || ! is_writable(dirname($snapshotPath))) {
                $this->error('The folder of ' . $snapshotPath . ' does not exist or cannot be written.');

                return self::INVALID;
            }
        }

        $before = null;
        $comparePath = $this->pathOption('compare');
        if ($comparePath !== null) {
            $before = self::readCensus($comparePath, $problem);
            if ($before === null) {
                $this->error($problem);

                return self::INVALID;
            }
            $fileTenant = $before['tenant'] ?? null;
            if ($tenant === null && $fileTenant !== null) {
                $tenant = (int) $fileTenant;
                $this->line("The census file was taken for organisation {$tenant}: comparing that organisation.");
            } elseif ($tenant !== (($fileTenant === null) ? null : (int) $fileTenant)) {
                $this->error('The census file covers ' . ($fileTenant === null ? 'every organisation' : 'organisation ' . (int) $fileTenant) . ' but --tenant asks for ' . ($tenant === null ? 'every organisation' : 'organisation ' . $tenant) . '. Compare like with like.');

                return self::INVALID;
            }
        }

        try {
            $integrity = new ProductIntegrity($explicit, $tenant);
            $session = $integrity->lockSessionReadOnly();
        } catch (\Throwable $e) {
            $this->error('Could not open the database connection: ' . $e->getMessage());

            return self::INVALID;
        }
        $info = $integrity->connectionInfo();
        $this->line('product-master:check (READ ONLY)');
        $this->line(sprintf(
            'Read: connection %s (%s), host %s, database %s, session read-only: %s',
            $info['name'], $info['driver'], $info['host'] ?? '-', $info['database'] ?? '-', $session,
        ));
        $this->line('Scope: ' . ($tenant === null ? 'every organisation' : 'organisation ' . $tenant));

        $failed = false;
        $skipped = [];

        // ---- the invariants
        // check() plus W3 (schema drift against the migration files): a warning, so it never changes the exit code.
        $report = $integrity->checkWithSchema();
        $skipped = array_merge($skipped, $report['skipped']);
        $this->line('');
        $this->line(sprintf(
            'Registry: %d pointers in %d tables (%d at crm_products.id, %d at g2g_product_master.id); %d examined on this host; %d master rows, %d live catalogue items, %d Portfolio offers.',
            count(ProductPointers::all()), count(ProductPointers::tables()), count(ProductPointers::toCrmProduct()), count(ProductPointers::toMaster()), $report['checked']['pointers'],
            $report['checked']['masterRows'], $report['checked']['catalogueItems'], $report['checked']['offers'],
        ));
        $this->line('');
        foreach (ProductIntegrity::CODES as $code => [$severity, $meaning]) {
            $this->line(sprintf('  %-4s %-8s %7d  %s', $code, $severity, $report['counts'][$code], $meaning));
        }
        $this->printFindings($report);

        // ---- the census
        $census = null;
        if ($snapshotPath !== null || $before !== null) {
            $census = $integrity->census();
            $skipped = array_merge($skipped, $census['skipped']);
        }

        if ($before !== null && $census !== null) {
            $differences = ProductIntegrity::differences($before, $census);
            $this->line('');
            if ($differences === []) {
                $this->info("Compared with {$comparePath}: no difference in any row count, sum or rows-per-product.");
            } else {
                $failed = true;
                $this->error('Compared with ' . $comparePath . ': ' . count($differences) . ' difference(s)');
                foreach ($differences as $line) {
                    $this->line('  ' . $line);
                }
            }
        }

        if ($snapshotPath !== null && $census !== null) {
            $file = ['format' => $census['format'], 'meta' => [
                'taken_at' => now()->toIso8601String(), 'connection' => $info['name'], 'host' => $info['host'], 'database' => $info['database'],
                'command' => 'product-master:check --snapshot', 'checks' => ['errors' => $report['errors'], 'warnings' => $report['warnings']],
            ]] + $census;
            $written = @file_put_contents($snapshotPath, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
            if ($written === false) {
                $this->error("Could not write {$snapshotPath}.");

                return self::INVALID;
            }
            $this->line('');
            $this->info(sprintf('Census written to %s: %d tables, %d rows in the line tables.', $snapshotPath, count($census['tables']), $this->lineRows($census)));
        }

        $skipped = array_values(array_unique($skipped));
        if ($skipped !== []) {
            $this->line('');
            $this->line('Skipped on this host (not an error: the schema there does not have it yet):');
            foreach ($skipped as $why) {
                $this->line('  - ' . $why);
            }
        }

        $this->line('');
        if ($report['errors'] > 0) {
            $failed = true;
        }
        if ($failed) {
            $this->error(sprintf('RESULT: FAILED (%d error(s), %d warning(s)%s)', $report['errors'], $report['warnings'], $before !== null ? ', see the comparison above' : ''));

            return self::FAILURE;
        }
        $this->info(sprintf('RESULT: OK (0 errors, %d warning(s))', $report['warnings']));

        return self::SUCCESS;
    }

    /**
     * Whether this run may read the host it is about to read. Without --database only the dev host (or a non-MySQL driver: the sqlite test database) is allowed,
     * so nobody reads production by accident because a machine's default connection points there; naming the connection is the deliberate act.
     *
     * @param  array<string, mixed>  $connections  config('database.connections')
     */
    public static function refusal(?string $explicit, string $default, array $connections): ?string
    {
        $name = $explicit ?? $default;
        $config = $connections[$name] ?? null;
        if (! is_array($config)) {
            return "There is no database connection named '{$name}'.";
        }
        if ($explicit !== null) {
            return null;
        }
        if (! in_array((string) ($config['driver'] ?? ''), ['mysql', 'mariadb'], true)) {
            return null;
        }
        $host = (string) ($config['host'] ?? '');
        if ($host === self::DEV_HOST) {
            return null;
        }

        return "Refusing to read host '{$host}': without --database this command reads only the dev host " . self::DEV_HOST . '. Name the connection with --database=<name> if you mean it (the command is read only).';
    }

    /**
     * A census file, or null with the reason in $problem.
     *
     * @return ?array<string, mixed>
     */
    public static function readCensus(string $path, ?string &$problem = null): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $problem = "The census file {$path} does not exist or cannot be read.";

            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $problem = "The census file {$path} is not valid JSON: " . $e->getMessage();

            return null;
        }
        if (! is_array($data) || ($data['format'] ?? null) !== ProductIntegrity::FORMAT || ! isset($data['tables']) || ! is_array($data['tables'])) {
            $problem = "The census file {$path} is not a census written by this command (format " . ProductIntegrity::FORMAT . ').';

            return null;
        }

        return $data;
    }

    private function pathOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array{counts: array<string, int>, findings: list<array<string, mixed>>} $report */
    private function printFindings(array $report): void
    {
        foreach (ProductIntegrity::CODES as $code => $_) {
            $mine = array_values(array_filter($report['findings'], fn (array $f): bool => $f['code'] === $code));
            if ($mine === []) {
                continue;
            }
            $this->line('');
            foreach (array_slice($mine, 0, self::PRINT_LIMIT) as $finding) {
                $this->line("  [{$code}] {$finding['message']}");
            }
            if ($report['counts'][$code] > self::PRINT_LIMIT) {
                $this->line('  [' . $code . '] ... and ' . ($report['counts'][$code] - self::PRINT_LIMIT) . ' more');
            }
        }
    }

    /** @param array{tables: array<string, array<string, mixed>>} $census */
    private function lineRows(array $census): int
    {
        $rows = 0;
        foreach (['crm_quote_line_items', 'crm_sales_order_line_items', 'crm_invoice_line_items'] as $table) {
            $rows += (int) ($census['tables'][$table]['rows'] ?? 0);
        }

        return $rows;
    }
}
