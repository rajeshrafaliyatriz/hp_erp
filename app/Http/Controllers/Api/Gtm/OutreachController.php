<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\GtmOutreachMessage;
use App\Domain\Gtm\GtmPlaybook;
use App\Domain\Gtm\OutreachMailer;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

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
            'contact_id' => 'required|integer', 'subject' => 'required|string|max:255', 'body' => 'required|string|min:20|max:20000', 'deal_id' => 'nullable|integer',
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
        if ($request->filled('deal_id') && ! DB::table('gtm_deals')->where('sub_institute_id', $t)->where('id', (int) $request->input('deal_id'))->where('account_id', $contact->account_id)->exists()) {
            return response()->json(['status' => 0, 'message' => 'That deal does not belong to this contact\'s account.'], 422);
        }

        $m = GtmOutreachMessage::create([
            'sub_institute_id' => $t, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'deal_id' => $request->input('deal_id'),
            'subject' => trim((string) $request->input('subject')), 'body' => (string) $request->input('body'), 'status' => 'draft', 'created_by' => $identity['user_id'],
        ]);
        GtmAudit::record('gtm.outreach.created', $t, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['contact_id' => $contact->id, 'source' => 'manual']);

        return response()->json(['status' => 1, 'data' => ['message' => $m]], 201);
    }

    /** Save an Outreach Agent result (one email, or every step of a sequence) as drafts. */
    public function fromAnalysis(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $v = Validator::make($request->all(), ['analysis_id' => 'required|integer']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $analysis = DB::table('gtm_analyses')->where('sub_institute_id', $t)->where('id', (int) $request->input('analysis_id'))->where('kind', 'outreach_draft')->where('status', 'success')->first();
        if (! $analysis) {
            return response()->json(['status' => 0, 'message' => 'Draft not found.'], 404);
        }
        if (GtmOutreachMessage::where('sub_institute_id', $t)->where('analysis_id', $analysis->id)->exists()) {
            return response()->json(['status' => 0, 'code' => 'already_saved', 'message' => 'This draft was already saved.'], 409);
        }
        $result = json_decode($analysis->result ?? 'null', true) ?: [];
        $contact = GtmContact::where('sub_institute_id', $t)->find((int) ($result['recipient']['contact_id'] ?? $analysis->subject_id));
        if (! $contact) {
            return response()->json(['status' => 0, 'message' => 'The contact for this draft no longer exists.'], 422);
        }
        if (in_array($contact->status, OutreachMailer::BLOCKED_CONTACT, true)) {
            return response()->json(['status' => 0, 'code' => 'contact_blocked', 'message' => "{$contact->full_name} is marked '{$contact->status}'. Nothing was saved."], 422);
        }

        $steps = isset($result['steps']) && is_array($result['steps'])
            ? array_values(array_filter($result['steps'], fn ($s) => is_array($s) && trim((string) ($s['body'] ?? '')) !== ''))
            : [['subject' => $result['subject'] ?? '', 'body' => $result['body'] ?? '']];
        if ($steps === [] || trim((string) ($steps[0]['body'] ?? '')) === '' || trim((string) ($steps[0]['subject'] ?? '')) === '') {
            return response()->json(['status' => 0, 'message' => 'The draft has no usable text.'], 422);
        }

        $sequence = count($steps) > 1;
        $waits = GtmPlaybook::where('slug', 'outreach-follow-up-sequence')->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $t))
            ->orderByRaw('sub_institute_id IS NULL')->first()?->definition['steps'] ?? [];
        $key = $sequence ? Str::lower(Str::random(24)) : null;

        $created = DB::transaction(function () use ($steps, $sequence, $key, $waits, $contact, $analysis, $t, $identity) {
            $due = now();
            $rows = [];
            foreach ($steps as $i => $s) {
                if ($i > 0) {
                    $due = $due->copy()->addDays((int) ($waits[$i]['wait_days'] ?? 3));
                }
                $rows[] = GtmOutreachMessage::create([
                    'sub_institute_id' => $t, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'analysis_id' => $analysis->id,
                    'sequence_key' => $key, 'step' => $i + 1, 'due_at' => $sequence ? $due : null,
                    'subject' => mb_substr(trim((string) ($s['subject'] ?? ($steps[0]['subject'] ?? ''))) ?: 'Following up', 0, 255), 'body' => (string) $s['body'],
                    'status' => 'draft', 'created_by' => $identity['user_id'],
                ]);
            }

            return $rows;
        });
        GtmAudit::record('gtm.outreach.created', $t, 'gtm_outreach_messages', $created[0]->id, $identity['user_id'], ['contact_id' => $contact->id, 'source' => 'agent', 'steps' => count($created), 'analysis_id' => $analysis->id]);

        return response()->json(['status' => 1, 'data' => ['messages' => $created]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$m, $identity, $err] = $this->load($request, $id);
        if ($err) {
            return $err;
        }
        if (! in_array($m->status, ['draft', 'rejected'], true)) {
            return response()->json(['status' => 0, 'code' => 'locked', 'message' => 'Only a draft or a rejected message can be edited. Approved text is locked so what was approved is what is sent.'], 422);
        }
        $v = Validator::make($request->all(), ['subject' => 'sometimes|string|max:255', 'body' => 'sometimes|string|min:20|max:20000']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $m->fill($v->validated());
        $m->status = 'draft';
        $m->save();
        GtmAudit::record('gtm.outreach.updated', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['changed' => array_keys($v->validated())]);

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
        $m->forceFill([
            'status' => 'approved', 'approved_by' => $identity['user_id'], 'approved_at' => now(), 'approved_hash' => $m->contentHash(),
            'decided_by' => $identity['user_id'], 'decision_note' => $request->input('note') ? mb_substr((string) $request->input('note'), 0, 500) : null,
        ])->save();
        GtmAudit::record('gtm.outreach.approved', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, $identity['user_id'], ['hash' => $m->approved_hash]);

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
