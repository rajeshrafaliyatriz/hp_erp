<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one-off data command of the India tax list (S16, 25 Nov 2026; contract Docs/agentic-crm/slices/S16-PRICING-CONTRACT.md section 4, DB-RULES item 8).
 *
 *   php artisan crm:seed-gst-rates                    DRY RUN (the default): says what it would add, writes nothing
 *   php artisan crm:seed-gst-rates --tenant=6         the same for one organisation
 *   php artisan crm:seed-gst-rates --apply            adds the rates, ONE transaction per organisation
 *
 * For every organisation that works in rupees (its setting `org.currency` is INR, which is also what an organisation that never set it has, or one of its products is priced in INR),
 * the five rates GST 0%, GST 5%, GST 12%, GST 18% and GST 28% are added, each tied to INR, unless the organisation already has a rate of that name and percentage. It never edits or
 * removes a rate that is there (an old "GST 18%" with no currency stays as it is and still fits any currency), and a second run adds nothing.
 *
 * Which organisations are looked at: those that use the catalogue at all (a catalogue row, or a tax rate) or have saved a currency, and the one named by --tenant (whatever it has).
 * Without --connection the command runs only on the dev host (or the sqlite test database); any other host must be named, as product-master:isolate-tenants does.
 *
 * Exit code: 0 done or nothing to do, 1 an organisation failed, 2 the command could not run (bad option, refused host, the column is missing).
 */
class CrmSeedGstRatesCommand extends Command
{
    /** name => percentage: the slabs of the Indian GST on goods and services. */
    public const RATES = ['GST 0%' => 0, 'GST 5%' => 5, 'GST 12%' => 12, 'GST 18%' => 18, 'GST 28%' => 28];

    public const CURRENCY = 'INR';

