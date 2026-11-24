<?php

namespace App\Console\Commands;

use App\Domain\Signals\Market\MarketReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only market-signal report. Runs two fixed SELECTs (tenant passed as a bound integer) and writes nothing.
 */
class SignalsMarketReport extends Command
{
    protected $signature = 'signals:market-report {--tenant= : sub_institute_id (required)} {--format=table : table|json}';

    protected $description = 'Read-only market-signal metrics with denominators (excludes samples, notes, duplicates inflation)';

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        if ($tenant === null || $tenant === '' || ! ctype_digit((string) $tenant)) {
            $this->error('--tenant=<sub_institute_id> is required (a number). Refusing to report across tenants.');

            return self::FAILURE;
        }
        $format = (string) $this->option('format');
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('--format must be table or json.');

            return self::FAILURE;
        }
        $tenant = (int) $tenant;

        $db = DB::connection();
        $rows = array_map(fn ($r) => (array) $r, $db->select(
            'SELECT o.id, o.sub_institute_id, c.name AS company, o.source_url, o.import_source, o.signal_kind, o.is_sample, o.claim_level, o.fetch_level, '
            . 'o.qualification, o.event_date, o.source_published_at, o.product_key, o.customer_model '
            . 'FROM g2g_company_opportunities o LEFT JOIN g2g_companies c ON c.id = o.company_id WHERE o.sub_institute_id = ?',
            [$tenant]
        ));

        $hasStats = ! empty($db->select("SHOW COLUMNS FROM `g2g_research_runs` LIKE 'stage_stats'"));
        $runs = array_map(fn ($r) => (array) $r, $db->select(
            'SELECT id, status, opportunities_new, ' . ($hasStats ? 'stage_stats' : 'NULL AS stage_stats') . ', product_key, customer_model '
            . 'FROM g2g_research_runs WHERE sub_institute_id = ?',
            [$tenant]
        ));

        $report = MarketReport::build($rows, $runs);

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->printTable($report);

        return self::SUCCESS;
    }

    private function printTable(array $r): void
    {
        $line = fn (array $x) => $x['ratio'] . ' (' . ($x['pct'] === null ? 'n/a' : $x['pct'] . '%') . ')';
        $section = function (string $title, array $map) use ($line) {
            $this->info($title);
            $this->table(['Item', 'n/N'], array_map(fn ($k, $v) => [$k, $line($v)], array_keys($map), $map));
        };

        $section('Cohorts (denominator: all ' . $r['cohort']['denominator'] . ' rows)', $r['cohort']['by_class']);
        $mk = $r['market'];
        $this->info('Market-signal cohort only (denominator ' . $mk['denominator'] . ')');
        $section('Evidence maturity', $mk['maturity']);
        $section('Qualification', $mk['qualification']);
        $section('Missing dates', ['missing event_date' => $mk['missing_event_date'], 'missing source_published_at' => $mk['missing_source_published_at']]);
        $d = $mk['duplicates'];
        $this->line('Rows that are duplicates beyond the first of each group: ' . $line($d['rows_beyond_first']));
        foreach ($d['real_duplicate_groups'] as $g) {
            $this->line('  duplicate: ids ' . implode(',', $g['ids']) . '  ' . $g['url_key']);
        }
        foreach ($d['shared_article_groups'] as $g) {
            $this->line('  shared article (not duplicates): ids ' . implode(',', $g['ids']) . '  ' . $g['url_key']);
        }
        $section('By product_key / customer_model', $mk['by_product_customer_model']);
        $h = $r['runs'];
        $this->info('Run health (denominator ' . $h['denominator'] . ' runs)');
        $section('Status', $h['status_counts']);
        $this->line('Failed share: ' . $line($h['failed_share']));
        $this->line('Zero-signal share of successful runs: ' . $line($h['zero_signal_share_of_successful']));
    }
}
