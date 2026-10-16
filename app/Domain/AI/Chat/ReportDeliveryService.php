<?php

namespace App\Domain\AI\Chat;

use App\Domain\AI\Support\AiAuditLogger;
use App\Services\Ai\AiRequestScope;
use App\Support\MailGate;
use App\Support\MailSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Sends a saved AI report to real people of the caller's organisation, by e-mail.
 *
 * MECHANISM REUSED (not invented)
 *   - Transport: Laravel `Mail` with the app's configured mailer (`Mail::html`, the same facade
 *     InviteService / NotificationSender use).
 *   - Gate: `App\Support\MailGate::allowedForTenant()` - THE single gate every outbound mail path
 *     passes through here (G2G_NOTIFY_EMAIL / G2G_NOTIFY_EMAIL_TENANTS). When the gate is closed
 *     the send is REFUSED up front with the gate's own reason; nothing is queued or faked.
 *   - Recipients: `tbluser` (same table NotificationSender reads the address from), tenant column
 *     `sub_institute_id`.
 *
 * AUTHORISATION  Administrators / platform owner only - the same rule as viewing or generating
 *   reports (ChatReportService, /ai/reports).
 *
 * IDEMPOTENCY (double click / retry)
 *   1. A recipient who already has a queued/sent delivery for the same report in the last
 *      DUPLICATE_WINDOW_SECONDS is reported `duplicate` and NOT mailed again.
 *   2. Before mailing, a row is claimed with a unique `dedupe_key` (client `request_key` +
 *      report + recipient when supplied, else report + recipient + time bucket), so two concurrent
 *      requests cannot both pass. A failed attempt clears its key so retrying is possible.
 *
 * Audit: `ai.report.sent` with counts only (never addresses or bodies).
 */
class ReportDeliveryService
{
    public const MAX_RECIPIENTS = 20;

    public const DUPLICATE_WINDOW_SECONDS = 60;

    public function __construct(private readonly AiAuditLogger $audit)
    {
    }

    /** Is the sending mechanism available to this caller (role, gate, table)? */
    public function canSend(AiRequestScope $scope): bool
    {
        return $this->mayManage($scope)
            && MailGate::allowedForTenant((int) $scope->selectedInstituteId)
            && Schema::hasTable('ai_report_deliveries');
    }

