<?php

namespace App\Domain\Gtm\Marketing;

use Illuminate\Support\Facades\DB;

/**
 * What a campaign has actually achieved, COUNTED from records on every read. Nothing here is stored or
 * estimated, so it cannot drift from the outreach messages, activities and deals it describes.
 *
 * ATTRIBUTION IS A CORRELATION. A deal counts for a campaign when it was created on a member account
 * AFTER that account joined. That does not prove the campaign caused it, and the response says so. A rate is
 * null (not zero) when its denominator is zero.
 */
final class CampaignMetrics
{
    /** @return array<string, mixed> */
    public function for(int $tenant, int $campaignId): array
    {
        $members = DB::table('gtm_campaign_members')->where('sub_institute_id', $tenant)->where('campaign_id', $campaignId)->get(['account_id', 'contact_id', 'created_at']);
        $accountIds = $members->pluck('account_id')->unique()->values();
        $joined = $members->groupBy('account_id')->map(fn ($g) => $g->min('created_at'));
        $targetedContacts = $members->where('contact_id', '>', 0)->pluck('contact_id')->unique()->values();

        $msgs = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->where('campaign_id', $campaignId)->get(['id', 'contact_id', 'status', 'outcome']);
        $byStatus = $msgs->countBy('status');
        $reached = $msgs->where('status', 'sent')->pluck('contact_id')->unique();

        // Replies and engagement: inbound email activity on a member account since the account joined.
        $inbound = $accountIds->isEmpty() ? collect() : DB::table('gtm_activities')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)
            ->where('type', 'email')->where('direction', 'inbound')->get(['account_id', 'contact_id', 'occurred_at'])
            ->filter(fn ($a) => isset($joined[$a->account_id]) && (string) $a->occurred_at >= (string) $joined[$a->account_id]);
        $repliedContacts = $inbound->whereNotNull('contact_id')->pluck('contact_id')->unique();
        $engagedAccounts = $inbound->pluck('account_id')->unique();

        $unsubscribed = $accountIds->isEmpty() ? 0 : DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->where('status', 'unsubscribed')->count();
        $bounced = $accountIds->isEmpty() ? 0 : DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->where('status', 'bounced')->count();

        $deals = $accountIds->isEmpty() ? collect() : DB::table('gtm_deals')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('account_id', $accountIds)->get(['id', 'account_id', 'stage', 'amount', 'currency', 'created_at', 'closed_at'])
            ->filter(fn ($d) => isset($joined[$d->account_id]) && (string) $d->created_at >= (string) $joined[$d->account_id]);
        $won = $deals->where('stage', 'won');
        $sum = function ($rows) {
            $out = [];
            foreach ($rows as $d) {
                if ($d->amount !== null && $d->currency) {
                    $out[$d->currency] = ($out[$d->currency] ?? 0) + (int) round((float) $d->amount * 100);
                }
            }

            return array_map(fn ($c) => $c / 100, $out);
        };
        $rate = fn (int $num, int $den) => $den > 0 ? round($num / $den * 100, 1) : null;

        return [
            'targets' => ['accounts' => $accountIds->count(), 'contacts' => $targetedContacts->count()],
            'outreach' => [
                'messages' => $msgs->count(), 'draft' => (int) ($byStatus['draft'] ?? 0), 'pending_approval' => (int) ($byStatus['pending_approval'] ?? 0),
                'approved' => (int) ($byStatus['approved'] ?? 0), 'sent' => (int) ($byStatus['sent'] ?? 0), 'failed' => (int) ($byStatus['failed'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0), 'contacts_reached' => $reached->count(),
            ],
            'engagement' => [
                'contacts_replied' => $repliedContacts->count(), 'accounts_engaged' => $engagedAccounts->count(),
                'bounced_contacts' => $bounced, 'unsubscribed_contacts' => $unsubscribed,
                'reply_rate_pct' => $rate($repliedContacts->count(), $reached->count()), 'account_engagement_pct' => $rate($engagedAccounts->count(), $accountIds->count()),
            ],
            'conversion' => [
                'accounts_with_new_deal' => $deals->pluck('account_id')->unique()->count(), 'deals_created' => $deals->count(), 'deals_won' => $won->count(),
                'pipeline_value_by_currency' => $sum($deals->whereIn('stage', ['discovery', 'qualification', 'proposal', 'negotiation'])), 'won_value_by_currency' => $sum($won),
                'account_conversion_pct' => $rate($deals->pluck('account_id')->unique()->count(), $accountIds->count()),
                'note' => 'A deal is counted when it was created on a member account after the account joined this campaign. That is a correlation, not proof the campaign caused it.',
            ],
        ];
    }
}
