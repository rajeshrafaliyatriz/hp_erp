<?php

namespace App\Console\Commands;

use App\Domain\Signals\Market\TargetCandidates;
use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY: proposes organisations to track for one product and customer model, from signals already stored. SELECT statements only;
 * it prints to stdout and writes nothing (no file, no table). Loading a proposal into g2g_target_accounts is a separate, human decision.
 *
 *   php artisan signals:target-candidates --tenant=6 --product=g2g --model=b2g [--format=table|csv|json] [--limit=50]
 */
class SignalsTargetCandidates extends Command
{
    protected $signature = 'signals:target-candidates {--tenant= : organisation id (required)} {--product= : product key (required)} {--model= : b2b, b2c or b2g (required)} {--format=table : table, csv or json} {--limit=50 : most candidates to print} {--all-regions : do not leave out organisations that name another country (India first is the default)}';

    protected $description = 'Read-only: proposed target organisations for review, built from stored signals (never loaded automatically)';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $product = trim((string) $this->option('product'));
        $model = strtolower(trim((string) $this->option('model')));
        $format = strtolower((string) $this->option('format'));

        if ($tenant <= 0 || $product === '' || $model === '') {
            $this->error('--tenant, --product and --model are all required.');

            return self::FAILURE;
        }
        if (! in_array($model, ['b2b', 'b2c', 'b2g'], true)) {
            $this->error('--model must be b2b, b2c or b2g.');

            return self::FAILURE;
        }
        if (! in_array($format, ['table', 'csv', 'json'], true)) {
            $this->error('--format must be table, csv or json.');

            return self::FAILURE;
        }
        if (strtolower($product) === 'scholarclone') {
            $this->error('scholarclone already has its own school list (target accounts); candidates are proposed for the other products only.');

            return self::FAILURE;
        }
        foreach (['g2g_company_opportunities', 'g2g_companies', 'g2g_target_accounts'] as $t) {
            if (! Schema::hasTable($t)) {
                $this->error("The table {$t} does not exist in this environment.");

                return self::FAILURE;
            }
        }
        foreach (['product_key', 'customer_model'] as $col) {
            if (! ColumnExists::has('g2g_company_opportunities', $col)) {
                $this->error("g2g_company_opportunities has no {$col} column in this environment.");

                return self::FAILURE;
            }
        }

        $optional = fn (string $table, string $alias, string $col, string $as) => ColumnExists::has($table, $col) ? "{$alias}.{$col} AS {$as}" : "NULL AS {$as}";
        $sql = 'SELECT c.name AS company, ' . $optional('g2g_companies', 'c', 'website', 'website') . ', ' . $optional('g2g_companies', 'c', 'domain', 'domain') . ', '
            . $optional('g2g_company_opportunities', 'o', 'source_url', 'source_url') . ', ' . $optional('g2g_company_opportunities', 'o', 'signal_kind', 'signal_kind') . ', '
            . $optional('g2g_company_opportunities', 'o', 'claim_level', 'claim_level') . ', ' . $optional('g2g_company_opportunities', 'o', 'fetch_level', 'fetch_level') . ', '
            . $optional('g2g_company_opportunities', 'o', 'created_at', 'created_at') . ', ' . $optional('g2g_company_opportunities', 'o', 'title', 'title') . ', '
            . $optional('g2g_company_opportunities', 'o', 'observed_event', 'observed_event') . ', ' . $optional('g2g_companies', 'c', 'location', 'location')
            . ' FROM g2g_company_opportunities o JOIN g2g_companies c ON c.id = o.company_id'
            . ' WHERE o.sub_institute_id = ? AND o.product_key = ? AND o.customer_model = ? AND o.is_sample = 0';
        $signals = array_map(fn ($r) => (array) $r, DB::select($sql, [$tenant, $product, $model]));
        $existing = DB::table('g2g_target_accounts')->where('sub_institute_id', $tenant)->pluck('name')->map(fn ($n) => (string) $n)->all();

        // India first (signals.region.default); --all-regions keeps organisations that name another country.
        $region = $this->option('all-regions') || strtolower(trim((string) config('signals.region.default', 'India'))) !== 'india' ? null : 'india';
        $rows = array_slice(TargetCandidates::propose($signals, $existing, 1, $region), 0, max(1, (int) $this->option('limit')));

        if ($format === 'json') {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($format === 'csv') {
            $this->line('name,status,kind,signal_count,best_evidence,latest_at,websites,source_hosts,reason');
            foreach ($rows as $r) {
                $this->line(implode(',', array_map(fn ($v) => $this->csv((string) $v), [$r['name'], $r['status'], $r['kind'], $r['signal_count'], $r['best_evidence'], $r['latest_at'], implode(' ', $r['websites']), implode(' ', $r['source_hosts']), $r['reason']])));
            }
        } else {
            $this->table(['Name', 'Kind', 'Signals', 'Evidence', 'Latest', 'Websites', 'Source hosts', 'Status'],
                array_map(fn ($r) => [$r['name'], $r['kind'], $r['signal_count'], $r['best_evidence'], $r['latest_at'] ?? '', implode(' ', $r['websites']), implode(' ', $r['source_hosts']), $r['status']], $rows));
        }
        $this->line(count($rows) . ' candidate(s) proposed for review. Nothing was saved.');

        return self::SUCCESS;
    }

    private function csv(string $v): string
    {
        // A leading = + - @ would be run as a formula by a spreadsheet.
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
            $v = "'" . $v;
        }

        return '"' . str_replace('"', '""', $v) . '"';
    }
}
