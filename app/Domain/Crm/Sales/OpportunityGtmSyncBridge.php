<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmDeal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bi-directional Data Bridge between CRM Opportunities (Order-to-Cash)
 * and GTM Deals (Pipeline, Forecast, AI Coaching, Command Center).
 *
 * Guarantees that actions taken on the unified 'Opportunities & Deals' screen
 * keep both crm_opportunities and gtm_deals in 100% real-time synchronization.
 */
class OpportunityGtmSyncBridge
{
    /**
     * Map CRM sales stage to GTM deal stage.
     */
    public static function mapSalesStageToGtmStage(?string $salesStage): string
    {
        $s = strtolower(trim((string) $salesStage));
        return match (true) {
            str_contains($s, 'won') => 'won',
            str_contains($s, 'lost') => 'lost',
            str_contains($s, 'negotiat') => 'negotiation',
            str_contains($s, 'proposal') || str_contains($s, 'quote') => 'proposal',
            str_contains($s, 'qualif') => 'qualification',
            default => 'discovery',
        };
    }

    /**
     * Map GTM deal stage to CRM sales stage.
     */
    public static function mapGtmStageToSalesStage(string $gtmStage): string
    {
        return match ($gtmStage) {
            'won' => 'Closed Won',
            'lost' => 'Closed Lost',
            'negotiation' => 'Negotiation/Review',
            'proposal' => 'Proposal/Price Quote',
            'qualification' => 'Qualification',
            default => 'Needs Analysis',
        };
    }

    /**
     * Synchronize a CRM Opportunity into GTM Deals.
     */
    public static function syncOpportunityToGtm(int $tenantId, int $opportunityId, ?int $actorId = null): ?int
    {
        $opp = DB::table('crm_opportunities')
            ->where('sub_institute_id', $tenantId)
            ->where('id', $opportunityId)
            ->first();

        if (! $opp) {
            return null;
        }

        $gtmStage = self::mapSalesStageToGtmStage($opp->sales_stage);
        $closingDate = $opp->closing_date ? (string) $opp->closing_date : null;

        // If this opportunity is already linked to a GTM deal, update it
        if ($opp->converted_from_gtm_deal_id) {
            $gtmDeal = GtmDeal::where('sub_institute_id', $tenantId)->find($opp->converted_from_gtm_deal_id);
            if ($gtmDeal) {
                $closing = in_array($gtmStage, ['won', 'lost'], true);
                $gtmDeal->forceFill([
                    'name' => $opp->name,
                    'amount' => $opp->amount ?: $gtmDeal->amount,
                    'currency' => $opp->currency ?: ($gtmDeal->currency ?: 'INR'),
                    'stage' => $gtmStage,
                    'expected_close_date' => $closingDate ?: $gtmDeal->expected_close_date,
                    'close_reason' => $opp->close_reason ?: $gtmDeal->close_reason,
                    'closed_at' => $closing ? ($gtmDeal->closed_at ?: now()) : null,
                    'updated_at' => now(),
                ])->save();

                return $gtmDeal->id;
            }
        }

        // Otherwise find or create the GTM account from the opportunity's organization
        $gtmAccountId = null;
        if ($opp->organization_id) {
            $org = DB::table('crm_organizations')
                ->where('sub_institute_id', $tenantId)
                ->where('id', $opp->organization_id)
                ->first();

            if ($org) {
                $account = GtmAccount::where('sub_institute_id', $tenantId)
                    ->where('crm_organization_id', $org->id)
                    ->first();

                if (! $account) {
                    $account = GtmAccount::where('sub_institute_id', $tenantId)
                        ->where('name', $org->name)
                        ->first();
                }

                if (! $account) {
                    $account = GtmAccount::create([
                        'sub_institute_id' => $tenantId,
                        'name' => $org->name,
                        'crm_organization_id' => $org->id,
                        'stage' => $gtmStage === 'won' ? 'customer' : 'target',
                        'created_by' => $actorId ?: $opp->created_by,
                    ]);
                } elseif (! $account->crm_organization_id) {
                    $account->forceFill(['crm_organization_id' => $org->id])->save();
                }

                $gtmAccountId = $account->id;
            }
        }

        // If no organization exists, fall back to first tenant account or create default
        if (! $gtmAccountId) {
            $account = GtmAccount::where('sub_institute_id', $tenantId)->first();
            if (! $account) {
                $account = GtmAccount::create([
                    'sub_institute_id' => $tenantId,
                    'name' => 'General Accounts',
                    'stage' => 'target',
                    'created_by' => $actorId ?: $opp->created_by,
                ]);
            }
            $gtmAccountId = $account->id;
        }

        // Create the synchronized GTM deal and link it back, together or not at all: a deal made without its link would be an orphan that a repair
        // run would copy into a second opportunity (slice 11, D17).
        $closing = in_array($gtmStage, ['won', 'lost'], true);

        return DB::transaction(function () use ($tenantId, $gtmAccountId, $opp, $gtmStage, $closingDate, $closing, $actorId) {
            $deal = GtmDeal::create([
                'sub_institute_id' => $tenantId,
                'account_id' => $gtmAccountId,
                'name' => $opp->name,
                'stage' => $gtmStage,
                'amount' => $opp->amount,
                'currency' => $opp->currency ?: 'INR',
                'expected_close_date' => $closingDate,
                'owner_user_id' => $opp->assigned_to ?: $actorId,
                'close_reason' => $opp->close_reason,
                'closed_at' => $closing ? now() : null,
                'created_by' => $actorId ?: $opp->created_by,
            ]);

            // Link back to CRM opportunity
            DB::table('crm_opportunities')
                ->where('id', $opp->id)
                ->update([
                    'converted_from_gtm_deal_id' => $deal->id,
                    'updated_at' => now(),
                ]);

            return $deal->id;
        });
    }

