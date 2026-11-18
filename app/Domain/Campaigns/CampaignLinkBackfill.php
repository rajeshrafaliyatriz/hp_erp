<?php

namespace App\Domain\Campaigns;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in the links of campaigns and leads that were made before the columns existed (slice 9; the command is campaigns:backfill-links).
 *
 *   crm_campaigns.product_master_id   from the campaign's loose `product_id` (a catalogue item, crm_products.id) through the Product Master row of THIS
 *                                     organisation whose `crm_product_id` is that item. A campaign whose item has no master row is left empty and counted.
 *   crm_leads.campaign_id             from the lead's free-text `campaign_text`, matched to the NAME of one of the organisation's own live campaigns. The match
 *                                     is exact except for case and the spaces around it (nothing fuzzy: no contains, no punctuation or word-order tolerance). If
 *                                     two live campaigns of the organisation carry the same name the lead is NOT linked: the name is reported as ambiguous, because
 *                                     a wrong first-touch is worse than a missing one.
 *
 * The rules of every data update here (Docs/agentic-crm/DB-RULES.md): dry run unless apply; one transaction per organisation; a filled value is NEVER
 * overwritten (the update itself says "where it is still NULL"); a row's updated_at is not touched (this is not an edit by a person); a second run finds nothing
 * to do and writes nothing. Only the organisation's own campaigns, leads and products are read; a lead with no organisation (crm_leads.sub_institute_id is
 * nullable on older rows) is never read. Soft-deleted campaigns and leads are linked too, so a restored row is whole, except that a campaign in the Recycle Bin is
 * not a candidate for a lead (a lead is not attributed to a campaign nobody can open).
 */
final class CampaignLinkBackfill
{
    private const CHUNK = 200;

    /** How many ambiguous names a report lists (the count is always complete). */
    public const AMBIGUOUS_LISTED = 20;

    /**
     * @return list<int> the organisations that have campaigns, or leads with a campaign text
     */
    public function tenants(): array
    {
        $fromCampaigns = DB::table('crm_campaigns')->where('sub_institute_id', '>', 0)->distinct()->pluck('sub_institute_id');
        $fromLeads = DB::table('crm_leads')->where('sub_institute_id', '>', 0)->whereNotNull('campaign_text')->where('campaign_text', '!=', '')->distinct()->pluck('sub_institute_id');

        return $fromCampaigns->merge($fromLeads)->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
    }

    /**
     * @return array<string, mixed> counts of what was (or would be) written, and why the rest was left
     *
     * @throws CampaignLinkBackfillBlocked when this connection lacks the columns this slice adds
     */
    public function run(int $tenantId, bool $apply): array
    {
        if (! in_array('product_master_id', ColumnExists::all('crm_campaigns'), true) || ! in_array('campaign_id', ColumnExists::all('crm_leads'), true)) {
            throw new CampaignLinkBackfillBlocked('crm_campaigns has no product_master_id or crm_leads has no campaign_id on this connection: run migrations 2026_12_11_140000 and 2026_12_11_140200 first.');
        }

        $r = [
            'campaigns' => 0, 'campaigns_with_product' => 0, 'product_filled' => 0, 'product_to_set' => 0, 'product_unresolved' => 0,
            'leads' => 0, 'leads_with_text' => 0, 'campaign_filled' => 0, 'campaign_to_set' => 0, 'campaign_no_match' => 0, 'campaign_ambiguous' => 0,
            'ambiguous_names' => [], 'written' => 0, 'warnings' => [],
        ];
        $hasProducts = Schema::hasTable('g2g_product_master');
        if (! $hasProducts) {
            $r['warnings'][] = 'g2g_product_master is not on this connection: no product can be resolved.';
        }

        // ── campaigns: the product ───────────────────────────────────────────
        $campaignPlan = [];
        DB::table('crm_campaigns')->where('sub_institute_id', $tenantId)->orderBy('id')->chunk(self::CHUNK, function ($campaigns) use (&$r, &$campaignPlan, $tenantId, $hasProducts) {
            $wanted = [];
            foreach ($campaigns as $campaign) {
                if ($campaign->product_master_id === null && (int) ($campaign->product_id ?? 0) > 0) {
                    $wanted[(int) $campaign->product_id] = true;
                }
            }
            // This organisation's own master rows only (the shared ones hold no catalogue pointer); the unique key (sub_institute_id, crm_product_id) makes it one row per item.
            $masters = ($hasProducts && $wanted !== [])
                ? DB::table('g2g_product_master')->where('sub_institute_id', $tenantId)->whereIn('crm_product_id', array_keys($wanted))->pluck('id', 'crm_product_id')->all()
                : [];

            foreach ($campaigns as $campaign) {
                $r['campaigns']++;
                $productId = (int) ($campaign->product_id ?? 0);
                $r['campaigns_with_product'] += $productId > 0 ? 1 : 0;
                if ($campaign->product_master_id !== null) {
                    $r['product_filled']++;
                } elseif ($productId > 0 && $hasProducts) {
                    if (isset($masters[$productId])) {
                        $campaignPlan[(int) $campaign->id] = (int) $masters[$productId];
                        $r['product_to_set']++;
                    } else {
                        $r['product_unresolved']++;
                    }
                }
            }
        });

        // ── leads: the campaign, by its exact name ───────────────────────────
        $byName = [];
        foreach (DB::table('crm_campaigns')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']) as $c) {
            $byName[self::normalise((string) $c->name)][] = (int) $c->id;
        }
        $ambiguous = [];
        $leadPlan = [];
        DB::table('crm_leads')->where('sub_institute_id', $tenantId)->orderBy('id')->chunk(self::CHUNK, function ($leads) use (&$r, &$leadPlan, &$ambiguous, $byName) {
            foreach ($leads as $lead) {
                $r['leads']++;
                $text = self::normalise((string) ($lead->campaign_text ?? ''));
                $r['leads_with_text'] += $text !== '' ? 1 : 0;
                if ($lead->campaign_id !== null) {
                    $r['campaign_filled']++;

                    continue;
                }
                if ($text === '') {
                    continue;
                }
                $candidates = $byName[$text] ?? [];
                if ($candidates === []) {
                    $r['campaign_no_match']++;
                } elseif (count($candidates) > 1) {
                    $r['campaign_ambiguous']++;
                    $ambiguous[$text] = trim((string) $lead->campaign_text);
                } else {
                    $leadPlan[(int) $lead->id] = $candidates[0];
                    $r['campaign_to_set']++;
                }
            }
        });
        $r['ambiguous_names'] = array_slice(array_values($ambiguous), 0, self::AMBIGUOUS_LISTED);

        if ($apply && ($campaignPlan !== [] || $leadPlan !== [])) {
            DB::transaction(function () use ($campaignPlan, $leadPlan, $tenantId, &$r) {
                foreach ($campaignPlan as $campaignId => $masterId) {
                    // Never over a value that is there now, never another organisation's row: the update says so itself.
                    $r['written'] += DB::table('crm_campaigns')->where('id', $campaignId)->where('sub_institute_id', $tenantId)->whereNull('product_master_id')->update(['product_master_id' => $masterId]);
                }
                foreach ($leadPlan as $leadId => $campaignId) {
                    $r['written'] += DB::table('crm_leads')->where('id', $leadId)->where('sub_institute_id', $tenantId)->whereNull('campaign_id')->update(['campaign_id' => $campaignId]);
                }
            });
        }

        return $r;
    }

    /** A campaign name or a lead's campaign text for comparing: the spaces around it removed and the case folded. Nothing else is made equal. */
    public static function normalise(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
