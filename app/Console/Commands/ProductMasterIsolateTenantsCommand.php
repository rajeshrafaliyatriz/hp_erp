<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductIsolation;
use App\Domain\ProductMaster\ProductIsolator;
use App\Domain\ProductMaster\ProductMasterInvalid;
use Illuminate\Console\Command;

/**
 * The one-off data command of the product isolation (S15 follow-up, 24 Nov 2026; contract Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md D-1 to D-3,
 * DB-RULES item 8): every Product, Sub-product, item and About belongs to ONE organisation, and the rows that used to be shared are given to the organisation they belong to.
 *
 *   php artisan product-master:isolate-tenants --owner=6                       DRY RUN (the default): prints what it would do; only SELECT statements are sent, and the
 *                                                                              server is asked to refuse any write on the session as well
 *   php artisan product-master:isolate-tenants --owner=6 --apply               saves the before-image of every row it will touch (storage/app/product-isolation/), then
 *                                                                              runs ONE transaction per organisation; a second run says "nothing to do"
 *   php artisan product-master:isolate-tenants --rollback=<before-image.json>  puts the changed columns back, removes the Products the run made, brings back a shared
 *                                                                              row it deleted; refuses (and writes nothing) when a row changed since
 *
 * --owner is the organisation that takes the shared rows; it must be one of config product_isolation.builtin_owner_tenants (env G2G_BUILTIN_PRODUCTS_TENANTS), and the first of
 * the list when omitted. Without --connection the command runs only on the dev host (or the sqlite test database); any other host must be named, and the command then runs on
 * that connection alone. It never touches crm_products beyond the registered `product_master_id` pointer: Sell is already per organisation.
 *
 * Exit code: 0 done or nothing to do, 1 an organisation failed, something could not be placed (apply) or an undo was refused, 2 the command could not run (bad option, refused
 * host, missing file).
 */
