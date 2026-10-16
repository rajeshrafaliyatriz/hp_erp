<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmAuditTrail;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Controller;
use App\Services\Crm\CrmOutreachMailer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Campaigns — the legacy CRM's Marketing module, last of the three target
 * types (Leads/Contacts/Organizations already exist; Campaigns links to all
 * three via one polymorphic pivot, `crm_campaign_targets`).
 *
 * Raw `DB::table()` query builder throughout, tenant-scoped by
 * `sub_institute_id` only, matching the Leads/Contacts/Organizations
 * controllers this one is modeled on.
 */
class CrmCampaignController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmAuditTrail;
    use HasCrmBulkActions;
    use HasCrmExport;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'campaign_type' => 'Type', 'campaign_status' => 'Status',
        'expected_revenue' => 'Expected Revenue', 'budget_cost' => 'Budget Cost',
        'actual_cost' => 'Actual Cost', 'sponsor' => 'Sponsor', 'target_audience' => 'Target Audience',
        'closing_date' => 'Closing Date', 'description' => 'Description',
        'assigned_to' => 'Assigned To (user id)',
    ];

    /** @var array<string, string> */
    private const TARGET_TABLES = [
        'lead' => 'crm_leads',
        'contact' => 'crm_contacts',
        'organization' => 'crm_organizations',
    ];

    /** Singular targetType -> crm_saved_views.module (that table's own plural convention). */
    private const TARGET_MODULES = [
        'lead' => 'leads',
        'contact' => 'contacts',
        'organization' => 'organizations',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_campaigns')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($campaignStatus = $request->input('campaign_status')) {
            $query->where('campaign_status', $campaignStatus);
        }

        $sortableColumns = ['name', 'campaign_type', 'campaign_status', 'closing_date', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Campaigns.',
            'data' => [
                'items' => $rows->map(fn ($row) => $this->resource($row))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Campaign.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), $this->validationRules());

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request);
        $data['campaign_no'] = 'CAM-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_campaigns')->insertGetId($data);
        $row = DB::table('crm_campaigns')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Campaign created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), $this->validationRules(forUpdate: true));

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_campaigns')->where('id', $id)->update($data);
        $this->recordCrmAudit('crm.campaign.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));
        $row = DB::table('crm_campaigns')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Campaign updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        DB::table('crm_campaigns')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.campaign.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Campaign moved to Recycle Bin.']);
    }

    protected function auditEntityType(): string
    {
        return 'crm_campaigns';
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_campaigns', $identity['sub_institute_id'], $identity['user_id'], 'Campaign');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_campaigns', $identity['sub_institute_id'], $identity['user_id'], 'Campaign');
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_campaigns')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'campaigns.csv');
    }

    /** CSV import. A missing/invalid owner rejects the row (no fallback) - same rule store() already applies. */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|array|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $created = 0;
        $results = [];

        foreach ($request->input('rows') as $index => $row) {
            $rowRequest = Request::create('/', 'POST', is_array($row) ? $row : []);
            $rowValidator = Validator::make($rowRequest->all(), $this->validationRules());

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['campaign_no'] = 'CAM-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_campaigns')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} campaign(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * Add one target (Lead/Contact/Organization) to this campaign.
     *
     * Idempotent: re-adding the same (campaign, type, id) is a no-op, never a
     * 500 from the `crm_campaign_targets_unique` constraint — a caller
     * re-submitting a bulk-add or double-clicking "Add" must see success, not
     * an error for something that already happened.
     */
    public function addTarget(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'targetType' => 'required|in:lead,contact,organization',
            'targetId' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $targetType = $request->input('targetType');
        $targetId = (int) $request->input('targetId');

        if (! $this->targetExists($identity['sub_institute_id'], $targetType, $targetId)) {
            return response()->json(['status' => 0, 'message' => ucfirst($targetType) . ' not found.'], 422);
        }

        $this->insertTargetIdempotent($id, $targetType, $targetId);

        return response()->json([
            'status' => 1,
            'message' => 'Target added.',
            'data' => $this->targetsPayload($identity['sub_institute_id'], $id),
        ]);
    }

    public function removeTarget(Request $request, int $id, int $targetRowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        DB::table('crm_campaign_targets')
            ->where('id', $targetRowId)
            ->where('campaign_id', $id)
            ->delete();

        return response()->json(['status' => 1, 'message' => 'Target removed.']);
    }

    public function updateTargetStatus(Request $request, int $id, int $targetRowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'responseStatus' => 'required|in:none,contacted_successful,contacted_unsuccessful,contacted_never_again',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('crm_campaign_targets')
            ->where('id', $targetRowId)
            ->where('campaign_id', $id)
            ->update(['response_status' => $request->input('responseStatus')]);

        return response()->json(['status' => 1, 'message' => 'Target status updated.']);
    }

    public function getTargets(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Campaign targets.',
            'data' => $this->targetsPayload($identity['sub_institute_id'], $id),
        ]);
    }

    /**
     * Add every Lead/Contact/Organization matching a search term as a
     * target, in one batch. Reuses the same idempotent-insert path as
     * addTarget() per row, so re-running the same search is always safe.
     */
    public function bulkAddFromSearch(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'targetType' => 'required|in:lead,contact,organization',
            'search' => 'nullable|string|max:191',
            'savedViewId' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $targetType = $request->input('targetType');
        $search = trim((string) $request->input('search', ''));

        /*
         * A saved view (built for the list screens - search/sort only, see
         * crm_saved_views) overrides the free-text search when named - its
         * own stored search term IS the filter criterion here. sortKey/
         * sortAsc are meaningless for "which rows to add as targets", so
         * only the search condition is read out of it.
         */
        if ($request->filled('savedViewId')) {
            $module = self::TARGET_MODULES[$targetType];

            $savedView = DB::table('crm_saved_views')
                ->where('id', (int) $request->input('savedViewId'))
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->where('module', $module)
                ->first();

            if (! $savedView) {
                return response()->json(['status' => 0, 'message' => 'Saved view not found.'], 404);
            }

            $conditions = json_decode($savedView->conditions, true) ?? [];
            $search = trim((string) ($conditions['search'] ?? ''));
        }

        $added = 0;
        $alreadyPresent = 0;

        foreach ($this->searchTargetIds($identity['sub_institute_id'], $targetType, $search) as $targetId) {
            if ($this->insertTargetIdempotent($id, $targetType, $targetId)) {
                $added++;
            } else {
                $alreadyPresent++;
            }
        }

        return response()->json([
            'status' => 1,
            'message' => "{$added} target(s) added, {$alreadyPresent} already present.",
            'data' => [
                'added' => $added,
                'alreadyPresent' => $alreadyPresent,
            ],
        ]);
    }

    /** Current send state - how many of this campaign's targets have already received it, before committing to another send. */
    public function sendSummary(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $tenantId = $identity['sub_institute_id'];
        $totalTargets = DB::table('crm_campaign_targets')->where('campaign_id', $id)->count();
        $sentCount = DB::table('crm_emails')->where('campaign_id', $id)->where('sub_institute_id', $tenantId)->where('status', 'Sent')->count();
        // "handled" = send() will skip this target on the next call - Queued/Sending count too
        // (not just Sent), the same rule send()'s own idempotency check uses, so this preview
        // and the real send can never disagree about what "remaining" means.
        $handledCount = DB::table('crm_emails')->where('campaign_id', $id)->where('sub_institute_id', $tenantId)->whereIn('status', ['Queued', 'Sending', 'Sent'])->count();
        $lastSentAt = DB::table('crm_emails')->where('campaign_id', $id)->where('sub_institute_id', $tenantId)->where('status', 'Sent')->max('sent_at');

        return response()->json(['status' => 1, 'message' => 'Campaign send summary.', 'data' => [
            'totalTargets' => $totalTargets,
            'sentCount' => $sentCount,
            'pendingCount' => max(0, $handledCount - $sentCount),
            'remainingCount' => max(0, $totalTargets - $handledCount),
            'lastSentAt' => $lastSentAt,
            'sendingEnabled' => (bool) config('crm.email_sending_enabled'),
        ]]);
    }

    /**
     * Sends one message to every target on this campaign - the "actually
     * send a marketing email" capability the gap analysis found entirely
     * absent (Campaigns was a tag/ROI-tracking list only). Reuses
     * CrmOutreachMailer unmodified: every safety gate that already governs
     * a single compose() send (master switch, MailGate, unsubscribe
     * reachability, recipient opt-out, the one shared daily cap) applies
     * here exactly the same way, per recipient, with nothing new to
     * circumvent them. The only new logic is which recipients to loop over
     * and not sending the same campaign to the same target twice - see this
     * controller's own `send()`-adjacent docblocks and the crm_emails.
     * campaign_id migration's own docblock for why that needed a real
     * column, not a guess.
     *
     * Bounded to `config('crm.campaign_send_batch_limit')` NEW sends per
     * call (already-sent targets don't count against it) - large campaigns
     * need a second "Send" click to finish, reported plainly in the
     * response, never silently partial.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'templateId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = $identity['sub_institute_id'];

        if ($request->filled('templateId')) {
            $templateExists = DB::table('crm_email_templates')
                ->where('id', (int) $request->input('templateId'))
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $templateExists) {
                return response()->json(['status' => 0, 'message' => 'Email template not found.'], 422);
            }
        }

        $targets = DB::table('crm_campaign_targets')->where('campaign_id', $id)->get(['target_type', 'target_id']);

        if ($targets->isEmpty()) {
            return response()->json(['status' => 0, 'message' => 'This campaign has no targets yet. Add targets first.'], 422);
        }

        // One query for every (linked_type, linked_id) this campaign has ALREADY queued, is
        // sending, or has sent - not per-target, so this scales with campaigns, not targets.
        // Deliberately not status='Sent' only: while config('crm.email_sending_enabled') is
        // off (the default), nothing ever reaches Sent, so a Sent-only check would let every
        // re-click of "Send" queue a fresh duplicate row per recipient - harmless while
        // sending stays off, but each of those stale duplicates would actually fire the
        // moment it's turned on and this is called again. Only a genuinely Failed row (a real
        // delivery attempt that didn't work) is left eligible for a retry.
        $alreadyHandled = DB::table('crm_emails as e')
            ->join('crm_email_links as l', 'l.email_id', '=', 'e.id')
            ->where('e.campaign_id', $id)->where('e.sub_institute_id', $tenantId)->whereIn('e.status', ['Queued', 'Sending', 'Sent'])
            ->get(['l.linked_type', 'l.linked_id'])
            ->map(fn ($row) => strtoupper($row->linked_type).':'.$row->linked_id)
            ->flip();

        $batchLimit = max(1, (int) config('crm.campaign_send_batch_limit'));
        $mailer = new CrmOutreachMailer();
        $counts = ['sent' => 0, 'notSent' => 0, 'failed' => 0, 'alreadyHandled' => 0, 'skippedNoEmail' => 0, 'skippedOptedOut' => 0];
        $processed = 0;
        $truncated = false;

        foreach ($targets as $target) {
            $linkedType = strtoupper($target->target_type);
            $key = "{$linkedType}:{$target->target_id}";

            if ($alreadyHandled->has($key)) {
                $counts['alreadyHandled']++;

                continue;
            }

            if ($processed >= $batchLimit) {
                $truncated = true;

                continue;
            }

            $contact = $this->targetContactInfo($target->target_type, (int) $target->target_id, $tenantId);

            if (! $contact || empty($contact['email']) || ! filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
                $counts['skippedNoEmail']++;

                continue;
            }

            if ($contact['optOut']) {
                $counts['skippedOptedOut']++;

                continue;
            }

            $processed++;

            $emailId = DB::table('crm_emails')->insertGetId([
                'subject' => $request->input('subject'),
                'body' => $request->input('body'),
                'to_address' => $contact['email'],
                'template_id' => $request->filled('templateId') ? (int) $request->input('templateId') : null,
                'campaign_id' => $id,
                'status' => 'Queued',
                'created_by' => $identity['user_id'],
                'sub_institute_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('crm_email_links')->insert([
                'email_id' => $emailId,
                'linked_type' => $linkedType,
                'linked_id' => (int) $target->target_id,
                'sub_institute_id' => $tenantId,
                'created_by' => $identity['user_id'],
                'created_at' => now(),
            ]);

            if (! config('crm.email_sending_enabled')) {
                $counts['notSent']++;

                continue;
            }

            $row = DB::table('crm_emails')->where('id', $emailId)->first();
            $result = $mailer->send($row, $identity['user_id'], $linkedType, (int) $target->target_id);

            if ($result['ok']) {
                $counts['sent']++;
            } elseif ($result['code'] === 'send_failed') {
                $counts['failed']++;
            } else {
                // mail_disabled / unsubscribe_unreachable / mailer_not_delivering / daily_cap /
                // already_claimed - the row stays Queued either way, genuinely re-sendable.
                $counts['notSent']++;
            }
        }

        $message = $counts['sent'] > 0
            ? "Sent to {$counts['sent']} recipient(s)."
            : 'Nothing was actually sent this time - see the breakdown for why.';

        if ($truncated) {
            $message .= ' More targets remain - click Send again to continue.';
        }

        // `num_sent` already existed as a plain editable field on this entity (legacy
        // vtiger's own column) - nothing ever kept it accurate before this feature existed,
        // since there was nothing real to count. Recomputed from the real ledger every time,
        // never incremented, so it can't drift the way a running counter could.
        DB::table('crm_campaigns')->where('id', $id)->update([
            'num_sent' => DB::table('crm_emails')->where('campaign_id', $id)->where('sub_institute_id', $tenantId)->where('status', 'Sent')->count(),
        ]);

        $this->recordCrmAudit('crm.campaign.sent', $tenantId, $this->auditEntityType(), $id, $identity['user_id'], [], ['sent' => $counts['sent'], 'subject' => $request->input('subject')]);

        return response()->json(['status' => 1, 'message' => $message, 'data' => array_merge($counts, [
            'totalTargets' => $targets->count(),
            'truncated' => $truncated,
            'batchLimit' => $batchLimit,
        ])]);
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_campaigns')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function targetExists(int $tenantId, string $targetType, int $targetId): bool
    {
        $table = self::TARGET_TABLES[$targetType];

        return DB::table($table)
            ->where('id', $targetId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * @return array{email: ?string, optOut: bool}|null null when the target row itself no longer exists.
     *
     * Lead/Contact/Organization all carry their own `email`/`email_opt_out` columns directly
     * (confirmed via SHOW COLUMNS before writing this - Organizations are not an exception),
     * so one shared lookup covers every target_type send() loops over.
     */
    private function targetContactInfo(string $targetType, int $targetId, int $tenantId): ?array
    {
        $table = self::TARGET_TABLES[$targetType];
        $row = DB::table($table)->where('id', $targetId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->first(['email', 'email_opt_out']);

        return $row ? ['email' => $row->email, 'optOut' => (bool) $row->email_opt_out] : null;
    }

    /**
     * Insert one (campaign_id, target_type, target_id) row if it is not
     * already there. Returns true when a new row was inserted, false when it
     * was already present (whether found up front, or discovered only via
     * the unique-constraint violation of a concurrent duplicate insert).
     */
    private function insertTargetIdempotent(int $campaignId, string $targetType, int $targetId): bool
    {
        $exists = DB::table('crm_campaign_targets')
            ->where('campaign_id', $campaignId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            DB::table('crm_campaign_targets')->insert([
                'campaign_id' => $campaignId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'response_status' => 'none',
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // 23000 = integrity constraint violation. Another request won the
            // race between our existence check above and this insert - that
            // is the same "already present" outcome, never a 500.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /** @return array<int, int> */
    private function searchTargetIds(int $tenantId, string $targetType, string $search): array
    {
        $table = self::TARGET_TABLES[$targetType];

        $query = DB::table($table)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at');

        if ($search !== '') {
            if ($targetType === 'lead') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('company', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            } elseif ($targetType === 'contact') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            } else {
                $query->where('name', 'like', "%{$search}%");
            }
        }

        return $query->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Every target row for this campaign, grouped by type and enriched with
     * the target's own display name - a broken reference (the underlying
     * Lead/Contact/Organization since soft-deleted) is filtered out rather
     * than shown blank.
     *
     * @return array{leads: array<int, array<string, mixed>>, contacts: array<int, array<string, mixed>>, organizations: array<int, array<string, mixed>>}
     */
    private function targetsPayload(int $tenantId, int $campaignId): array
    {
        $leads = DB::table('crm_campaign_targets as t')
            ->join('crm_leads as l', 'l.id', '=', 't.target_id')
            ->where('t.campaign_id', $campaignId)
            ->where('t.target_type', 'lead')
            ->where('l.sub_institute_id', $tenantId)
            ->whereNull('l.deleted_at')
            ->orderBy('t.id')
            ->get(['t.id as target_row_id', 't.target_id', 't.response_status', 'l.first_name', 'l.last_name', 'l.company'])
            ->map(fn ($row) => [
                'targetRowId' => (string) $row->target_row_id,
                'targetId' => (string) $row->target_id,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'subLabel' => $row->company,
                'responseStatus' => $row->response_status,
            ])
            ->all();

        $contacts = DB::table('crm_campaign_targets as t')
            ->join('crm_contacts as c', 'c.id', '=', 't.target_id')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('t.campaign_id', $campaignId)
            ->where('t.target_type', 'contact')
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->orderBy('t.id')
            ->get(['t.id as target_row_id', 't.target_id', 't.response_status', 'c.first_name', 'c.last_name', 'o.name as organization_name'])
            ->map(fn ($row) => [
                'targetRowId' => (string) $row->target_row_id,
                'targetId' => (string) $row->target_id,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'subLabel' => $row->organization_name,
                'responseStatus' => $row->response_status,
            ])
            ->all();

        $organizations = DB::table('crm_campaign_targets as t')
            ->join('crm_organizations as o', 'o.id', '=', 't.target_id')
            ->where('t.campaign_id', $campaignId)
            ->where('t.target_type', 'organization')
            ->where('o.sub_institute_id', $tenantId)
            ->whereNull('o.deleted_at')
            ->orderBy('t.id')
            ->get(['t.id as target_row_id', 't.target_id', 't.response_status', 'o.name'])
            ->map(fn ($row) => [
                'targetRowId' => (string) $row->target_row_id,
                'targetId' => (string) $row->target_id,
                'name' => $row->name,
                'subLabel' => null,
                'responseStatus' => $row->response_status,
            ])
            ->all();

        return ['leads' => $leads, 'contacts' => $contacts, 'organizations' => $organizations];
    }

    /** @return array<string, string> */
    private function validationRules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes|required' : 'required';

        return [
            'name' => "{$required}|string|max:191",
            'assignedTo' => "{$required}|integer",
            'campaignType' => 'nullable|string|max:100',
            'campaignStatus' => 'nullable|string|max:100',
            'expectedRevenue' => 'nullable|numeric',
            'budgetCost' => 'nullable|numeric',
            'actualCost' => 'nullable|numeric',
            'expectedResponse' => 'nullable|string|max:50',
            'numSent' => 'nullable|integer',
            'sponsor' => 'nullable|string|max:191',
            'targetAudience' => 'nullable|string|max:191',
            'targetSize' => 'nullable|integer',
            'expectedResponseCount' => 'nullable|integer',
            'expectedSalesCount' => 'nullable|integer',
            'actualResponseCount' => 'nullable|integer',
            'actualSalesCount' => 'nullable|integer',
            'expectedRoi' => 'nullable|numeric',
            'actualRoi' => 'nullable|numeric',
            'closingDate' => 'nullable|date',
            'productId' => 'nullable|integer',
            'description' => 'nullable|string',
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'campaignType' => 'campaign_type', 'campaignStatus' => 'campaign_status',
            'expectedRevenue' => 'expected_revenue', 'budgetCost' => 'budget_cost', 'actualCost' => 'actual_cost',
            'expectedResponse' => 'expected_response', 'numSent' => 'num_sent', 'sponsor' => 'sponsor',
            'targetAudience' => 'target_audience', 'targetSize' => 'target_size',
            'expectedResponseCount' => 'expected_response_count', 'expectedSalesCount' => 'expected_sales_count',
            'actualResponseCount' => 'actual_response_count', 'actualSalesCount' => 'actual_sales_count',
            'expectedRoi' => 'expected_roi', 'actualRoi' => 'actual_roi', 'closingDate' => 'closing_date',
            'productId' => 'product_id', 'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('product_id', $data) && $data['product_id'] === '') {
            $data['product_id'] = null;
        }

        if (array_key_exists('closing_date', $data) && $data['closing_date'] === '') {
            $data['closing_date'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'campaignNo' => $row->campaign_no,
            'name' => $row->name,
            'campaignType' => $row->campaign_type,
            'campaignStatus' => $row->campaign_status,
            'expectedRevenue' => $row->expected_revenue !== null ? (float) $row->expected_revenue : null,
            'budgetCost' => $row->budget_cost !== null ? (float) $row->budget_cost : null,
            'actualCost' => $row->actual_cost !== null ? (float) $row->actual_cost : null,
            'expectedResponse' => $row->expected_response,
            'numSent' => $row->num_sent !== null ? (int) $row->num_sent : null,
            'sponsor' => $row->sponsor,
            'targetAudience' => $row->target_audience,
            'targetSize' => $row->target_size !== null ? (int) $row->target_size : null,
            'expectedResponseCount' => $row->expected_response_count !== null ? (int) $row->expected_response_count : null,
            'expectedSalesCount' => $row->expected_sales_count !== null ? (int) $row->expected_sales_count : null,
            'actualResponseCount' => $row->actual_response_count !== null ? (int) $row->actual_response_count : null,
            'actualSalesCount' => $row->actual_sales_count !== null ? (int) $row->actual_sales_count : null,
            'expectedRoi' => $row->expected_roi !== null ? (float) $row->expected_roi : null,
            'actualRoi' => $row->actual_roi !== null ? (float) $row->actual_roi : null,
            'closingDate' => $row->closing_date,
            'productId' => $row->product_id ? (string) $row->product_id : null,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