    /**
     * Synchronize a GTM Deal into CRM Opportunities.
     */
    public static function syncGtmDealToOpportunity(int $tenantId, int $dealId, ?int $actorId = null): ?int
    {
        $deal = GtmDeal::where('sub_institute_id', $tenantId)->find($dealId);
        if (! $deal) {
            return null;
        }

        $account = GtmAccount::where('sub_institute_id', $tenantId)->find($deal->account_id);
        if (! $account) {
            return null;
        }

        $existingOpp = DB::table('crm_opportunities')
            ->where('sub_institute_id', $tenantId)
            ->where('converted_from_gtm_deal_id', $deal->id)
            ->first();

        $salesStage = self::mapGtmStageToSalesStage($deal->stage);
        // A date the deal does not have is not invented. This used to fall back to TODAY, which made a GTM-sourced opportunity "overdue" the next day, overwrote
        // an opportunity's real closing date whenever the deal had none, and would have put a made-up date into the GTM forecast once synced back (that forecast
        // leaves a deal without a date out on purpose). Slice 11, found while making the resync repair idempotent.
        $closingDate = $deal->expected_close_date ? $deal->expected_close_date->toDateString() : ($deal->closed_at ? substr((string) $deal->closed_at, 0, 10) : null);

        if ($existingOpp) {
            $changes = [
                'name' => $deal->name,
                'amount' => $deal->amount ?: $existingOpp->amount,
                'currency' => $deal->currency ?: ($existingOpp->currency ?: 'INR'),
                'sales_stage' => $salesStage,
                'closing_date' => $closingDate ?: $existingOpp->closing_date,
                'close_reason' => $deal->close_reason ?: $existingOpp->close_reason,
                'updated_at' => now(),
            ];

            // The deal changed the opportunity's stage, amount or date, so the history says so (slice 11: this path wrote no row before). The row and the
            // update stand together.
            DB::transaction(function () use ($existingOpp, $changes, $actorId, $deal) {
                OpportunityStageHistory::recordIfChanged($existingOpp, $changes, $actorId ?: ($deal->owner_user_id ?: null));
                DB::table('crm_opportunities')->where('id', $existingOpp->id)->update($changes);
            });

            return $existingOpp->id;
        }

        // Resolve Organization
        $orgId = $account->crm_organization_id;
        if (! $orgId) {
            $orgId = DB::table('crm_organizations')
                ->where('sub_institute_id', $tenantId)
                ->where('name', $account->name)
                ->whereNull('deleted_at')
                ->value('id');
        }

        if (! $orgId) {
            $orgId = DB::table('crm_organizations')->insertGetId([
                'account_no' => 'ORG-' . Str::upper(Str::random(8)),
                'name' => $account->name,
                'industry' => $account->industry ?? null,
                'description' => "Synced from GTM Account #{$account->id}",
                'assigned_to' => $account->owner_user_id ?: ($actorId ?: 1),
                'sub_institute_id' => $tenantId,
                'created_by' => $actorId ?: ($deal->created_by ?: 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $account->forceFill(['crm_organization_id' => $orgId])->save();
        }

        $prob = match ($deal->stage) {
            'won' => 100,
            'lost' => 0,
            'negotiation' => 80,
            'proposal' => 60,
            'qualification' => 40,
            default => 20,
        };

        // The opportunity and its first history row (slice 11: this path wrote no row before) stand together.
        return DB::transaction(function () use ($deal, $orgId, $closingDate, $salesStage, $prob, $actorId, $tenantId) {
            $oppId = DB::table('crm_opportunities')->insertGetId([
                'opportunity_no' => 'OPP-' . Str::upper(Str::random(8)),
                'name' => $deal->name,
                'organization_id' => $orgId,
                'amount' => $deal->amount,
                'currency' => $deal->currency ?: 'INR',
                'closing_date' => $closingDate,
                'sales_stage' => $salesStage,
                'probability' => $prob,
                'lead_source' => 'GTM',
                'description' => "Synced from GTM Deal #{$deal->id}",
                'converted_from_gtm_deal_id' => $deal->id,
                'assigned_to' => $deal->owner_user_id ?: ($actorId ?: 1),
                'sub_institute_id' => $tenantId,
                'created_by' => $actorId ?: ($deal->created_by ?: 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            OpportunityStageHistory::record($oppId, null, $salesStage, $deal->amount, $prob, $closingDate, $actorId ?: ($deal->created_by ?: null));

            return $oppId;
        });
    }

    /**
     * Backfill synchronization across all deals and opportunities in a tenant.
     */
    public static function backfillTenant(int $tenantId): array
    {
        $deals = GtmDeal::where('sub_institute_id', $tenantId)->get();
        $syncedDeals = 0;
        foreach ($deals as $deal) {
            self::syncGtmDealToOpportunity($tenantId, $deal->id);
            $syncedDeals++;
        }

        $opps = DB::table('crm_opportunities')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();
        $syncedOpps = 0;
        foreach ($opps as $opp) {
            self::syncOpportunityToGtm($tenantId, $opp->id);
            $syncedOpps++;
        }

        return [
            'deals_synced_to_opps' => $syncedDeals,
            'opps_synced_to_deals' => $syncedOpps,
        ];
    }
}