    protected $signature = 'crm:seed-gst-rates
        {--tenant= : One organisation only (default: every organisation that works in rupees)}
        {--apply : Write the rates (default: show what would be added)}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}';

    protected $description = 'Dry run by default: add the GST 0, 5, 12, 18 and 28 percent rates (tied to INR) to every organisation that works in rupees. --apply writes, one transaction per organisation; it never edits a rate that exists.';

    public function handle(): int
    {
        $explicit = $this->option('connection');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;
        $refusal = ProductMasterIsolateTenantsCommand::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }
        $only = $this->option('tenant');
        if ($only !== null && $only !== '' && (! ctype_digit((string) $only) || (int) $only <= 0)) {
            $this->error('--tenant must be a whole number above 0 (an organisation id).');

            return self::INVALID;
        }
        $only = $only === null || $only === '' ? null : (int) $only;
        $apply = (bool) $this->option('apply');
        $db = DB::connection($explicit);
        $schema = Schema::connection($explicit);

        if (! $schema->hasTable('crm_tax_rates') || ! $schema->hasColumn('crm_tax_rates', 'currency')) {
            $this->error('crm_tax_rates has no `currency` column on this connection: run the S16 migration (2026_12_26_090000_add_currency_to_crm_tax_rates) first. Nothing was done.');

            return self::INVALID;
        }

        $this->line('crm:seed-gst-rates - ' . ($apply ? 'APPLY' : 'DRY RUN (nothing is written)') . ' on connection ' . ($explicit ?? config('database.default')));

        $plan = $this->plan($db, $schema, $only);
        $added = 0;
        $failed = 0;
        foreach ($plan as $tenant => $row) {
            if (! $row['inr']) {
                $this->line("  organisation {$tenant}: skipped, it works in {$row['currency']} and has no product priced in INR");

                continue;
            }
            if ($row['missing'] === []) {
                $this->line("  organisation {$tenant}: nothing to do, it has all five GST rates");

                continue;
            }
            $names = implode(', ', array_keys($row['missing']));
            if (! $apply) {
                $this->line("  organisation {$tenant}: would add " . count($row['missing']) . " rate(s): {$names}");
                $added += count($row['missing']);

                continue;
            }
            try {
                $n = $this->insert($db, $tenant, $row['missing']);
                $added += $n;
                $this->line("  DONE   organisation {$tenant}: added {$n} rate(s): {$names}");
            } catch (\Throwable $e) {
                $failed++;
                $this->line("  FAILED organisation {$tenant}: {$e->getMessage()} (its transaction was taken back; nothing of it was written)");
            }
        }

        if ($plan === []) {
            $this->line('Nothing to do: no organisation to look at.');
        }
        $this->line($apply ? "{$added} rate(s) added, {$failed} organisation(s) failed. Running it again adds nothing." : "{$added} rate(s) would be added. Nothing was written. Add --apply to write them.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One answer per organisation: whether it works in rupees, and the rates (name => percentage) it lacks. Read in four statements, whatever the number of organisations.
     *
     * @return array<int, array{inr: bool, currency: string, missing: array<string, int>}>
     */
    private function plan($db, $schema, ?int $only): array
    {
        $explicit = $schema->hasTable('tenant_setting')
            ? $db->table('tenant_setting')->where('setting_key', 'org.currency')->pluck('setting_value', 'sub_institute_id')->all()
            : [];
        $explicit = array_map(fn ($v) => strtoupper(trim((string) $v)), $explicit);

        $withProducts = $schema->hasTable('crm_products')
            ? $db->table('crm_products')->where('sub_institute_id', '>', 0)->distinct()->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all()
            : [];
        $inrProducts = $schema->hasTable('crm_products')
            ? $db->table('crm_products')->where('sub_institute_id', '>', 0)->whereRaw('UPPER(TRIM(currency)) = ?', [self::CURRENCY])->distinct()->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all()
            : [];
        $rates = $db->table('crm_tax_rates')->where('sub_institute_id', '>', 0)->get(['sub_institute_id', 'name', 'percentage']);

        $tenants = array_values(array_unique(array_merge($withProducts, $rates->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all(), array_map('intval', array_keys($explicit)))));
        if ($only !== null) {
            $tenants = [$only];
        }
        sort($tenants);

        $have = [];
        foreach ($rates as $r) {
            $have[(int) $r->sub_institute_id][] = [mb_strtolower(trim((string) $r->name)), (int) round(((float) $r->percentage) * 1000)];
        }

        $out = [];
        foreach ($tenants as $tenant) {
            $currency = $explicit[(string) $tenant] ?? self::CURRENCY;   // an organisation that never set it works in INR (TenantSettings::DEFAULTS)
            $inr = $currency === self::CURRENCY || in_array($tenant, $inrProducts, true);
            $missing = [];
            foreach (self::RATES as $name => $percentage) {
                if (! in_array([mb_strtolower($name), $percentage * 1000], $have[$tenant] ?? [], true)) {
                    $missing[$name] = $percentage;
                }
            }
            $out[$tenant] = ['inr' => $inr, 'currency' => $currency === '' ? self::CURRENCY : $currency, 'missing' => $missing];
        }

        return $out;
    }

    /** @param array<string, int> $missing name => percentage; the existence check is made again inside the transaction, so two runs at once add each rate once */
    private function insert($db, int $tenant, array $missing): int
    {
        return (int) $db->transaction(function () use ($db, $tenant, $missing): int {
            $now = now();
            $n = 0;
            foreach ($missing as $name => $percentage) {
                $exists = $db->table('crm_tax_rates')->where('sub_institute_id', $tenant)->where('name', $name)->where('percentage', $percentage)->exists();
                if ($exists) {
                    continue;
                }
                $db->table('crm_tax_rates')->insert([
                    'name' => $name, 'percentage' => $percentage, 'currency' => self::CURRENCY, 'is_default' => false, 'is_active' => true,
                    'created_by' => null, 'sub_institute_id' => $tenant, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $n++;
            }

            return $n;
        });
    }
}
