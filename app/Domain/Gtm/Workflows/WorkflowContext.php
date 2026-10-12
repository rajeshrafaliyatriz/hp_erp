<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\GtmEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads the real facts behind one event, for ONE tenant, into the flat `values` map conditions and templates read.
 * Everything is queried with the event's own sub_institute_id; nothing comes from a request. Returns null when the
 * record the event is about no longer exists (the run is then skipped with that reason, not failed).
 *
 * `ids` holds the entity ids actions need (account, contact, deal, ...). Personal contact details (email, phone,
 * notes) are never loaded into `values`.
 */
final class WorkflowContext
{
    /**
     * @param  array<string, mixed>  $payload  the event's payload (event.* facts)
     * @return array{values: array<string, mixed>, ids: array<string, int>}|null
     */
    public static function load(int $tenant, string $type, ?string $entityType, ?int $entityId, array $payload): ?array
    {
        if (! $entityType || ! $entityId) {
            return null;
        }
        $values = [];
        $ids = [];
        foreach ($payload as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $values["event.{$k}"] = $v;
            }
        }
        $accountId = null;

        switch ($entityType) {
            case 'gtm_accounts':
                $accountId = $entityId;
                break;
            case 'gtm_contacts':
                $c = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($entityId);
                if (! $c) {
                    return null;
                }
                $ids['contact'] = (int) $c->id;
                $accountId = (int) $c->account_id;
                $values += ['contact.id' => (int) $c->id, 'contact.full_name' => $c->full_name, 'contact.title' => $c->title, 'contact.role_in_deal' => $c->role_in_deal, 'contact.status' => $c->status, 'contact.has_email' => trim((string) $c->email) !== ''];
                break;
            case 'gtm_deals':
                $d = DB::table('gtm_deals')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($entityId);
                if (! $d) {
                    return null;
                }
                $ids['deal'] = (int) $d->id;
                $accountId = (int) $d->account_id;
                $values += ['deal.id' => (int) $d->id, 'deal.name' => $d->name, 'deal.stage' => $d->stage, 'deal.amount' => $d->amount !== null ? (float) $d->amount : null, 'deal.currency' => $d->currency,
                    'deal.days_in_stage' => $d->stage_changed_at ? (int) Carbon::parse($d->stage_changed_at)->diffInDays(now()) : null, 'deal.has_next_step' => trim((string) $d->next_step) !== '', 'deal.forecast_category' => $d->forecast_category];
                break;
            case 'gtm_outreach_messages':
                $m = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->find($entityId);
                if (! $m) {
                    return null;
                }
                $ids['message'] = (int) $m->id;
                $ids['contact'] = (int) $m->contact_id;
                $accountId = (int) $m->account_id;
                $values += ['message.id' => (int) $m->id, 'message.status' => $m->status, 'message.step' => (int) $m->step, 'message.has_campaign' => $m->campaign_id !== null];
                $c = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->find($m->contact_id);
                if ($c) {
                    $values += ['contact.id' => (int) $c->id, 'contact.full_name' => $c->full_name, 'contact.title' => $c->title, 'contact.role_in_deal' => $c->role_in_deal, 'contact.status' => $c->status, 'contact.has_email' => trim((string) $c->email) !== ''];
                }
                break;
            case 'gtm_renewals':
                $r = DB::table('gtm_renewals')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($entityId);
                if (! $r) {
                    return null;
                }
                $ids['renewal'] = (int) $r->id;
                $accountId = (int) $r->account_id;
                $values += ['renewal.id' => (int) $r->id, 'renewal.name' => $r->name, 'renewal.status' => $r->status, 'renewal.amount' => $r->amount !== null ? (float) $r->amount : null, 'renewal.currency' => $r->currency,
                    'renewal.days_until' => (int) now()->startOfDay()->diffInDays(Carbon::parse($r->renewal_date)->startOfDay(), false)];
                break;
            default:
                return null;
        }

        $a = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($accountId);
        if (! $a) {
            return null;
        }
        $ids['account'] = (int) $a->id;
        $contacts = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->where('account_id', $a->id)->whereNull('deleted_at')->count();
        $values += ['account.id' => (int) $a->id, 'account.name' => $a->name, 'account.stage' => $a->stage, 'account.industry' => $a->industry, 'account.location' => $a->location,
            'account.icp_fit_score' => $a->icp_fit_score !== null ? (int) $a->icp_fit_score : null, 'account.source' => $a->source, 'account.owner_user_id' => $a->owner_user_id,
            'account.contacts_count' => $contacts, 'account.has_contacts' => $contacts > 0];

        return ['values' => $values, 'ids' => $ids];
    }

    /** Load straight from a stored event row. @return array{values: array<string, mixed>, ids: array<string, int>}|null */
    public static function fromEvent(object $event): ?array
    {
        $payload = json_decode((string) ($event->payload ?? '[]'), true);

        return self::load((int) $event->sub_institute_id, (string) $event->type, $event->entity_type, $event->entity_id !== null ? (int) $event->entity_id : null, is_array($payload) ? $payload : []);
    }

    /** True for an event type a workflow may start from. */
    public static function triggerable(string $type): bool
    {
        return isset(GtmEvents::TYPES[$type]) && GtmEvents::TYPES[$type]['triggerable'];
    }
}
