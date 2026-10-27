<?php

namespace App\Console\Commands;

use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Run\YieldReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY daily-yield report for one organisation: SELECTs only (no insert, update, delete, no migration, no research, no provider call).
 * Run it only against an environment you have approved for measurement; it prints what the stored runs and signals say, and nothing else.
 *
 *   php artisan signals:yield-report --tenant=6 --from=2026-10-20 --to=2026-10-27 [--product=scholarclone] [--target=10] [--json]
 */
class SignalsYieldReport extends Command
{
    protected $signature = 'signals:yield-report {--tenant= : organisation id (required)} {--from= : first day YYYY-MM-DD} {--to= : last day YYYY-MM-DD} {--product= : one product key} {--target=10 : unique new qualified signals per day} {--json : print JSON}';

    protected $description = 'Read-only: per day and product, unique new qualified signals against the target, with the recorded reasons for a shortfall';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->option('from')) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->option('to'))) {
            $this->error('--tenant, --from and --to (YYYY-MM-DD) are required.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('g2g_research_runs') || ! Schema::hasTable('g2g_company_opportunities')) {
            $this->error('The signals tables do not exist in this environment.');

            return self::FAILURE;
        }
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $tz = (string) config('signals.timezone', 'Asia/Kolkata');
        $hasStats = \App\Support\ColumnExists::has('g2g_research_runs', 'stage_stats');
        $hasKey = \App\Support\ColumnExists::has('g2g_research_runs', 'product_key');

        $runs = DB::table('g2g_research_runs')->where('sub_institute_id', $tenant)->whereBetween('report_date', [$from, $to])
            ->get(array_filter(['id', 'report_date', 'status', $hasKey ? 'product_key' : null, $hasStats ? 'stage_stats' : null]))
            ->map(fn ($r) => ['id' => $r->id, 'report_date' => Carbon::parse($r->report_date)->toDateString(), 'status' => $r->status, 'product_key' => $r->product_key ?? 'scholarclone', 'stage_stats' => $r->stage_stats ?? null])->all();

        $fitToKey = [];
        foreach (ProductCatalog::definitionsFor($tenant) as $key => $d) {
            $fitToKey[$d['business_fit']] = $key;
        }
        $signals = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('is_sample', false)
            ->whereBetween('first_discovered_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])
            ->get(['id', 'business_fit', 'first_discovered_at', 'claim_level', 'assessment'])
            ->map(function ($o) use ($fitToKey, $tz) {
                $a = is_string($o->assessment) ? (json_decode($o->assessment, true) ?: []) : [];

                return ['id' => $o->id, 'product_key' => $fitToKey[(string) $o->business_fit] ?? 'unassigned', 'claim_level' => $o->claim_level,
                    'first_day' => Carbon::parse($o->first_discovered_at)->setTimezone($tz)->toDateString(), 'evidence_class' => $a['evidence_class'] ?? null];
            })->all();

        if ($this->option('product')) {
            $p = (string) $this->option('product');
            $runs = array_values(array_filter($runs, fn ($r) => $r['product_key'] === $p));
            $signals = array_values(array_filter($signals, fn ($s) => $s['product_key'] === $p));
        }

        $report = YieldReport::build($runs, $signals, max(1, (int) $this->option('target')));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        foreach ($report['days'] as $d) {
            $this->line(sprintf('%s %-16s unique new %2d / %d %s | runs %d (ok %d, partial %d, failed %d) | re-observed %d%s', $d['date'], $d['product'], $d['unique_new'], $report['target'],
                $d['target_met'] ? 'MET' : 'not met', $d['runs']['total'], $d['runs']['success'], $d['runs']['partial'], $d['runs']['failed'], $d['reobserved'], $d['shortfall'] ? ' | ' . $d['shortfall'] : ''));
        }
        foreach ($report['notes'] as $n) {
            $this->line('Note: ' . $n);
        }

        return self::SUCCESS;
    }
}
