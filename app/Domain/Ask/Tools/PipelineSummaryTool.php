<?php

namespace App\Domain\Ask\Tools;

use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRecords;
use App\Domain\Ask\AskRights;
use App\Domain\Ask\AskRow;
use App\Domain\Ask\ToolResult;

/**
 * pipeline_summary {}: counts and sums, no record named. Opportunities by stage (deals and amount per currency, with the open total worked out here so the
 * model does not do arithmetic) from the Opportunities right, and leads by status, by source ("where did my leads come from?") and with how many came from
 * a signal (so "how many leads came from signals?" has an answer) from the Leads right. Each part is its own citable row; a part the person may not open is named in the result.
 */
final class PipelineSummaryTool extends AskTool
{
    public function name(): string
    {
        return 'pipeline_summary';
    }

    public function describe(): string
    {
        return 'pipeline_summary {}. Counts and money, no records: opportunities by stage (deals and amounts, open total) and leads by status, by source (where they came from) and how many came from a signal. Takes no arguments.';
    }

    protected function allowed(): array
    {
        return [];
    }

    protected function normalise(AskCaller $caller, array $args): array
    {
        return [];
    }

    public function run(AskCaller $caller, array $args): ToolResult
    {
        $rows = [];
        $withheld = [];

        if (AskRights::canRead($caller, 'opportunity')) {
            $stages = AskRecords::pipeline($caller);
            $open = ['deals' => 0, 'by_currency' => []];
            foreach ($stages as $s) {
                if (! preg_match('/closed|won|lost/i', $s['stage'])) {
                    $open['deals'] += $s['deals'];
                    $currency = $s['currency'] ?? 'no currency';
                    $open['by_currency'][$currency] = round(($open['by_currency'][$currency] ?? 0) + ($s['amount'] ?? 0), 2);
                }
            }
            $rows[] = new AskRow('summary', 'pipeline', 'Pipeline by stage', '/module/crm/sales/opportunities', [
                'deals_in_all' => array_sum(array_column($stages, 'deals')), 'by_stage' => $stages, 'open_deals' => $open['deals'], 'open_amount_by_currency' => $open['by_currency'],
                'about_these_amounts' => 'an amount adds up only the deals that have one; amount null means no deal of that stage has an amount recorded (it is not 0)',
            ]);
        } else {
            $withheld[] = AskRights::plural('opportunity');
        }

        if (AskRights::canRead($caller, 'lead')) {
            $leads = AskRecords::leads($caller);
            if ($leads !== null) {
                $data = [
                    'open_leads' => $leads['open'], 'converted_leads' => $leads['converted'], 'open_leads_by_status' => $leads['by_status'],
                    'leads_that_came_from_a_signal' => $leads['from_signals'],
                ];
                $about = 'leads_that_came_from_a_signal counts every lead made from a signal, converted ones included; open_leads and open_leads_by_status leave converted leads out';
                if ($leads['by_source'] !== null) {
                    $data['all_leads'] = $leads['open'] + $leads['converted'];
                    $data['leads_by_source'] = $leads['by_source'];
                    $data['leads_by_source_left_out'] = $leads['sources_left_out'];
                    $about .= '; leads_by_source counts every lead (open and converted) by the source typed on the lead, "' . AskRecords::NO_SOURCE_LABEL . '" being the leads with no source typed; it names at most '
                        . AskRecords::SOURCE_CAP . ' sources, and leads_by_source_left_out says how many more sources and leads it did not list';
                } else {
                    $about .= '; this system does not keep a source on its leads, so where they came from is not known';
                }
                $data['about_these_counts'] = $about;
                $rows[] = new AskRow('summary', 'leads', 'Leads summary', '/module/crm/marketing/leads', $data);
            }
        } else {
            $withheld[] = AskRights::plural('lead');
        }

        if ($rows === [] && $withheld !== []) {
            return new ToolResult($this->name(), $args, ToolResult::NOT_PERMITTED, [], $withheld);
        }

        return new ToolResult($this->name(), $args, ToolResult::OK, $rows, $withheld, null, 'in the pipeline and the leads');
    }
}