    /**
     * People of the organisation with the contact details the send dialog needs. A person with no
     * usable e-mail or mobile is still listed (that field null) so the dialog can say which method
     * is unavailable instead of silently hiding them. Read from `tbluser`; nothing is invented.
     *
     * @return array<int, array{id:int,name:string,email:?string,mobile:?string,role:?string,department:?string}>
     */
    public function recipients(AiRequestScope $scope, ?string $q, int $limit = 25): array
    {
        $this->authorise($scope);

        $query = DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->leftJoin('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.sub_institute_id', $scope->selectedInstituteId)
            ->where('u.status', 1)
            ->whereNull('u.deleted_at');

        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('u.first_name', 'like', $like)
                    ->orWhere('u.last_name', 'like', $like)
                    ->orWhere('u.email', 'like', $like)
                    ->orWhere('u.mobile', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) like ?", [$like]);
            });
        }

        $rows = $query->orderBy('u.first_name')->orderBy('u.last_name')
            ->limit(max(1, min(50, $limit)))
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.mobile', 'p.name as role', 'd.department']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'name' => $this->name($r),
                'email' => $this->cleanEmail($r->email),
                'mobile' => $this->cleanMobile($r->mobile),
                'role' => $r->role,
                'department' => $r->department,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,int|string>  $recipientIds
     * @return array{report_id:int,title:string,sent:int,failed:int,duplicate:int,results:array<int,array<string,mixed>>}
     */
    public function send(AiRequestScope $scope, int $reportId, array $recipientIds, ?string $note, ?string $requestKey): array
    {
        $this->authorise($scope);

        $tenant = (int) $scope->selectedInstituteId;

        if (! MailGate::allowedForTenant($tenant)) {
            $this->audit->recordRejection(MailGate::reasonForTenant($tenant) ?? MailGate::reason(), $scope, [
                'related_type' => 'ai_generated_reports', 'related_id' => $reportId,
                'message' => 'Report send refused: outbound mail disabled for this organisation.',
            ]);
            throw new RuntimeException(MailGate::reasonForTenant($tenant) ?? MailGate::reason());
        }

        if (! Schema::hasTable('ai_report_deliveries')) {
            throw new RuntimeException('Report delivery is not set up on this estate (ai_report_deliveries is missing).');
        }

        $report = $this->report($scope, $reportId);
        if ($report === null) {
            throw new NotFoundReport('That report was not found.');
        }

        $ids = array_values(array_unique(array_map('intval', $recipientIds)));
        if ($ids === [] || in_array(0, $ids, true)) {
            throw new RuntimeException('Choose at least one recipient.');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            throw new RuntimeException('At most ' . self::MAX_RECIPIENTS . ' recipients per send.');
        }

        // Tenant-scoped, active, with an e-mail. Anything else is rejected as a whole.
        $users = DB::table('tbluser')
            ->whereIn('id', $ids)
            ->where('sub_institute_id', $tenant)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereNotNull('email')->where('email', '!=', '')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->keyBy('id');

        $invalid = [];
        foreach ($ids as $id) {
            $u = $users->get($id);
            if ($u === null || ! filter_var($u->email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $id;
            }
        }
        if ($invalid !== []) {
            $this->audit->recordRejection('Report send refused: invalid recipients.', $scope, [
                'related_type' => 'ai_generated_reports', 'related_id' => $reportId,
                'payload' => ['invalid_count' => count($invalid)],
            ]);
            throw new RuntimeException(
                'Some recipients are not people of this organisation with an e-mail address (ids: ' . implode(', ', $invalid) . ').'
            );
        }

        $sender = $this->senderName($scope);
        $note = $note === null ? null : mb_substr(trim($note), 0, 500);
        $note = $note === '' ? null : $note;
        $html = $this->body((string) $report->title, (string) $report->html_content, $sender, $note);

        $results = [];
        $counts = ['sent' => 0, 'failed' => 0, 'duplicate' => 0];

        foreach ($ids as $id) {
            $u = $users->get($id);
            $name = $this->name($u);
            $email = (string) $u->email;
            $base = ['recipient_user_id' => $id, 'name' => $name, 'email' => $email];

            $recent = DB::table('ai_report_deliveries')
                ->where('report_id', $reportId)->where('sub_institute_id', $tenant)
                ->where('recipient_user_id', $id)->whereIn('status', ['queued', 'sent'])
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                ->exists();

            $key = $requestKey !== null && $requestKey !== ''
                ? sha1("req|$requestKey|$reportId|$id")
                : sha1("win|$tenant|$reportId|$id|" . intdiv(time(), self::DUPLICATE_WINDOW_SECONDS));

            $deliveryId = $recent ? 0 : (int) DB::table('ai_report_deliveries')->insertOrIgnore([
                'report_id' => $reportId, 'sub_institute_id' => $tenant, 'sent_by' => $scope->userId,
                'recipient_user_id' => $id, 'recipient_email' => $email, 'recipient_name' => mb_substr($name, 0, 190),
                'channel' => 'email', 'status' => 'queued', 'note' => $note, 'dedupe_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($recent || $deliveryId === 0) {
                $counts['duplicate']++;
                $results[] = $base + ['status' => 'duplicate', 'error' => 'Already sent to this person a moment ago; not sent again.'];
                continue;
            }

            $row = DB::table('ai_report_deliveries')->where('dedupe_key', $key)->first(['id']);

            try {
                MailSender::mailer($tenant, 'ai_reports')->html($html, function ($m) use ($email, $name, $report) {
                    $m->to($email, $name)->subject('Report: ' . $report->title);
                });
                DB::table('ai_report_deliveries')->where('id', $row->id)
                    ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
                $counts['sent']++;
                $results[] = $base + ['status' => 'sent', 'error' => null];
            } catch (Throwable $e) {
                // The transport's message is kept (it is what an operator needs); the dedupe key is
                // released so a retry is possible.
                DB::table('ai_report_deliveries')->where('id', $row->id)->update([
                    'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000),
                    'dedupe_key' => null, 'updated_at' => now(),
                ]);
                $counts['failed']++;
                $results[] = $base + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        $this->audit->record('ai.report.sent', $scope, [
            'related_type' => 'ai_generated_reports',
            'related_id' => $reportId,
            'outcome' => $counts['failed'] > 0 ? 'partial' : 'success',
            'message' => sprintf('Report "%s" sent: %d sent, %d failed, %d duplicate.', $report->title, $counts['sent'], $counts['failed'], $counts['duplicate']),
            'payload' => ['recipients' => count($ids)] + $counts,
        ]);

        return ['report_id' => $reportId, 'title' => (string) $report->title] + $counts + ['results' => $results];
    }

    /**
     * Send (SMS) or prepare (WhatsApp) a short message about a saved report to people's mobile
     * numbers, read from `tbluser` on the server - the client only names the people.
     *
     * SMS goes through the organisation's own gateway (TenantSmsGateway, the mechanism the CRM SMS
     * notifier uses). WhatsApp is NOT sent from here: this project has no WhatsApp Business API
     * (config/platform_services.php excludes it), so the server validates the number and returns a
     * `wa.me` click-to-chat link per person; the sender's own WhatsApp does the sending. Those
     * results have status `prepared`, never `sent`.
     *
     * @param  array<int,int|string>  $recipientIds
     * @return array{report_id:int,title:string,channel:string,sent:int,failed:int,duplicate:int,prepared:int,results:array<int,array<string,mixed>>}
     */
    public function sendToMobile(AiRequestScope $scope, int $reportId, array $recipientIds, string $channel, ?string $note, ?string $requestKey, ?string $reportUrl): array
    {
        $this->authorise($scope);

        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            throw new RuntimeException('Unknown delivery method.');
        }

        $tenant = (int) $scope->selectedInstituteId;
        $label = $channel === 'sms' ? 'SMS' : 'WhatsApp';

        $report = $this->report($scope, $reportId);
        if ($report === null) {
            throw new NotFoundReport('That report was not found.');
        }

        $ids = array_values(array_unique(array_map('intval', $recipientIds)));
        if ($ids === [] || in_array(0, $ids, true)) {
            throw new RuntimeException('Choose at least one recipient.');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            throw new RuntimeException('At most ' . self::MAX_RECIPIENTS . ' recipients per send.');
        }

        $users = DB::table('tbluser')
            ->whereIn('id', $ids)
            ->where('sub_institute_id', $tenant)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'email', 'mobile'])
            ->keyBy('id');

        // Refuse the whole request, naming who and why, before anything is sent.
        $problems = [];
        foreach ($ids as $id) {
            $u = $users->get($id);
            if ($u === null) {
                $problems[] = "person #$id is not an active member of this organisation";
            } elseif ($this->cleanMobile($u->mobile) === null) {
                $problems[] = $this->name($u) . ' has no valid mobile number on record';
            }
        }
        if ($problems !== []) {
            $this->audit->recordRejection("Report $label send refused: recipients without a usable number.", $scope, [
                'related_type' => 'ai_generated_reports', 'related_id' => $reportId,
                'payload' => ['invalid_count' => count($problems), 'channel' => $channel],
            ]);
            throw new RuntimeException("Cannot send by $label: " . implode('; ', $problems) . '.');
        }

        $gateway = null;
        if ($channel === 'sms') {
            $gateway = app(\App\Services\Sms\TenantSmsGateway::class)->resolve($tenant);
            if ($gateway === null) {
                throw new RuntimeException('No SMS gateway is configured for this organisation. Set one up under Settings → Integrations.');
            }
            if (! Schema::hasTable('ai_report_deliveries')) {
                throw new RuntimeException('Report delivery is not set up on this estate (ai_report_deliveries is missing).');
            }
        }

        // WhatsApp goes out through the organisation's own Cloud API account when it has one; with none,
        // the sender gets click-to-chat links instead (status `prepared`, nothing is sent by the server).
        $cloud = null;
        if ($channel === 'whatsapp') {
            $cloud = app(\App\Services\Whatsapp\TenantWhatsappCloud::class)->resolve($tenant);
            if ($cloud !== null && ! Schema::hasTable('ai_report_deliveries')) {
                throw new RuntimeException('Report delivery is not set up on this estate (ai_report_deliveries is missing).');
            }
        }

        $note = $note === null ? null : mb_substr(trim($note), 0, 500);
        $note = $note === '' ? null : $note;
        $sender = $this->senderName($scope);
        $link = $reportUrl !== null && preg_match('#^https?://#i', $reportUrl) ? $reportUrl : null;
        $message = $this->shortMessage($sender, (string) $report->title, $note, $link);

        $results = [];
        $counts = ['sent' => 0, 'failed' => 0, 'duplicate' => 0, 'prepared' => 0];

        foreach ($ids as $id) {
            $u = $users->get($id);
            $name = $this->name($u);
            $mobile = (string) $this->cleanMobile($u->mobile);
            $base = ['recipient_user_id' => $id, 'name' => $name, 'email' => $this->cleanEmail($u->email), 'mobile' => $mobile, 'channel' => $channel];

            if ($channel === 'whatsapp' && $cloud === null) {
                $counts['prepared']++;
                $results[] = $base + [
                    'status' => 'prepared',
                    'error' => null,
                    'link' => 'https://wa.me/' . $this->whatsappNumber($mobile) . '?text=' . rawurlencode($message),
                ];
                continue;
            }

            $recent = DB::table('ai_report_deliveries')
                ->where('report_id', $reportId)->where('sub_institute_id', $tenant)
                ->where('recipient_user_id', $id)->where('channel', $channel)->whereIn('status', ['queued', 'sent'])
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                ->exists();

            $key = $requestKey !== null && $requestKey !== ''
                ? sha1("req|$channel|$requestKey|$reportId|$id")
                : sha1("win|$channel|$tenant|$reportId|$id|" . intdiv(time(), self::DUPLICATE_WINDOW_SECONDS));

            // `recipient_email` is the address snapshot for the channel: the mobile number for SMS.
            $deliveryId = $recent ? 0 : (int) DB::table('ai_report_deliveries')->insertOrIgnore([
                'report_id' => $reportId, 'sub_institute_id' => $tenant, 'sent_by' => $scope->userId,
                'recipient_user_id' => $id, 'recipient_email' => $mobile, 'recipient_name' => mb_substr($name, 0, 190),
                'channel' => $channel, 'status' => 'queued', 'note' => $note, 'dedupe_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($recent || $deliveryId === 0) {
                $counts['duplicate']++;
                $results[] = $base + ['status' => 'duplicate', 'error' => 'Already sent to this person a moment ago; not sent again.'];
                continue;
            }

            $row = DB::table('ai_report_deliveries')->where('dedupe_key', $key)->first(['id']);
            $messageId = null;
            if ($channel === 'whatsapp') {
                $outcome = app(\App\Services\Whatsapp\TenantWhatsappCloud::class)->send($cloud, $this->whatsappNumber($mobile), $message);
                $status = $outcome['status'];
                $reason = $outcome['error'];
                $messageId = $outcome['id'];
            } else {
                [$status, $reason] = app(\App\Services\Sms\TenantSmsGateway::class)->dispatch($gateway, $mobile, $message);
            }

            if ($status === 'sent') {
                DB::table('ai_report_deliveries')->where('id', $row->id)
                    ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
                $counts['sent']++;
                $results[] = $base + ['status' => 'sent', 'error' => null] + ($messageId !== null ? ['message_id' => $messageId] : []);
            } else {
                DB::table('ai_report_deliveries')->where('id', $row->id)->update([
                    'status' => 'failed', 'error' => mb_substr((string) $reason, 0, 1000),
                    'dedupe_key' => null, 'updated_at' => now(),
                ]);
                $counts['failed']++;
                $results[] = $base + ['status' => 'failed', 'error' => mb_substr((string) $reason, 0, 300)];
            }
        }

        $this->audit->record('ai.report.sent', $scope, [
            'related_type' => 'ai_generated_reports',
            'related_id' => $reportId,
            'outcome' => $counts['failed'] > 0 ? 'partial' : 'success',
            'message' => sprintf('Report "%s" by %s: %d sent, %d prepared, %d failed, %d duplicate.', $report->title, $label, $counts['sent'], $counts['prepared'], $counts['failed'], $counts['duplicate']),
            'payload' => ['recipients' => count($ids), 'channel' => $channel] + $counts,
        ]);

        return ['report_id' => $reportId, 'title' => (string) $report->title, 'channel' => $channel] + $counts + ['results' => $results];
    }

    /**
     * The tenant's most recent saved reports for a module (and its child screens).
     *
     * @return array<int, array{id:int,title:string,module_key:string,row_count:int,created_at:string,url_path:string}>
     */
    public function recentReports(AiRequestScope $scope, string $moduleKey, int $limit = 15): array
    {
        $this->authorise($scope);

        if (! Schema::hasTable('ai_generated_reports')) {
            return [];
        }

        return DB::table('ai_generated_reports')
            ->where('sub_institute_id', $scope->selectedInstituteId)
            ->where('status', 1)
            ->whereIn('module_key', app(\App\Domain\AI\Modules\ModuleRollUp::class)->keysFor($moduleKey))
            ->orderByDesc('id')->limit(max(1, min(30, $limit)))
            ->get(['id', 'title', 'module_key', 'row_count', 'created_at'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'title' => (string) $r->title, 'module_key' => (string) $r->module_key,
                'row_count' => (int) $r->row_count, 'created_at' => (string) $r->created_at,
                'url_path' => '/ai/reports/' . $r->id,
            ])->all();
    }

    /** @return array<int, array<string,mixed>> */
    public function deliveries(AiRequestScope $scope, int $reportId): array
    {
        $this->authorise($scope);

        if ($this->report($scope, $reportId) === null) {
            throw new NotFoundReport('That report was not found.');
        }
        if (! Schema::hasTable('ai_report_deliveries')) {
            return [];
        }

        return DB::table('ai_report_deliveries')
            ->where('report_id', $reportId)
            ->where('sub_institute_id', $scope->selectedInstituteId)
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'recipient_user_id', 'recipient_name', 'recipient_email', 'channel', 'status', 'error', 'sent_by', 'sent_at', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    // ------------------------------------------------------------------ internals

    private function mayManage(AiRequestScope $scope): bool
    {
        return $scope->isAdmin || $scope->isPlatformOwner;
    }

    private function authorise(AiRequestScope $scope): void
    {
        if (! $this->mayManage($scope)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Sending reports is available to administrators.');
        }
    }

    private function report(AiRequestScope $scope, int $id): ?object
    {
        if (! Schema::hasTable('ai_generated_reports')) {
            return null;
        }

        return DB::table('ai_generated_reports')
            ->where('id', $id)->where('sub_institute_id', $scope->selectedInstituteId)->where('status', 1)
            ->first(['id', 'title', 'html_content']);
    }

    private function cleanEmail(mixed $value): ?string
    {
        $email = trim((string) $value);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Digits only (a leading + is kept out too); 7-15 digits or null. Stored format is otherwise left as is. */
    private function cleanMobile(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    /** wa.me needs the country code; a bare 10-digit number is read as Indian (+91), as this ERP's users are. */
    private function whatsappNumber(string $digits): string
    {
        return strlen($digits) === 10 ? '91' . $digits : ltrim($digits, '0');
    }

    private function shortMessage(string $sender, string $title, ?string $note, ?string $link): string
    {
        $text = $sender . ' shared the report "' . $title . '" with you.';
        if ($note !== null) {
            $text .= ' ' . $note;
        }
        if ($link !== null) {
            $text .= ' Open (sign-in required): ' . $link;
        }

        return mb_substr($text, 0, 900);
    }

    private function name(object $u): string
    {
        $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));

        return $n !== '' ? $n : (string) ($u->email ?? '');
    }

    private function senderName(AiRequestScope $scope): string
    {
        $u = DB::table('tbluser')->where('id', $scope->userId)->first(['first_name', 'last_name', 'email']);

        return $u === null ? 'An administrator' : $this->name($u);
    }

    private function body(string $title, string $reportHtml, string $sender, ?string $note): string
    {
        // Saved reports are editable HTML: drop active content before it goes into a mailbox.
        $clean = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $reportHtml) ?? '';
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
        $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1=$2#$2', $clean) ?? $clean;

        $head = '<p style="font-family:Arial,sans-serif;font-size:14px">' . e($sender) . ' shared the report <strong>' . e($title) . '</strong> with you.</p>';
        if ($note !== null) {
            $head .= '<blockquote style="font-family:Arial,sans-serif;font-size:14px;border-left:3px solid #ccc;margin:8px 0;padding:4px 12px">' . nl2br(e($note)) . '</blockquote>';
        }

        return '<!doctype html><html><body>' . $head . '<hr>' . $clean . '</body></html>';
    }
}
