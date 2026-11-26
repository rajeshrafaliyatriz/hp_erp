<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use App\Domain\Crm\Contacts\CompanyPeople;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lets ANY of this phase's 5 "hot" entities (Lead/Contact/Organization/
 * Opportunity/Ticket) show the CRM Emails composed/sent against them on
 * their own detail page, by joining the generic `crm_email_links` pivot
 * (see its own migration docblock - same polymorphic-pivot shape
 * `document_library_links`/HasCrmDocuments already use for Documents,
 * applied here to `crm_emails` instead) back to `crm_emails` itself.
 *
 * `crm_ticket_comments`/CrmTicketController's own getComments()/
 * addComment() are NOT touched or generalized by this trait - a CRM Email is
 * a different thing from a plain internal comment (it is a real send
 * attempt against an external address, gated by App\Services\Crm\
 * CrmOutreachMailer), so it gets its own join table rather than being
 * folded into the comment thread.
 *
 * Generic over 2 host methods, same convention every other trait in this
 * folder uses:
 *   `emailsLinkedType(): string` - the UPPERCASE SINGULAR constant for this
 *     controller's own entity ('LEAD', 'CONTACT', 'ORGANIZATION',
 *     'OPPORTUNITY', 'TICKET' - identical casing/values to
 *     HasCrmDocuments::documentsLinkedType()).
 *   `ownRow(int $tenantId, int $id): ?object` - the same tenant-scoped,
 *     not-soft-deleted single-row lookup update()/destroy() already use on
 *     every host controller. Already exists on Contact/Organization/
 *     Opportunity/Ticket; CrmLeadController has no such method yet (see this
 *     phase's integration report for the one extra method Lead alone needs -
 *     the Documents gap area's own HasCrmDocuments trait hit the exact same
 *     gap first).
 *
 * Composing/sending a new email is NOT part of this trait - that is
 * CrmEmailController::compose() (a standalone endpoint, not a per-entity
 * sub-route), because one compose action needs its own validation/sending
 * gate regardless of which entity's detail page it was opened from. This
 * trait is read-only: "what has been sent against this record so far."
 */
trait HasCrmEmailLinks
{
    /** GET {prefix}/{id}/emails */
    public function getEmails(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        // `include=people` (S18): hosts that opt in (the organisation) also get the e-mails linked to the people of the company; null for everyone else.
        $people = method_exists($this, 'companyPeopleFor') ? $this->companyPeopleFor($request, $identity['sub_institute_id'], $id) : null;

        $query = DB::table('crm_email_links as l')
            ->join('crm_emails as e', 'e.id', '=', 'l.email_id')
            ->leftJoin('crm_email_templates as t', 't.id', '=', 'e.template_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'e.created_by')
            ->where('l.sub_institute_id', $identity['sub_institute_id'])
            ->orderByDesc('e.created_at')
            ->select(
                'e.*', 't.name as template_name', 'l.linked_type as link_type', 'l.linked_id as link_id',
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"),
            );

        if ($people === null) {
            $query->where('l.linked_type', $this->emailsLinkedType())->where('l.linked_id', $id);
        } else {
            $query->where(function ($q) use ($id, $people) {
                $q->where(fn ($own) => $own->where('l.linked_type', $this->emailsLinkedType())->where('l.linked_id', $id));
                if ($people !== []) {
                    $q->orWhere(fn ($person) => $person->where('l.linked_type', 'CONTACT')->whereIn('l.linked_id', array_keys($people)));
                }
            });
        }

        $rows = $query->get();

        if ($people === null) {
            return response()->json([
                'status' => 1,
                'message' => 'Emails.',
                'data' => $rows->map(fn ($row) => $this->emailLinkResource($row))->all(),
            ]);
        }

        // A company row keeps its place and its default look; an e-mail linked to the company AND to a person is listed once, as the company's.
        $items = $rows->map(function ($row) use ($id, $people) {
            $own = strtoupper((string) $row->link_type) === $this->emailsLinkedType() && (int) $row->link_id === $id;

            return $this->emailLinkResource($row) + ($own ? ['personId' => null, 'personName' => null] : CompanyPeople::tag($people, $row->link_type, $row->link_id));
        })->all();

        return response()->json(['status' => 1, 'message' => 'Emails.', 'data' => CompanyPeople::onePerRecord($items)]);
    }

    /** @return array<string, mixed> */
    private function emailLinkResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'subject' => $row->subject,
            'body' => $row->body,
            'toAddress' => $row->to_address,
            'fromAddress' => $row->from_address ?? null,
            'direction' => $row->direction ?? 'outbound',
            'templateId' => $row->template_id ? (string) $row->template_id : null,
            'templateName' => $row->template_name ?? null,
            'campaignId' => $row->campaign_id ? (string) $row->campaign_id : null,
            'status' => $row->status,
            'sentAt' => $row->sent_at,
            'failureReason' => $row->failure_reason,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdByName' => ($row->created_by_name ?? '') !== '' ? $row->created_by_name : null,
            'createdAt' => $row->created_at,
        ];
    }
}
