<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Crm\Leads\LeadLinks;
use App\Domain\ProductMaster\ProductMaster;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in the product and the coded loss reason of the opportunities that were made before those columns existed (slice 11; the command is
 * opportunities:backfill-links).
 *
 *   product_master_id   in this order, the first that gives ONE answer:
 *                       1. the opportunity's own product tags (crm_opportunity_products -> the catalogue item -> the Product Master row that follows it): when all
 *                          its tags lead to the same Product Master row. Tags that lead to different ones are ambiguous and left empty (a person chooses).
 *                       2. the lead it was converted from (crm_leads.converted_opportunity_id): that lead's own product_master_id.
 *                       3. that lead's signal (source_company_opportunity_id): the signal's own Product Master row, else the one the resolver finds from its stored
 *                          business_fit and matched offer (LeadLinks::productForSignal, the rule a promotion uses).
 *                       Only a product the organisation may see (its own or a shared one) is ever linked.
 *   loss_reason_code    for a Closed Lost opportunity that has a free-text close_reason and no code: the code whose label or key IS that text, ignoring case,
 *                       surrounding spaces and the difference between "no budget" and "no_budget". An exact match only: "too expensive" is not guessed to be
 *                       "price"; the rest stays free text.
 *
 * The rules of every data update (Docs/agentic-crm/DB-RULES.md): dry run unless apply; one transaction per organisation; a filled value is NEVER overwritten (the
 * update itself says "where it is still NULL"); an opportunity's updated_at is not touched (this is not an edit by a person); no history row is written (nothing
 * about the deal changed); a second run finds nothing to do. Only the organisation's own rows are read.
 */
final class OpportunityLinkBackfill
{
    private const CHUNK = 200;