class ProductMasterIsolateTenantsCommand extends Command
{
    protected $signature = 'product-master:isolate-tenants
        {--owner= : The organisation that takes the shared rows (one of G2G_BUILTIN_PRODUCTS_TENANTS; the first of the list when omitted)}
        {--apply : Write the changes (default: show what would change)}
        {--rollback= : A before-image file written by an --apply run: put its changes back}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}
        {--database= : The same as --connection (the other product-master commands call it that)}
        {--report= : Where to save the printed summary (default: storage/app/product-isolation/); never overwrites a file}';

    protected $description = 'Dry run by default: give every shared product row to the organisation it belongs to. --apply writes (before-image first, one transaction per organisation); --rollback undoes a run.';

    /** @var list<string> every line printed, for the saved summary */
    private array $log = [];

    public function handle(): int
    {
        $explicit = $this->option('connection') ?: $this->option('database');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;

        $refusal = self::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        $apply = (bool) $this->option('apply');
        $rollback = $this->path('rollback');
        if ($apply && $rollback !== null) {
            return $this->refuse('--apply and --rollback are two different runs: do one, check, then the other.');
        }
        if ($rollback !== null && $this->option('owner') !== null && $this->option('owner') !== '') {
            return $this->refuse('--rollback restores a before-image; it takes no --owner (the file knows it).');
        }

        $owner = null;
        if ($rollback === null) {
            $owner = $this->owner();
            if (is_string($owner)) {
                return $this->refuse($owner);
            }
        }

        $reportPath = $this->path('report');
        if ($reportPath !== null) {
            if (file_exists($reportPath)) {
                return $this->refuse("Refusing to overwrite {$reportPath}: delete the file first if you mean to replace it.");
            }
            $dir = dirname($reportPath);
            if ((! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) || ! is_writable($dir)) {
                return $this->refuse("The folder {$dir} cannot be written, so the summary could not be saved there. Nothing was done.");
            }
        }

        try {
            if ($rollback !== null) {
                return $this->undo($explicit, $rollback, $reportPath);
            }

            return $this->isolate($explicit, (int) $owner, $apply, $reportPath);
        } catch (\Throwable $e) {
            return $this->refuse('The command stopped: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ dry run and apply

    private function isolate(?string $connection, int $owner, bool $apply, ?string $reportPath): int
    {
        $isolator = new ProductIsolator($connection);
        $integrity = new ProductIntegrity($connection);
        // A dry run asks the server to refuse every write on this session as well: the promise "nothing is written" is then enforced and not only made.
        $session = $apply ? 'not asked (this is an --apply run)' : $integrity->lockSessionReadOnly();
        $info = $integrity->connectionInfo();

        $this->say(sprintf('product-master:isolate-tenants - %s', $apply ? 'APPLY' : 'DRY RUN (nothing is written to any table)'));
        $this->say(sprintf('Connection %s (%s), host %s, database %s, session read-only: %s', $info['name'], $info['driver'], $info['host'] ?? '-', $info['database'] ?? '-', $session));
        $this->say('Owner of the built-in Products: organisation ' . $owner . ' (config product_isolation.builtin_owner_tenants: ' . implode(', ', ProductIsolation::builtinOwners()) . ')');

        $plan = $isolator->plan($owner);
        foreach ($isolator->describe($plan, $apply) as $line) {
            $this->say($line);
        }
        $this->say('');

        // The census: the rows of every table the run can touch, counted and checksummed (SELECT statements only), so the apply and the rollback can be proven afterwards.
        $touched = $isolator->beforeImage($plan, [])['tables'];
        $census = $isolator->census($touched);
        foreach ($isolator->describeCensus($census, 'CENSUS BEFORE (rows, md5 of every column of every row in id order, rows by organisation; "touches" are the rows this plan changes)') as $line) {
            $this->say($line);
        }
        $this->say('');
        if ($plan['work'] > 0) {
            foreach ($isolator->describeChanges($plan, $census) as $line) {
                $this->say($line);
            }
            $this->say('');
            foreach ([
                'CHECKSUM PLAN',
                '  after --apply : the rows the plan does not touch (and does not make) keep their md5 in every table: the apply prints the census again and compares ("untouched" lines).',
                '  after --rollback: EVERY table has the row count and the md5 of the CENSUS BEFORE above, byte for byte; the rollback prints the census again and compares. Running this dry run again',
                '                  after the rollback prints the same CENSUS BEFORE text. (Not restored, because it is not a row: the auto-increment counter of g2g_product_master, so the next row made gets a higher id.)',
            ] as $line) {
                $this->say($line);
            }
            $this->say('');
        }

        if ($plan['work'] === 0) {
            $this->say($plan['blocked'] === []
                ? 'Nothing to do: every product row already belongs to one organisation.'
                : 'Nothing can be done: ' . count($plan['blocked']) . ' row(s) could not be placed (listed above).');
            $this->saveSummary($reportPath, $apply ? 'apply' : 'dry-run');

            return $apply && $plan['blocked'] !== [] ? self::FAILURE : self::SUCCESS;
        }
        if (! $apply) {
            $this->say(sprintf('%d step(s) would be made in %d organisation(s). Nothing was written. Add --apply to write them.', $plan['work'], count($plan['orgs'])));
            $this->saveSummary($reportPath, 'dry-run');

            return self::SUCCESS;
        }

        // the before-image first, then the first write
        $path = $this->folder() . '/isolation-' . $this->stamp() . '.json';
        $image = $isolator->beforeImage($plan, [
            'taken_at' => now()->toIso8601String(), 'connection' => $info['name'], 'host' => $info['host'], 'database' => $info['database'],
            'command' => 'product-master:isolate-tenants --apply', 'owner' => $owner, 'plan' => $isolator->describe($plan, true),
        ]);
        $image['census'] = $census;
        $rows = array_sum(array_map('count', $image['tables']));
        $this->say(sprintf('Before-image of the %d row(s) the run will touch saved to %s (undo with --rollback=%s)', $rows, $path, $path));

        $result = $isolator->apply($plan, $image, $path);
        foreach ($result['done'] as $tenant => $summary) {
            $this->say("  DONE   organisation {$tenant}: {$summary}");
        }
        foreach ($result['failed'] as $tenant => $why) {
            $this->say("  FAILED organisation {$tenant}: {$why} (its transaction was taken back; nothing of it was written)");
        }
        foreach ($result['kept'] as $why) {
            $this->say('  KEPT   ' . $why);
        }

        // the census again: the rows the run did not touch and did not make are the ones from before, in every table
        $made = [];
        foreach ($result['image']['created'] as $c) {
            $made[$c['table']][] = (int) $c['id'];
        }
        $after = $isolator->census($touched, $made);
        foreach ($isolator->describeCensus($after, 'CENSUS AFTER THE APPLY') as $line) {
            $this->say($line);
        }
        $drift = ProductIsolator::untouchedDifferences($census, $after);
        $this->say($drift === [] ? 'UNTOUCHED ROWS: identical in all ' . count($census) . ' tables (row count and md5 of every row the run did not touch).' : 'UNTOUCHED ROWS DIFFER (something else wrote to these tables while the run was going):');
        foreach ($drift as $line) {
            $this->say('  ' . $line);
        }

        $check = (new ProductIntegrity($connection))->check();
        $this->say(sprintf('Checker (every organisation): %d error(s), %d warning(s); not_isolated: %d.', $check['errors'], $check['warnings'], $check['counts']['not_isolated'] ?? 0));
        foreach (array_slice($check['findings'], 0, 10) as $f) {
            $this->say('  [' . $f['code'] . '] ' . $f['message']);
        }
        $ok = $result['failed'] === [] && $plan['blocked'] === [] && $result['kept'] === [] && $drift === [];
        $this->say($ok ? 'RESULT: OK. Running it again changes nothing.' : sprintf('RESULT: INCOMPLETE (%d organisation(s) failed, %d row(s) could not be placed, %d kept)', count($result['failed']), count($plan['blocked']), count($result['kept'])));
        $this->saveSummary($reportPath, 'apply');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------ the way back

    private function undo(?string $connection, string $path, ?string $reportPath): int
    {
        $image = ProductIsolator::read($path, $problem);
        if ($image === null) {
            return $this->refuse($problem);
        }
        $isolator = new ProductIsolator($connection);
        $integrity = new ProductIntegrity($connection);
        $info = $integrity->connectionInfo();
        $this->say('product-master:isolate-tenants - ROLLBACK of ' . $path);
        $this->say(sprintf('Connection %s (%s), host %s, database %s', $info['name'], $info['driver'], $info['host'] ?? '-', $info['database'] ?? '-'));

        try {
            $r = $isolator->rollback($image);
        } catch (ProductMasterInvalid $e) {
            $this->say('REFUSED: ' . $e->getMessage());
            foreach (array_slice($e->errors['rows'] ?? [], 0, 20) as $line) {
                $this->say('  ' . $line);
            }
            $this->say('Nothing was undone.');
            $this->saveSummary($reportPath, 'rollback');

            return self::FAILURE;
        }
        $this->say(sprintf('UNDONE: %d row(s) put back, %d Product(s) made by the run removed, %d shared row(s) brought back.', $r['restored'], $r['removed'], $r['reinserted']));
        $check = $integrity->check();
        $this->say(sprintf('Checker (every organisation): %d error(s), %d warning(s); not_isolated: %d (the shared rows are shared again).', $check['errors'], $check['warnings'], $check['counts']['not_isolated'] ?? 0));

        // byte for byte: the census now against the census the run saved before its first write
        $identical = true;
        if (is_array($image['census'] ?? null)) {
            $now = $isolator->census();
            foreach ($isolator->describeCensus($now, 'CENSUS AFTER THE ROLLBACK') as $line) {
                $this->say($line);
            }
            $diff = ProductIsolator::censusDifferences($image['census'], $now);
            $identical = $diff === [];
            $this->say($identical
                ? 'IDENTICAL: every one of the ' . count($now) . ' tables has the row count and the md5 it had before the run, byte for byte.'
                : 'DIFFERENT from before the run (if people worked in these tables since the run, that is expected; otherwise the rollback is incomplete):');
            foreach ($diff as $line) {
                $this->say('  ' . $line);
            }
        }
        $this->saveSummary($reportPath, 'rollback');

        return $identical ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Whether this run may use the connection it is about to use. Without --connection only the dev host (or a non-MySQL driver: the sqlite test database) is allowed, so
     * nobody writes to production because a machine's default connection points there; naming the connection is the deliberate act. The same rule as
     * ProductMasterCheckCommand::refusal, worded for a command that WRITES.
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
        if ($explicit !== null || ! in_array((string) ($config['driver'] ?? ''), ['mysql', 'mariadb'], true)) {
            return null;
        }
        $host = (string) ($config['host'] ?? '');

        return $host === ProductMasterCheckCommand::DEV_HOST ? null : "Refusing to run on host '{$host}': without --connection this command runs only on the dev host " . ProductMasterCheckCommand::DEV_HOST . '. Name the connection with --connection=<name> if you mean it.';
    }

    /** @return int|string the owner organisation, or the refusal */
    private function owner(): int|string
    {
        $given = $this->option('owner');
        if ($given === null || $given === '') {
            $default = ProductIsolation::defaultOwner();

            return $default ?? 'config product_isolation.builtin_owner_tenants is empty: name the owner with --owner=<organisation>, and put it in G2G_BUILTIN_PRODUCTS_TENANTS.';
        }
        if (! ctype_digit((string) $given) || (int) $given <= 0) {
            return '--owner must be a whole number above 0 (an organisation id).';
        }
        $owner = (int) $given;
        if (! ProductIsolation::isBuiltinOwner($owner)) {
            return "Organisation {$owner} is not in config product_isolation.builtin_owner_tenants (" . implode(', ', ProductIsolation::builtinOwners()) . '): it would be given the shared Products and then not see them. Put it in G2G_BUILTIN_PRODUCTS_TENANTS first.';
        }

        return $owner;
    }

    private function path(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function folder(): string
    {
        return rtrim((string) config('product_isolation.folder', storage_path('app/product-isolation')), '/\\');
    }

    private function stamp(): string
    {
        return now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2));
    }

    private function say(string $line): void
    {
        $this->log[] = $line;
        $this->line($line);
    }

    /** The summary of a run. Saving it never turns a run that is done into an error: it is said, not thrown. */
    private function saveSummary(?string $reportPath, string $kind): void
    {
        $path = $reportPath ?? $this->folder() . '/' . $kind . '-' . $this->stamp() . '.txt';
        try {
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException("The folder {$dir} cannot be created.");
            }
            if (@file_put_contents($path, implode("\n", $this->log) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException("Could not write {$path}.");
            }
            $this->line('Summary saved to ' . $path);
        } catch (\Throwable $e) {
            $this->warn('The summary could not be saved (' . $e->getMessage() . '). What was done is printed above.');
        }
    }

    private function refuse(?string $message): int
    {
        $this->error((string) $message);

        return self::INVALID;
    }
}
