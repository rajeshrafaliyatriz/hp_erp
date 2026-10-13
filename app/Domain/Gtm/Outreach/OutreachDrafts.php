<?php

namespace App\Domain\Gtm\Outreach;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\GtmOutreachMessage;
use App\Domain\Gtm\GtmPlaybook;
use App\Domain\Gtm\OutreachMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turning an Outreach Agent result into DRAFT messages. Used by the Outreach screen and by workflows, so every
 * path applies the same rules: the contact must exist in this tenant and be emailable (not bounced, unsubscribed,
 * do-not-contact or suppressed), the draft must have real text, a campaign must be open with the contact's account
 * as a target, and the same agent result can be saved only once. Drafts are never submitted or approved here.
 */
final class OutreachDrafts
{
    /**
     * @return array{ok: true, messages: list<GtmOutreachMessage>}|array{ok: false, http: int, code: string, message: string}
     */
    public function fromAnalysis(int $tenant, int $analysisId, ?int $actorId, string $source = 'agent'): array
    {
        $fail = fn (int $http, string $code, string $message) => ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message];

        $analysis = DB::table('gtm_analyses')->where('sub_institute_id', $tenant)->where('id', $analysisId)->where('kind', 'outreach_draft')->where('status', 'success')->first();
        if (! $analysis) {
            return $fail(404, 'not_found', 'Draft not found.');
        }
        if (GtmOutreachMessage::where('sub_institute_id', $tenant)->where('analysis_id', $analysis->id)->exists()) {
            return $fail(409, 'already_saved', 'This draft was already saved.');
        }
        $result = json_decode($analysis->result ?? 'null', true) ?: [];
        $contact = GtmContact::where('sub_institute_id', $tenant)->find((int) ($result['recipient']['contact_id'] ?? $analysis->subject_id));
        if (! $contact) {
            return $fail(422, 'no_contact', 'The contact for this draft no longer exists.');
        }
        if (in_array($contact->status, OutreachMailer::BLOCKED_CONTACT, true)) {
            return $fail(422, 'contact_blocked', "{$contact->full_name} is marked '{$contact->status}'. Nothing was saved.");
        }
        if ($block = app(Suppressions::class)->blocking($tenant, $contact->email)) {
            return $fail(422, 'suppressed', "This address is on the suppression list ({$block['kind']} {$block['value']}, {$block['reason']}). Nothing was saved.");
        }

        $steps = isset($result['steps']) && is_array($result['steps'])
            ? array_values(array_filter($result['steps'], fn ($s) => is_array($s) && trim((string) ($s['body'] ?? '')) !== ''))
            : [['subject' => $result['subject'] ?? '', 'body' => $result['body'] ?? '']];
        if ($steps === [] || trim((string) ($steps[0]['body'] ?? '')) === '' || trim((string) ($steps[0]['subject'] ?? '')) === '') {
            return $fail(422, 'no_text', 'The draft has no usable text.');
        }

        $campaignId = isset($result['campaign_id']) ? (int) $result['campaign_id'] : null;
        if ($campaignId !== null && ($problem = $this->campaignProblem($tenant, $campaignId, (int) $contact->account_id))) {
            return $fail(422, $problem['code'], $problem['message']);
        }

        $sequence = count($steps) > 1;
        $waits = GtmPlaybook::where('slug', 'outreach-follow-up-sequence')->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $tenant))
            ->orderByRaw('sub_institute_id IS NULL')->first()?->definition['steps'] ?? [];
        $key = $sequence ? Str::lower(Str::random(24)) : null;

        $created = DB::transaction(function () use ($steps, $sequence, $key, $waits, $contact, $analysis, $tenant, $actorId, $campaignId) {
            $due = now();
            $rows = [];
            foreach ($steps as $i => $s) {
                if ($i > 0) {
                    $due = $due->copy()->addDays((int) ($waits[$i]['wait_days'] ?? 3));
                }
                $rows[] = GtmOutreachMessage::create([
                    'sub_institute_id' => $tenant, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'analysis_id' => $analysis->id, 'campaign_id' => $campaignId,
                    'sequence_key' => $key, 'step' => $i + 1, 'due_at' => $sequence ? $due : null,
                    'subject' => mb_substr(trim((string) ($s['subject'] ?? ($steps[0]['subject'] ?? ''))) ?: 'Following up', 0, 255), 'body' => (string) $s['body'],
                    'status' => 'draft', 'created_by' => $actorId,
                ]);
            }

            return $rows;
        });
        GtmAudit::record('gtm.outreach.created', $tenant, 'gtm_outreach_messages', $created[0]->id, $actorId, ['contact_id' => $contact->id, 'source' => $source, 'steps' => count($created), 'analysis_id' => $analysis->id], $source === 'workflow' ? ['source' => 'workflow'] : null);

        return ['ok' => true, 'messages' => $created];
    }

    /** Why this contact's account cannot be used with that campaign, or null when it can. @return array{code: string, message: string}|null */
    public function campaignProblem(int $tenant, int $campaignId, int $accountId): ?array
    {
        $c = DB::table('gtm_campaigns')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($campaignId);
        if (! $c) {
            return ['code' => 'no_campaign', 'message' => 'Choose one of your own campaigns.'];
        }
        if (in_array($c->status, ['completed', 'archived'], true)) {
            return ['code' => 'campaign_closed', 'message' => "The campaign is {$c->status}, so no new outreach can be written for it."];
        }
        if (! DB::table('gtm_campaign_members')->where('sub_institute_id', $tenant)->where('campaign_id', $campaignId)->where('account_id', $accountId)->exists()) {
            return ['code' => 'not_a_target', 'message' => 'This contact\'s account is not a target of that campaign.'];
        }

        return null;
    }
}
