<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\GtmOutreachMessage;
use App\Domain\Gtm\GtmPlaybook;
use App\Domain\Gtm\Outreach\OutreachDrafts;
use App\Domain\Gtm\Outreach\Suppressions;
use App\Domain\Gtm\OutreachMailer;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Collection;

/**
 * Outreach messages and their approval lifecycle.
 *
 *   draft -> pending_approval -> approved -> sent        (rejected -> edit -> draft; cancel any unsent)
 *
 * Writing a draft, submitting it, approving it and sending it are four separate calls, and the
 * send re-checks everything in OutreachMailer. Agents only ever produce drafts; nothing here is
 * triggered by an AI result, and there is no scheduler that sends on its own.
 */
class OutreachController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $q = GtmOutreachMessage::where('gtm_outreach_messages.sub_institute_id', $t)
            ->join('gtm_contacts as c', fn ($j) => $j->on('c.id', '=', 'gtm_outreach_messages.contact_id')->where('c.sub_institute_id', '=', $t))
            ->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'gtm_outreach_messages.account_id')->where('a.sub_institute_id', '=', $t))
            ->select('gtm_outreach_messages.*', 'c.full_name as contact_name', 'c.email as contact_email', 'c.status as contact_status', 'a.name as account_name');
        if (in_array($request->input('status'), GtmOutreachMessage::STATUSES, true)) {
            $q->where('gtm_outreach_messages.status', $request->input('status'));
        }
        if ($request->filled('contact_id')) {
            $q->where('gtm_outreach_messages.contact_id', (int) $request->input('contact_id'));
        }
        $items = $q->orderByDesc('gtm_outreach_messages.id')->limit(200)->get();
        $counts = GtmOutreachMessage::where('sub_institute_id', $t)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['status' => 1, 'data' => ['items' => $items, 'counts' => $counts]]);
    }

    public function readiness(Request $request, OutreachMailer $mailer): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'data' => $mailer->readiness($identity['sub_institute_id'], $request->boolean('check'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $v = Validator::make($request->all(), [
            'contact_id' => 'required|integer', 'subject' => 'required|string|max:255', 'body' => 'required|string|min:20|max:20000', 'deal_id' => 'nullable|integer', 'campaign_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $contact = GtmContact::where('sub_institute_id', $t)->find((int) $request->input('contact_id'));
        if (! $contact) {
            return response()->json(['status' => 0, 'message' => 'Choose one of your own contacts.'], 422);
        }
        if (in_array($contact->status, OutreachMailer::BLOCKED_CONTACT, true)) {
            return response()->json(['status' => 0, 'code' => 'contact_blocked', 'message' => "{$contact->full_name} is marked '{$contact->status}'. No message can be written for this contact."], 422);
        }
        if ($block = app(Suppressions::class)->blocking($t, $contact->email)) {
            return response()->json(['status' => 0, 'code' => 'suppressed', 'message' => "This address is on the suppression list ({$block['kind']} {$block['value']}, {$block['reason']}), so no message can be written for it."], 422);
        }
        if ($request->filled('deal_id') && ! DB::table('gtm_deals')->where('sub_institute_id', $t)->where('id', (int) $request->input('deal_id'))->where('account_id', $contact->account_id)->exists()) {
            return response()->json(['status' => 0, 'message' => 'That deal does not belong to this contact\'s account.'], 422);
        }

        $campaign = $request->filled('campaign_id') ? $this->campaignFor($t, (int) $request->input('campaign_id'), (int) $contact->account_id) : null;
        if ($campaign instanceof JsonResponse) {
            return $campaign;
        }

        $m = GtmOutreachMessage::create([
            'sub_institute_id' => $t, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'deal_id' => $request->input('deal_id'), 'campaign_id' => $campaign,
            'subject' => trim((string) $request->input('subject')), 'body' => (string) $request->input('body'), 'status' => 'draft', 'created_by' => $identity['user_id'],
        ]);
        GtmAudit::record('gtm.outreach.created', $t, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['contact_id' => $contact->id, 'source' => 'manual']);

        return response()->json(['status' => 1, 'data' => ['message' => $m]], 201);
    }

    /** Save an Outreach Agent result (one email, or every step of a sequence) as drafts. */
    public function fromAnalysis(Request $request, OutreachDrafts $drafts): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $v = Validator::make($request->all(), ['analysis_id' => 'required|integer']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $r = $drafts->fromAnalysis($identity['sub_institute_id'], (int) $request->input('analysis_id'), $identity['user_id']);

        return $r['ok']
            ? response()->json(['status' => 1, 'data' => ['messages' => $r['messages']]], 201)
            : response()->json(['status' => 0, 'code' => $r['code'], 'message' => $r['message']], $r['http']);
    }
    public function update(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if (! in_array($m->status, ['draft', 'rejected', 'pending_approval', 'approved', 'failed'], true)) {
            return response()->json(['status' => 0, 'code' => 'locked', 'message' => "A {$m->status} message cannot be edited."], 422);
        }
        $v = Validator::make($request->all(), ['subject' => 'sometimes|string|max:255', 'body' => 'sometimes|string|min:20|max:20000']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $m->fill($v->validated());
        if (! $m->isDirty(['subject', 'body'])) {
            return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]); // nothing changed: an approval must not be withdrawn by a no-op save
        }
        // CHANGED TEXT IS NEW TEXT. Whatever was approved or scheduled no longer describes it, so the approval,
        // its pinned hash and any send time are cleared and the message goes back to draft for a fresh approval.
        $wasApproved = $m->status === 'approved';
        $m->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'approved_hash' => null, 'scheduled_for' => null, 'next_attempt_at' => null, 'error' => null, 'submitted_at' => null])->save();
        GtmAudit::record('gtm.outreach.updated', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['changed' => array_keys($v->validated())]);
        if ($wasApproved) {
            GtmAudit::record('gtm.outreach.approval_invalidated', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['reason' => 'text edited after approval']);
        }

        return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if ($m->status !== 'draft') {
            return response()->json(['status' => 0, 'message' => 'Only a draft can be submitted for approval.'], 422);
        }
        $contact = GtmContact::where('sub_institute_id', $m->sub_institute_id)->find($m->contact_id);
        if (! $contact || ! filter_var((string) $contact->email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => 0, 'code' => 'no_recipient', 'message' => 'The contact has no valid email address. Add one before submitting.'], 422);
        }
        if (in_array($contact->status, OutreachMailer::BLOCKED_CONTACT, true)) {
            return response()->json(['status' => 0, 'code' => 'contact_blocked', 'message' => "The contact is marked '{$contact->status}'."], 422);
        }
        if (app(Suppressions::class)->blocking((int) $m->sub_institute_id, $contact->email)) {
            return response()->json(['status' => 0, 'code' => 'suppressed', 'message' => 'This address is on the suppression list.'], 422);
        }
        $m->forceFill(['status' => 'pending_approval', 'submitted_at' => now()])->save();
        GtmAudit::record('gtm.outreach.submitted', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id']);

        return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if ($m->status !== 'pending_approval') {
            return response()->json(['status' => 0, 'message' => 'Only a message awaiting approval can be approved.'], 422);
        }
        if (config('gtm.outreach.require_second_approver') && (int) $m->created_by === (int) $identity['user_id']) {
            return response()->json(['status' => 0, 'code' => 'second_approver_required', 'message' => 'A different administrator must approve a message you wrote.'], 403);
        }
        if (! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => 'Approving means this exact text may be emailed to this contact. Confirm to continue.'], 409);
        }
        $sendAt = $this->sendAt($request);
        if ($sendAt instanceof JsonResponse) {
            return $sendAt;
        }
        $this->markApproved($m, $identity, $request->input('note'), $sendAt);

        return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if ($m->status !== 'pending_approval') {
            return response()->json(['status' => 0, 'message' => 'Only a message awaiting approval can be rejected.'], 422);
        }
        $v = Validator::make($request->all(), ['note' => 'required|string|min:3|max:500']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $m->forceFill(['status' => 'rejected', 'decided_by' => $identity['user_id'], 'decision_note' => (string) $request->input('note')])->save();
        GtmAudit::record('gtm.outreach.rejected', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id']);

        return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if (in_array($m->status, ['sent', 'sending', 'cancelled'], true)) {
            return response()->json(['status' => 0, 'message' => "A {$m->status} message cannot be cancelled."], 422);
        }
        $m->forceFill(['status' => 'cancelled', 'decided_by' => $identity['user_id']])->save();
        GtmAudit::record('gtm.outreach.cancelled', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id']);

        return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]);
    }

    public function send(Request $request, int $id, OutreachMailer $mailer): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        $r = $mailer->send($m, $identity['user_id']);

        return $r['ok']
            ? response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]])
            : response()->json(['status' => 0, 'code' => $r['code'], 'message' => $r['message'], 'data' => ['message' => $m->fresh()]], $r['http']);
    }

    /** Approve one message and, optionally, set when the scheduler may send it. The approval pins the exact text. */
    private function markApproved(GtmOutreachMessage $m, array $identity, ?string $note, ?Carbon $sendAt): void
    {
        $m->forceFill([
            'status' => 'approved', 'approved_by' => $identity['user_id'], 'approved_at' => now(), 'approved_hash' => $m->contentHash(),
            'decided_by' => $identity['user_id'], 'decision_note' => $note ? mb_substr($note, 0, 500) : null,
            'scheduled_for' => $sendAt, 'next_attempt_at' => null, 'attempts' => 0, 'error' => null,
        ])->save();
        GtmAudit::record('gtm.outreach.approved', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['hash' => $m->approved_hash, 'scheduled_for' => $sendAt?->toDateTimeString()]);
        if ($sendAt) {
            GtmAudit::record('gtm.outreach.scheduled', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['scheduled_for' => $sendAt->toDateTimeString()]);
        }
    }

    /** A send time must be sane: not meaningfully in the past, not beyond 90 days. @return Carbon|null|JsonResponse */
    private function sendAt(Request $request): Carbon|JsonResponse|null
    {
        if (! $request->filled('send_at')) {
            return null;
        }
        $v = Validator::make($request->all(), ['send_at' => 'date']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $at = Carbon::parse((string) $request->input('send_at'));
        if ($at->lt(now()->subMinute())) {
            return response()->json(['status' => 0, 'code' => 'send_at_past', 'message' => 'The send time is in the past.'], 422);
        }
        if ($at->gt(now()->addDays(90))) {
            return response()->json(['status' => 0, 'code' => 'send_at_far', 'message' => 'Schedule at most 90 days ahead.'], 422);
        }

        return $at;
    }

    /** Remove the send time: the message stays approved but only a person can send it now. */
    public function unschedule(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if ($m->status !== 'approved' || $m->scheduled_for === null) {
            return response()->json(['status' => 0, 'message' => 'Only an approved, scheduled message can be unscheduled.'], 422);
        }
        $m->forceFill(['scheduled_for' => null, 'next_attempt_at' => null])->save();
        GtmAudit::record('gtm.outreach.updated', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['changed' => ['scheduled_for']]);

        return response()->json(['status' => 1, 'data' => ['message' => $m->fresh()]]);
    }

    /** Every attempt made to send a message, including refusals. */
    public function attempts(Request $request, int $id): JsonResponse
    {
        [$m, , $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }

        return response()->json(['status' => 1, 'data' => ['attempts' => DB::table('gtm_outreach_attempts')->where('sub_institute_id', $m->sub_institute_id)->where('message_id', $m->id)->orderBy('id')->get()]]);
    }

    /** @return Collection|JsonResponse the steps of one of this tenant's sequences, in order */
    private function steps(Request $request, string $key): mixed
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $steps = GtmOutreachMessage::where('sub_institute_id', $identity['sub_institute_id'])->where('sequence_key', $key)->orderBy('step')->get();

        return $steps->isEmpty() ? response()->json(['status' => 0, 'message' => 'Sequence not found'], 404) : $steps;
    }

    public function sequenceSubmit(Request $request, string $key): JsonResponse
    {
        $steps = $this->steps($request, $key);
        if ($steps instanceof JsonResponse) {
            return $steps;
        }
        $identity = $this->resolveApiIdentity($request);
        $n = 0;
        foreach ($steps->where('status', 'draft') as $m) {
            $contact = GtmContact::where('sub_institute_id', $m->sub_institute_id)->find($m->contact_id);
            if (! $contact || ! filter_var((string) $contact->email, FILTER_VALIDATE_EMAIL) || in_array($contact->status, OutreachMailer::BLOCKED_CONTACT, true) || app(Suppressions::class)->blocking((int) $m->sub_institute_id, $contact->email)) {
                return response()->json(['status' => 0, 'code' => 'not_sendable', 'message' => 'The contact has no valid email or cannot be emailed (blocked or suppressed), so the sequence cannot be submitted.'], 422);
            }
            $m->forceFill(['status' => 'pending_approval', 'submitted_at' => now()])->save();
            GtmAudit::record('gtm.outreach.submitted', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['sequence' => $key]);
            $n++;
        }

        return response()->json(['status' => 1, 'data' => ['submitted' => $n, 'steps' => $steps->fresh()]]);
    }

    /**
     * Approve a whole sequence in one confirmed action. With `schedule: true` each step gets a send time: the first at
     * `start_at` (default now) and each later one at the same distance after it that its due date is after the first's.
     * Every step is approved with its OWN pinned hash and still has to pass every gate when it is sent.
     */
    public function sequenceApprove(Request $request, string $key): JsonResponse
    {
        $steps = $this->steps($request, $key);
        if ($steps instanceof JsonResponse) {
            return $steps;
        }
        $identity = $this->resolveApiIdentity($request);
        if (! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => 'Approving a sequence approves every step\'s exact text. Confirm to continue.'], 409);
        }
        if ($steps->contains(fn ($m) => in_array($m->status, ['draft', 'rejected'], true))) {
            return response()->json(['status' => 0, 'code' => 'steps_not_submitted', 'message' => 'Submit every step for approval first.'], 422);
        }
        if (config('gtm.outreach.require_second_approver') && $steps->contains(fn ($m) => (int) $m->created_by === (int) $identity['user_id'])) {
            return response()->json(['status' => 0, 'code' => 'second_approver_required', 'message' => 'A different administrator must approve a sequence you wrote.'], 403);
        }
        $start = null;
        if ($request->boolean('schedule')) {
            $start = $this->sendAt($request->merge(['send_at' => $request->input('start_at', now()->toDateTimeString())]));
            if ($start instanceof JsonResponse) {
                return $start;
            }
        }
        $first = $steps->first()->due_at;
        $approved = 0;
        foreach ($steps->where('status', 'pending_approval') as $m) {
            $at = $start ? $start->copy()->addSeconds(max(0, $first && $m->due_at ? (int) $first->diffInSeconds($m->due_at, false) : 0)) : null;
            if ($at && $at->gt(now()->addDays(90))) {
                $at = null; // beyond the horizon: approved, but a person schedules it later
            }
            $this->markApproved($m, $identity, $request->input('note'), $at);
            $approved++;
        }

        return response()->json(['status' => 1, 'data' => ['approved' => $approved, 'steps' => GtmOutreachMessage::where('sub_institute_id', $identity['sub_institute_id'])->where('sequence_key', $key)->orderBy('step')->get()]]);
    }

    public function sequenceCancel(Request $request, string $key): JsonResponse
    {
        $steps = $this->steps($request, $key);
        if ($steps instanceof JsonResponse) {
            return $steps;
        }
        $identity = $this->resolveApiIdentity($request);
        $n = 0;
        foreach ($steps->whereNotIn('status', ['sent', 'sending', 'cancelled']) as $m) {
            $m->forceFill(['status' => 'cancelled', 'decided_by' => $identity['user_id'], 'scheduled_for' => null])->save();
            GtmAudit::record('gtm.outreach.cancelled', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['sequence' => $key]);
            $n++;
        }

        return response()->json(['status' => 1, 'data' => ['cancelled' => $n]]);
    }
    /**
     * A campaign an email may belong to: the organisation's own, still open, with the contact's account as a target.
     * Returns the id, or a 422 response saying exactly which rule failed.
     */
    private function campaignFor(int $tenant, int $campaignId, int $accountId): int|JsonResponse
    {
        $problem = app(OutreachDrafts::class)->campaignProblem($tenant, $campaignId, $accountId);

        return $problem ? response()->json(['status' => 0, 'code' => $problem['code'], 'message' => $problem['message']], 422) : $campaignId;
    }
    /** @return array{0: ?GtmOutreachMessage, 1: ?array, 2: ?JsonResponse} */
    private function load(Request $request, int $id): array
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return [null, null, $identity];
        }
        $m = GtmOutreachMessage::where('sub_institute_id', $identity['sub_institute_id'])->find($id);

        return $m ? [$m, $identity, null] : [null, $identity, response()->json(['status' => 0, 'message' => 'Message not found'], 404)];
    }

    private function invalid($validator): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    }
}
