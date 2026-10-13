<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Crm\CrmOutreachMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CRM Emails - legacy CRM's `Emails` module (confirmed built on
 * `vtiger_activity` there; here its own standalone `crm_emails` table - see
 * that migration's own docblock for why it carries no `related_*` columns
 * of its own, cross-linked instead via the generic `crm_email_links` pivot,
 * same polymorphic-pivot shape `document_library_links` already plays for
 * Documents).
 *
 * `index()`/`show()` are this feature's own cross-entity log (optionally
 * filtered by linkedType+linkedId) - the per-record "Emails" tab on a Lead/
 * Contact/Organization/Opportunity/Ticket detail page instead calls
 * `GET {prefix}/{id}/emails`, generalized by Concerns\HasCrmEmailLinks (NOT
 * this controller) so each of those 5 hot controllers can `use` it without
 * depending on this class.
 *
 * `compose()` is the ONE place a CRM Email is created, and the ONE place
 * App\Services\Crm\CrmOutreachMailer::send() is ever called from. Real
 * dispatch is gated by config('crm.email_sending_enabled') (default false,
 * see config/crm.php's own docblock for why) - a hard requirement, not a
 * suggestion: with the flag off, this still fully validates the request and
 * writes the crm_emails/crm_email_links rows (status 'Queued'), it simply
 * never reaches CrmOutreachMailer::send().
 *
 * `unsubscribe()` is the public, signed-link landing page CrmOutreachMailer
 * puts in every real send (route `crm.email.unsubscribe`, same
 * `['signed', 'throttle:30,1']` shape as the existing `gtm.unsubscribe`
 * route) - no auth, no `resolveApiIdentity()`, by design: the person
 * clicking it is an external recipient, not a logged-in user of this app.
 */
class CrmEmailController extends Controller
{
    use ResolvesApiIdentity;

    /** @var array<string, string> linkedType => table, for validating a compose()'s own linkedId before it is ever written. */
    private const LINKED_TABLES = [
        'LEAD' => 'crm_leads',
        'CONTACT' => 'crm_contacts',
        'ORGANIZATION' => 'crm_organizations',
        'OPPORTUNITY' => 'crm_opportunities',
        'TICKET' => 'crm_tickets',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = $this->baseQuery($identity['sub_institute_id']);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('e.subject', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('e.status', $status);
        }

        if ($request->filled('linked_type') && $request->filled('linked_id')) {
            $linkedType = strtoupper((string) $request->input('linked_type'));
            $linkedId = (int) $request->input('linked_id');
            $query->whereExists(function ($q) use ($linkedType, $linkedId) {
                $q->selectRaw('1')->from('crm_email_links as l')
                    ->whereColumn('l.email_id', 'e.id')
                    ->where('l.linked_type', $linkedType)
                    ->where('l.linked_id', $linkedId);
            });
        }

        $sortableColumns = ['subject', 'status', 'created_at', 'sent_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'asc' ? 'asc' : 'desc';

        $total = (clone $query)->count();
        $rows = $query->orderBy('e.' . $sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Emails.',
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

        $row = $this->baseQuery($identity['sub_institute_id'])->where('e.id', $id)->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Email not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Email.', 'data' => $this->resource($row)]);
    }

    /**
     * Creates the crm_emails row + its crm_email_links row, then - ONLY if
     * config('crm.email_sending_enabled') is true - hands it to
     * CrmOutreachMailer::send(). See this class's own docblock for why the
     * row is written either way.
     */
    public function compose(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'toAddress' => 'required|email|max:191',
            'templateId' => 'nullable|integer',
            'assignedTo' => 'nullable|integer',
            'linkedType' => 'required|string|in:' . implode(',', array_keys(self::LINKED_TABLES)),
            'linkedId' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $linkedType = strtoupper((string) $request->input('linkedType'));
        $linkedId = (int) $request->input('linkedId');

        $linkedExists = DB::table(self::LINKED_TABLES[$linkedType])
            ->where('id', $linkedId)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->exists();

        if (! $linkedExists) {
            return response()->json(['status' => 0, 'message' => 'That record was not found.'], 422);
        }

        if ($request->filled('templateId')) {
            $templateExists = DB::table('crm_email_templates')
                ->where('id', (int) $request->input('templateId'))
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $templateExists) {
                return response()->json(['status' => 0, 'message' => 'Email template not found.'], 422);
            }
        }

        $emailId = DB::transaction(function () use ($request, $identity, $linkedType, $linkedId) {
            $emailId = DB::table('crm_emails')->insertGetId([
                'subject' => $request->input('subject'),
                'body' => $request->input('body'),
                'to_address' => $request->input('toAddress'),
                'template_id' => $request->filled('templateId') ? (int) $request->input('templateId') : null,
                'status' => 'Queued',
                'assigned_to' => $request->filled('assignedTo') ? (int) $request->input('assignedTo') : null,
                'created_by' => $identity['user_id'],
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('crm_email_links')->insert([
                'email_id' => $emailId,
                'linked_type' => $linkedType,
                'linked_id' => $linkedId,
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => now(),
            ]);

            return $emailId;
        });

        $message = 'Email queued.';

        // The hard gate: real dispatch happens ONLY here, and ONLY when the
        // flag is true. With it false (the default), nothing below this
        // line ever runs - the row stays exactly 'Queued', which is success,
        // not a partial failure.
        if (config('crm.email_sending_enabled')) {
            $row = DB::table('crm_emails')->where('id', $emailId)->first();
            $result = (new CrmOutreachMailer())->send($row, $identity['user_id'], $linkedType, $linkedId);
            $message = $result['message'];
        }

        $row = $this->baseQuery($identity['sub_institute_id'])->where('e.id', $emailId)->first();

        return response()->json(['status' => 1, 'message' => $message, 'data' => $this->resource($row)], 201);
    }

    /**
     * Public, signed landing page a real send's unsubscribe link points at -
     * no `resolveApiIdentity()`, the caller is an external recipient. Flips
     * `email_opt_out` on whichever LEAD/CONTACT/ORGANIZATION row the link
     * names, or - for an OPPORTUNITY/TICKET link - on that record's own
     * parent organization (Opportunity; crm_opportunities has no contact_id
     * column) or parent contact/organization in that priority order (Ticket)
     * (same resolution CrmOutreachMailer::recipientBlocked() uses before a
     * send).
     */
    public function unsubscribe(Request $request, string $type, int $id)
    {
        if (! $request->hasValidSignature()) {
            return response('<!doctype html><html><body style="font-family:sans-serif;padding:2rem;text-align:center">'
                .'<h2>This unsubscribe link is invalid or has expired.</h2></body></html>', 403, ['Content-Type' => 'text/html']);
        }

        $target = $this->resolveOptOutTarget(strtoupper($type), $id);

        if ($target) {
            DB::table($target['table'])->where('id', $target['id'])->update(['email_opt_out' => 1, 'updated_at' => now()]);
        }

        return response('<!doctype html><html><body style="font-family:sans-serif;padding:2rem;text-align:center">'
            .'<h2>You have been unsubscribed.</h2><p>You will no longer receive emails from this record.</p></body></html>', 200, ['Content-Type' => 'text/html']);
    }

    /** @return array{table: string, id: int}|null */
    private function resolveOptOutTarget(string $type, int $id): ?array
    {
        return match ($type) {
            'LEAD' => ['table' => 'crm_leads', 'id' => $id],
            'CONTACT' => ['table' => 'crm_contacts', 'id' => $id],
            'ORGANIZATION' => ['table' => 'crm_organizations', 'id' => $id],
            'OPPORTUNITY' => $this->parentOptOutTarget('crm_opportunities', $id, ['organization_id' => 'crm_organizations']),
            'TICKET' => $this->parentOptOutTarget('crm_tickets', $id, ['contact_id' => 'crm_contacts', 'organization_id' => 'crm_organizations']),
            default => null,
        };
    }

    /** @param array<string, string> $parentColumns own column => parent table, checked in priority order. */
    private function parentOptOutTarget(string $table, int $id, array $parentColumns): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        if (! $row) {
            return null;
        }

        foreach ($parentColumns as $column => $parentTable) {
            if (! empty($row->{$column})) {
                return ['table' => $parentTable, 'id' => (int) $row->{$column}];
            }
        }

        return null;
    }

    private function baseQuery(int $tenantId)
    {
        return DB::table('crm_emails as e')
            ->leftJoin('crm_email_templates as t', 't.id', '=', 'e.template_id')
            ->where('e.sub_institute_id', $tenantId)
            ->select('e.*', 't.name as template_name');
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'legacyId' => $row->legacy_id !== null ? (string) $row->legacy_id : null,
            'subject' => $row->subject,
            'body' => $row->body,
            'toAddress' => $row->to_address,
            'templateId' => $row->template_id ? (string) $row->template_id : null,
            'templateName' => $row->template_name ?? null,
            'status' => $row->status,
            'sentAt' => $row->sent_at,
            'failureReason' => $row->failure_reason,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
