<?php

namespace App\Domain\Campaigns;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;

/**
 * A campaign's funnel, counted from the touchpoint timestamps its targets already keep (slice 9): how many targets it has, and how many of them have each of
 * sent, opened, replied, meeting booked, converted and opted out set. One query, and it always starts from the CAMPAIGN: `crm_campaign_targets` has no
 * organisation column, so a target belongs to an organisation only through its campaign, and the campaign row is what is matched to the caller's organisation.
 * A campaign id of another organisation (or one in the Recycle Bin, or one that does not exist) answers null and counts nothing.
 *
 * It counts what was RECORDED. The timestamps are written by `CrmCampaignController::recordTargetEvent` and by marking an approval sent
 * (`CrmOutreachApprovalController::markSent`, `sent_at` only); the queued mail ledger (`crm_emails`) is a different thing and is not read. A target counts for
 * every stage it has a timestamp for (a target that replied and booked a meeting counts in both), and a target counts whatever has since happened to the
 * person it points at: a message that was sent was sent.
 */
final class CampaignFunnel
{
    /** @var array<string, string> the target's timestamp column => the funnel key it feeds */
    public const STAGES = [
        'sent_at' => 'sent',
        'opened_at' => 'opened',
        'replied_at' => 'replied',
        'meeting_booked_at' => 'meetings',
        'converted_at' => 'converted',
        'opted_out_at' => 'opted_out',
    ];

    /**
     * @return array{targets: int, sent: int, opened: int, replied: int, meetings: int, converted: int, opted_out: int}|null null when the campaign is not this organisation's
     */
    public static function for(int $tenantId, int $campaignId): ?array
    {
        if ($tenantId <= 0 || $campaignId <= 0) {
            return null;
        }

        // A host that has not run the touchpoint migration (2026_12_03_080300) has no such column: that stage is 0 there, not an error.
        $present = ColumnExists::all('crm_campaign_targets');
        $select = ['MAX(c.id) AS campaign', 'COUNT(t.id) AS targets'];
        foreach (self::STAGES as $column => $key) {
            $select[] = in_array($column, $present, true) ? "COUNT(t.{$column}) AS {$key}" : "0 AS {$key}";
        }

        $row = DB::table('crm_campaigns as c')
            ->leftJoin('crm_campaign_targets as t', 't.campaign_id', '=', 'c.id')
            ->where('c.id', $campaignId)
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->selectRaw(implode(', ', $select))
            ->first();

        if ($row === null || $row->campaign === null) {
            return null;
        }

        $funnel = ['targets' => (int) $row->targets];
        foreach (self::STAGES as $key) {
            $funnel[$key] = (int) $row->{$key};
        }

        return $funnel;
    }
}
