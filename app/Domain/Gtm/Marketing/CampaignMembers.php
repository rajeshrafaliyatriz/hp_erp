<?php

namespace App\Domain\Gtm\Marketing;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * Adding targets to a campaign. Shared by the campaign screen, segments and the workflow engine, so
 * every path enforces the same rules: the account and contact must belong to this tenant, a contact
 * must belong to the account it is added under, and a repeat is ignored (UNIQUE index) rather than an error.
 */
final class CampaignMembers
{
    /**
     * @param  list<int>  $accountIds  account-level targets
     * @param  list<int>  $contactIds  contact-level targets (their account is added as well)
     * @return array{added: int, already_members: int, rejected: list<string>}
     */
    public function add(int $tenant, int $campaignId, array $accountIds, array $contactIds, ?int $actorId, ?array $auditMeta = null): array
    {
        $rejected = [];
        $rows = [];

        $accounts = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('id', array_unique($accountIds))->pluck('id')->all();
        foreach (array_diff(array_unique($accountIds), $accounts) as $bad) {
            $rejected[] = "Account {$bad} is not one of yours.";
        }
        foreach ($accounts as $a) {
            $rows["{$a}|0"] = ['account_id' => $a, 'contact_id' => 0];
        }

        $contacts = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('id', array_unique($contactIds))->get(['id', 'account_id'])->keyBy('id');
        foreach (array_unique($contactIds) as $cid) {
            if (! isset($contacts[$cid])) {
                $rejected[] = "Contact {$cid} is not one of yours.";

                continue;
            }
            $rows["{$contacts[$cid]->account_id}|{$cid}"] = ['account_id' => (int) $contacts[$cid]->account_id, 'contact_id' => (int) $cid];
            $rows["{$contacts[$cid]->account_id}|0"] ??= ['account_id' => (int) $contacts[$cid]->account_id, 'contact_id' => 0];
        }

        $added = 0;
        $already = 0;
        DB::transaction(function () use ($rows, $tenant, $campaignId, $actorId, &$added, &$already) {
            foreach ($rows as $r) {
                $exists = DB::table('gtm_campaign_members')->where('campaign_id', $campaignId)->where('account_id', $r['account_id'])->where('contact_id', $r['contact_id'])->exists();
                if ($exists) {
                    $already++;

                    continue;
                }
                DB::table('gtm_campaign_members')->insert($r + ['sub_institute_id' => $tenant, 'campaign_id' => $campaignId, 'status' => 'targeted', 'added_by' => $actorId, 'created_at' => now()]);
                $added++;
            }
        });

        if ($added > 0) {
            GtmAudit::record('gtm.campaign.members_added', $tenant, 'gtm_campaigns', $campaignId, $actorId, ['added' => $added, 'already_members' => $already], $auditMeta);
        }

        return ['added' => $added, 'already_members' => $already, 'rejected' => $rejected];
    }
}
