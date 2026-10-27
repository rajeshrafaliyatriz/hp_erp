<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;

/**
 * Creates a CRM email exactly the way CrmEmailController::compose() does - a `crm_emails` row (status Queued) plus its
 * `crm_email_links` row, in one transaction - for callers that are not an HTTP request (the lead follow-up cadence).
 *
 * It can NEVER send on its own. Sending is CrmOutreachMailer::send(), and only when config('crm.email_sending_enabled') is on;
 * that class re-checks every gate (platform mail gate, unsubscribe reachability, opt-out, daily cap). With sending off the
 * row simply stays Queued, which is a correct outcome, not a failure.
 */
final class CrmEmailQueue
{
    /** @param list<array{0: string, 1: int}> $extraLinks further records the same email is shown on (type, id) @return int the new crm_emails.id */
    public static function create(int $tenant, string $linkedType, int $linkedId, string $to, string $subject, string $body, ?int $assignedTo, ?int $createdBy, array $extraLinks = []): int
    {
        return DB::transaction(function () use ($tenant, $linkedType, $linkedId, $to, $subject, $body, $assignedTo, $createdBy, $extraLinks) {
            $id = (int) DB::table('crm_emails')->insertGetId([
                'subject' => mb_substr($subject, 0, 255), 'body' => $body, 'to_address' => $to, 'template_id' => null, 'status' => 'Queued',
                'assigned_to' => $assignedTo, 'created_by' => $createdBy, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('crm_email_links')->insert([
                'email_id' => $id, 'linked_type' => strtoupper($linkedType), 'linked_id' => $linkedId, 'sub_institute_id' => $tenant, 'created_by' => $createdBy, 'created_at' => now(),
            ]);
            foreach ($extraLinks as [$type, $linkId]) {
                DB::table('crm_email_links')->insert(['email_id' => $id, 'linked_type' => strtoupper($type), 'linked_id' => $linkId, 'sub_institute_id' => $tenant, 'created_by' => $createdBy, 'created_at' => now()]);
            }

            return $id;
        });
    }

    /**
     * Hand a Queued row to the mailer when sending is switched on; otherwise report why not, without touching it.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public static function deliver(int $emailId, string $linkedType, int $linkedId, ?int $actorId): array
    {
        if (! config('crm.email_sending_enabled')) {
            return ['ok' => false, 'code' => 'sending_disabled', 'message' => 'CRM email sending is switched off (CRM_EMAIL_SENDING_ENABLED), so the email stays Queued.'];
        }
        $row = DB::table('crm_emails')->find($emailId);
        if (! $row) {
            return ['ok' => false, 'code' => 'missing', 'message' => 'The email no longer exists.'];
        }
        $r = (new CrmOutreachMailer())->send($row, $actorId, strtoupper($linkedType), $linkedId);

        return ['ok' => $r['ok'], 'code' => $r['code'], 'message' => $r['message']];
    }
}
