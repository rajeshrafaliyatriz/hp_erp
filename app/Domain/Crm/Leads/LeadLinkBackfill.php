<?php

namespace App\Domain\Crm\Leads;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in the product and the organisation account of the leads that were made before the columns existed (slice 6; the command is leads:backfill-links).
 *
 *   product_master_id   from the signal a lead came from (source_company_opportunity_id): the signal's own Product Master row, else the one its stored
 *                       business_fit and matched offer resolve to (LeadLinks::productForSignal, the same rule a promotion uses).
 *   org_account_id      from the CRM organisation the lead was converted into (its identity), else from the signal's company (its identity). Looked up only:
 *                       no identity is made, and nothing is matched on a name. If the two disagree the converted organisation wins (a person converted the
 *                       lead into it) and the disagreement is counted so it can be looked at.
 *
 * The rules of every data update here (Docs/agentic-crm/DB-RULES.md): dry run unless apply; one transaction per organisation; a filled value is NEVER
 * overwritten (the update itself says "where it is still NULL"); a lead's updated_at is not touched (this is not an edit by a person); a second run
 * finds nothing to do and writes nothing. Only the organisation's own leads, signals, products and identities are read.
 *
 * One exception that is not an overwrite: an account a lead points at that is no longer a live identity of the organisation (merged into another, removed)
 * shows nothing and holds nothing, so it counts as empty and is re-resolved by the same rule, replaced only if it still reads as the dead value.
 * (LeadLinks::repointAccount is the exact repair for a merge; this catches whatever it did not.)
 */
final class LeadLinkBackfill
{
    private const CHUNK = 200;

    /**
     * @return list<int> the organisations that have leads
     */
    public function tenants(): array
    {
        return DB::table('crm_leads')->where('sub_institute_id', '>', 0)->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return array<string, mixed> counts of what was (or would be) written, and why the rest was left
     *
     * @throws LeadLinkBackfillBlocked when this connection lacks the columns this slice adds
     */
    public function run(int $tenantId, bool $apply): array
    {
        $columns = ColumnExists::all('crm_leads');
        if (! in_array('product_master_id', $columns, true) || ! in_array('org_account_id', $columns, true)) {
            throw new LeadLinkBackfillBlocked('crm_leads has no product_master_id / org_account_id on this connection: run migration 2026_12_11_100100 first.');
        }

        $r = [
            'leads' => 0, 'from_a_signal' => 0, 'converted' => 0,
            'product_filled' => 0, 'product_to_set' => 0, 'product_unresolved' => 0, 'signal_not_found' => 0,
            'account_filled' => 0, 'account_repaired' => 0, 'account_dangling_left' => 0, 'account_to_set' => 0, 'account_by_converted_organisation' => 0, 'account_by_signal_company' => 0, 'account_unresolved' => 0, 'account_disagreements' => 0,
            'written' => 0, 'warnings' => [],
        ];
        $hasProducts = Schema::hasTable('g2g_product_master');
        $hasAccounts = Schema::hasTable('g2g_org_accounts');
        $hasSignals = Schema::hasTable('g2g_company_opportunities');
        if (! $hasProducts) {
            $r['warnings'][] = 'g2g_product_master is not on this connection: no product can be resolved.';
        }
        if (! $hasAccounts) {
            $r['warnings'][] = 'g2g_org_accounts is not on this connection: no organisation account can be resolved.';
        }

        // An account a lead points at that is no longer a live identity of this organisation (it was merged into another, removed, or is another organisation's)
        // is as good as empty: it shows nothing, and it is re-resolved here by the same rule. A valid one is never touched.
        $dangling = [];
        if ($hasAccounts) {
            $present = DB::table('crm_leads')->where('sub_institute_id', $tenantId)->whereNotNull('org_account_id')->distinct()->pluck('org_account_id')->map(fn ($v) => (int) $v)->all();
            $valid = $present === [] ? [] : DB::table('g2g_org_accounts')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $present)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $dangling = array_flip(array_diff($present, $valid));
        }

        $plan = [];
        $signals = [];
        DB::table('crm_leads')->where('sub_institute_id', $tenantId)->orderBy('id')->chunk(self::CHUNK, function ($leads) use (&$r, &$plan, &$signals, $tenantId, $hasProducts, $hasAccounts, $hasSignals, $dangling) {
            foreach ($leads as $lead) {
                $r['leads']++;
                $signalId = (int) ($lead->source_company_opportunity_id ?? 0);
                $organisationId = (int) ($lead->converted_organization_id ?? 0);
                $r['from_a_signal'] += $signalId > 0 ? 1 : 0;
                $r['converted'] += $organisationId > 0 ? 1 : 0;

                $signal = null;
                if ($signalId > 0 && $hasSignals) {
                    $signals[$signalId] ??= DB::table('g2g_company_opportunities')->where('id', $signalId)->where('sub_institute_id', $tenantId)->first() ?: false;
                    $signal = $signals[$signalId] ?: null;
                }

                $set = [];
                if ($lead->product_master_id !== null) {
                    $r['product_filled']++;
                } elseif ($signalId > 0 && $hasProducts) {
                    if ($signal === null) {
                        $r['signal_not_found']++;
                    } elseif (($productId = LeadLinks::productForSignal($tenantId, $signal)) !== null) {
                        $set['product_master_id'] = [$productId, null];
                        $r['product_to_set']++;
                    } else {
                        $r['product_unresolved']++;
                    }
                }

                $accountWas = $lead->org_account_id === null ? null : (int) $lead->org_account_id;
                $isDangling = $accountWas !== null && isset($dangling[$accountWas]);
                if ($accountWas !== null && ! $isDangling) {
                    $r['account_filled']++;
                } elseif ($hasAccounts && ($organisationId > 0 || $signal !== null)) {
                    $byOrganisation = $organisationId > 0 ? LeadLinks::orgAccountForCrmOrganization($tenantId, $organisationId) : null;
                    $byCompany = $signal !== null ? LeadLinks::orgAccountForCompany($tenantId, (int) $signal->company_id) : null;
                    if ($byOrganisation !== null && $byCompany !== null && $byOrganisation !== $byCompany) {
                        $r['account_disagreements']++;
                    }
                    $accountId = $byOrganisation ?? $byCompany;
                    if ($accountId === null) {
                        $r[$isDangling ? 'account_dangling_left' : 'account_unresolved']++;
                    } else {
                        $set['org_account_id'] = [$accountId, $accountWas];
                        $r['account_to_set']++;
                        $r['account_repaired'] += $isDangling ? 1 : 0;
                        $r[$byOrganisation !== null ? 'account_by_converted_organisation' : 'account_by_signal_company']++;
                    }
                } elseif ($isDangling) {
                    $r['account_dangling_left']++;   // a dead account and no route (no converted organisation, no signal) to a live one
                }

                if ($set !== []) {
                    $plan[(int) $lead->id] = $set;
                }
            }
        });

        if ($apply && $plan !== []) {
            DB::transaction(function () use ($plan, $tenantId, &$r) {
                foreach ($plan as $leadId => $set) {
                    foreach ($set as $column => [$value, $was]) {
                        // Never over a value that is there now, never another organisation's row: the update says so itself. "There now" means what the
                        // plan read: empty (NULL), or the dead account being replaced; anything else written since stands.
                        $update = DB::table('crm_leads')->where('id', $leadId)->where('sub_institute_id', $tenantId);
                        $was === null ? $update->whereNull($column) : $update->where($column, $was);
                        $r['written'] += $update->update([$column => $value]);
                    }
                }
            });
        }

        return $r;
    }
}