    /** @return list<int> the organisations that have opportunities */
    public function tenants(): array
    {
        return DB::table('crm_opportunities')->where('sub_institute_id', '>', 0)->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return array<string, mixed> counts of what was (or would be) written, and why the rest was left
     *
     * @throws OpportunityLinkBackfillBlocked when this connection lacks the columns this slice adds
     */
    public function run(int $tenantId, bool $apply): array
    {
        [$r, $plan] = $this->plan($tenantId);

        if ($apply && $plan !== []) {
            $r['written'] = $this->write($tenantId, $plan);
        }

        return $r;
    }

    /**
     * Writes a plan: opportunity id => [column => value], in one transaction. Each update says "where it is still NULL", so a value that has appeared since the plan
     * was read (a person filled it in) is never overwritten, and "where it is this organisation's", so an id of another organisation's changes nothing.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return int the columns written
     */
    public function write(int $tenantId, array $plan): int
    {
        $written = 0;
        DB::transaction(function () use ($plan, $tenantId, &$written) {
            foreach ($plan as $opportunityId => $set) {
                foreach ($set as $column => $value) {
                    $written += DB::table('crm_opportunities')->where('id', $opportunityId)->where('sub_institute_id', $tenantId)->whereNull($column)->update([$column => $value]);
                }
            }
        });

        return $written;
    }

    /**
     * Reads the organisation's opportunities and works out what would be written. Writes nothing.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} the counts, and the plan (opportunity id => [column => value])
     *
     * @throws OpportunityLinkBackfillBlocked when this connection lacks the columns this slice adds
     */
    public function plan(int $tenantId): array
    {
        $columns = ColumnExists::all('crm_opportunities');
        if (array_diff(['product_master_id', 'loss_reason_code'], $columns) !== []) {
            throw new OpportunityLinkBackfillBlocked('crm_opportunities has no product_master_id / loss_reason_code on this connection: run migration 2026_12_11_130000 first.');
        }

        $r = [
            'opportunities' => 0,
            'product_filled' => 0, 'product_to_set' => 0, 'product_by_tags' => 0, 'product_by_lead' => 0, 'product_by_signal' => 0, 'product_ambiguous' => 0, 'product_unresolved' => 0,
            'lost' => 0, 'reason_filled' => 0, 'reason_to_set' => 0, 'reason_unmatched' => 0, 'reason_none' => 0,
            'written' => 0, 'warnings' => [],
        ];

        $hasMaster = Schema::hasTable('g2g_product_master');
        $hasTags = Schema::hasTable('crm_opportunity_products') && Schema::hasTable('crm_products');
        $hasLeads = Schema::hasTable('crm_leads') && array_diff(['converted_opportunity_id', 'product_master_id', 'source_company_opportunity_id'], ColumnExists::all('crm_leads')) === [];
        $hasSignals = Schema::hasTable('g2g_company_opportunities');
        if (! $hasMaster) {
            $r['warnings'][] = 'g2g_product_master is not on this connection: no product can be resolved.';
        }
        if (! $hasLeads) {
            $r['warnings'][] = 'crm_leads has no product link on this connection: the converted lead and its signal cannot be used.';
        }

        $codes = $this->codesByText();
        $signals = [];
        $plan = [];

        DB::table('crm_opportunities')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->orderBy('id')->chunk(self::CHUNK, function ($opportunities) use (&$r, &$plan, &$signals, $tenantId, $hasMaster, $hasTags, $hasLeads, $hasSignals, $codes) {
            foreach ($opportunities as $opp) {
                $r['opportunities']++;
                $set = [];

                if ($opp->product_master_id !== null) {
                    $r['product_filled']++;
                } elseif ($hasMaster) {
                    [$productId, $how] = $this->resolveProduct($tenantId, $opp, $hasTags, $hasLeads, $hasSignals, $signals);
                    if ($how === 'ambiguous') {
                        $r['product_ambiguous']++;
                    } elseif ($productId === null) {
                        $r['product_unresolved']++;
                    } else {
                        $set['product_master_id'] = $productId;
                        $r['product_to_set']++;
                        $r['product_by_' . $how]++;
                    }
                }

                if (OpportunityRules::isClosedLost($opp->sales_stage)) {
                    $r['lost']++;
                    if ($opp->loss_reason_code !== null) {
                        $r['reason_filled']++;
                    } elseif (trim((string) $opp->close_reason) === '') {
                        $r['reason_none']++;
                    } elseif (($code = $codes[$this->normalise($opp->close_reason)] ?? null) !== null) {
                        $set['loss_reason_code'] = $code;
                        $r['reason_to_set']++;
                    } else {
                        $r['reason_unmatched']++;
                    }
                }

                if ($set !== []) {
                    $plan[(int) $opp->id] = $set;
                }
            }
        });

        return [$r, $plan];
    }

    /**
     * @param  array<int, object|false>  $signals  cache of signals read, by id
     * @return array{0: ?int, 1: string} the Product Master id and how it was found (tags, lead, signal), or [null, 'ambiguous'] / [null, 'none']
     */
    private function resolveProduct(int $tenantId, object $opp, bool $hasTags, bool $hasLeads, bool $hasSignals, array &$signals): array
    {
        if ($hasTags) {
            $masters = DB::table('crm_opportunity_products as op')
                ->join('crm_products as p', function ($j) use ($tenantId) {
                    $j->on('p.id', '=', 'op.product_id')->where('p.sub_institute_id', '=', $tenantId)->whereNull('p.deleted_at');
                })
                ->join('g2g_product_master as m', function ($j) use ($tenantId) {
                    $j->on('m.crm_product_id', '=', 'p.id')->whereIn('m.sub_institute_id', [$tenantId, ProductMaster::SHARED]);
                })
                ->where('op.opportunity_id', $opp->id)
                ->distinct()->pluck('m.id')->map(fn ($v) => (int) $v)->all();
            if (count($masters) === 1) {
                return [$masters[0], 'tags'];
            }
            if (count($masters) > 1) {
                return [null, 'ambiguous'];
            }
        }

        if ($hasLeads) {
            $lead = DB::table('crm_leads')->where('sub_institute_id', $tenantId)->where('converted_opportunity_id', $opp->id)->orderBy('id')->first(['product_master_id', 'source_company_opportunity_id']);
            if ($lead !== null) {
                $fromLead = (int) ($lead->product_master_id ?? 0);
                if ($fromLead > 0 && LeadLinks::productVisible($tenantId, $fromLead)) {
                    return [$fromLead, 'lead'];
                }

                $signalId = (int) ($lead->source_company_opportunity_id ?? 0);
                if ($signalId > 0 && $hasSignals) {
                    $signals[$signalId] ??= DB::table('g2g_company_opportunities')->where('id', $signalId)->where('sub_institute_id', $tenantId)->first() ?: false;
                    if ($signals[$signalId] && ($fromSignal = LeadLinks::productForSignal($tenantId, $signals[$signalId])) !== null) {
                        return [$fromSignal, 'signal'];
                    }
                }
            }
        }

        return [null, 'none'];
    }

    /** @return array<string, string> normalised text => code, only where the text names ONE code (a label that two codes share maps to neither) */
    private function codesByText(): array
    {
        $map = [];
        $clash = [];
        foreach (OpportunityRules::lossReasons() as $code => $label) {
            foreach ([$this->normalise($label), $this->normalise($code)] as $text) {
                if (isset($map[$text]) && $map[$text] !== $code) {
                    $clash[$text] = true;
                }
                $map[$text] = $code;
            }
        }

        return array_diff_key($map, $clash);
    }

    /** Case, surrounding spaces, runs of spaces and underscores do not matter; nothing else does: this is the text itself, not a guess about it. */
    private function normalise(?string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s_]+/u', ' ', (string) $text)));
    }
}
