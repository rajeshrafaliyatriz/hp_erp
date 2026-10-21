<?php

namespace App\Domain\Reports;

use App\Services\Sms\TenantSmsGateway;
use App\Services\Whatsapp\TenantWhatsappCloud;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Send this report by SMS and/or WhatsApp" for the Reports section (the report builder).
 *
 * The AI chat's report send (App\Domain\AI\Chat\ReportDeliveryService::sendToMobile) already does this for
 * saved AI reports; this is the same thing for `reports` rows, over the SAME two organisation services:
 *   - SMS       -> TenantSmsGateway   (the organisation's own gateway; "sent" = gateway accepted it)
 *   - WhatsApp  -> TenantWhatsappCloud (the organisation's own Cloud API account; "sent" = Meta accepted it)
 * Numbers are read from `tbluser` here on the server, scoped to the caller's organisation - the client only
 * names people. Each channel runs on its own, so one failing (no gateway, refused, no account) is reported with
 * its exact reason and never stops the other. WhatsApp with no connected account sends nothing: it returns a
 * click-to-chat link per person with status `prepared`.
 *
 * Nothing is persisted (the report builder has no delivery ledger and this adds no schema): a 60 s cache lock per
 * report/person/channel stops a double click sending twice.
 */
final class ReportMobileSender
{
    public const MAX_RECIPIENTS = 20;

    public const DUPLICATE_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly TenantSmsGateway $sms,
        private readonly TenantWhatsappCloud $whatsapp,
    ) {
    }

    /** Whether each channel can actually send for this organisation (drives the dialog's honest hints). */
    public function availability(int $tenant): array
    {
        return [
            'sms_configured' => $this->sms->resolve($tenant) !== null,
            'whatsapp_configured' => $this->whatsapp->resolve($tenant) !== null,
        ];
    }

    /**
     * People of the organisation, with only whether they have a usable number (never the number itself).
     *
     * @return array<int, array{id:int,name:string,has_mobile:bool,mobile_hint:?string}>
     */
    public function recipients(int $tenant, ?string $q, int $limit = 25): array
    {
        $query = DB::table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1)->whereNull('deleted_at');

        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) like ?", [$like]);
            });
        }

        return $query->orderBy('first_name')->orderBy('last_name')->limit(max(1, min(50, $limit)))
            ->get(['id', 'first_name', 'last_name', 'mobile'])
            ->map(function ($u) {
                $mobile = $this->cleanMobile($u->mobile);

                return [
                    'id' => (int) $u->id,
                    'name' => $this->name($u),
                    'has_mobile' => $mobile !== null,
                    'mobile_hint' => $mobile === null ? null : '••••' . substr($mobile, -4),
                ];
            })->all();
    }

    /**
     * @param  object  $report  A `reports` row the caller is already allowed to view.
     * @param  array<int,int|string>  $recipientIds
     * @param  string  $channel  sms | whatsapp | both
     * @return array{sent:int,failed:int,duplicate:int,prepared:int,results:array<int,array<string,mixed>>}
     */
    public function send(object $report, int $senderId, array $recipientIds, string $channel, ?string $note, ?string $requestKey, ?string $reportUrl): array
    {
        $tenant = (int) $report->sub_institute_id;
        $channels = $channel === 'both' ? ['sms', 'whatsapp'] : [$channel];

        $ids = array_values(array_unique(array_map('intval', $recipientIds)));
        if ($ids === [] || in_array(0, $ids, true)) {
            throw new \InvalidArgumentException('Choose at least one recipient.');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('At most ' . self::MAX_RECIPIENTS . ' recipients per send.');
        }

        $users = DB::table('tbluser')->whereIn('id', $ids)->where('sub_institute_id', $tenant)
            ->where('status', 1)->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'mobile'])->keyBy('id');

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
            throw new \InvalidArgumentException('Cannot send: ' . implode('; ', $problems) . '.');
        }

        $sender = DB::table('tbluser')->where('id', $senderId)->first(['first_name', 'last_name']);
        $note = $note === null ? null : mb_substr(trim($note), 0, 500);
        $message = $this->message($sender ? $this->name($sender) : 'A colleague', (string) $report->title, $note === '' ? null : $note,
            $reportUrl !== null && preg_match('#^https?://#i', $reportUrl) ? $reportUrl : null);

        $merged = ['sent' => 0, 'failed' => 0, 'duplicate' => 0, 'prepared' => 0, 'results' => []];

        foreach ($channels as $one) {
            $account = $one === 'sms' ? $this->sms->resolve($tenant) : $this->whatsapp->resolve($tenant);

            foreach ($ids as $id) {
                $u = $users->get($id);
                $mobile = (string) $this->cleanMobile($u->mobile);
                $base = ['recipient_user_id' => $id, 'name' => $this->name($u), 'mobile' => $mobile, 'channel' => $one];

                if ($one === 'sms' && $account === null) {
                    $merged['failed']++;
                    $merged['results'][] = $base + ['status' => 'failed', 'error' => 'No SMS gateway is configured for this organisation. Set one up under Platform Services → Integration (SMS gateway).'];
                    continue;
                }

                if ($one === 'whatsapp' && $account === null) {
                    $merged['prepared']++;
                    $merged['results'][] = $base + [
                        'status' => 'prepared', 'error' => null,
                        'link' => 'https://wa.me/' . $this->whatsappNumber($mobile) . '?text=' . rawurlencode($message),
                    ];
                    continue;
                }

                $lock = 'report-mobile:' . sha1("$tenant|{$report->id}|$id|$one|" . ($requestKey ?: intdiv(time(), self::DUPLICATE_WINDOW_SECONDS)));
                if (! Cache::add($lock, 1, self::DUPLICATE_WINDOW_SECONDS)) {
                    $merged['duplicate']++;
                    $merged['results'][] = $base + ['status' => 'duplicate', 'error' => 'Already sent to this person a moment ago; not sent again.'];
                    continue;
                }

                if ($one === 'sms') {
                    [$status, $reason] = $this->sms->dispatch($account, $mobile, $message);
                    $messageId = null;
                } else {
                    $outcome = $this->whatsapp->send($account, $this->whatsappNumber($mobile), $message);
                    [$status, $reason, $messageId] = [$outcome['status'], $outcome['error'], $outcome['id']];
                }

                if ($status === 'sent') {
                    $merged['sent']++;
                    $merged['results'][] = $base + ['status' => 'sent', 'error' => null] + ($messageId !== null ? ['message_id' => $messageId] : []);
                } else {
                    Cache::forget($lock); // a failed attempt can be retried at once
                    $merged['failed']++;
                    $merged['results'][] = $base + ['status' => 'failed', 'error' => mb_substr((string) $reason, 0, 300)];
                }
            }
        }

        return $merged;
    }

    private function message(string $sender, string $title, ?string $note, ?string $link): string
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

    private function cleanMobile(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    private function whatsappNumber(string $digits): string
    {
        return strlen($digits) === 10 ? '91' . $digits : ltrim($digits, '0');
    }

    private function name(object $u): string
    {
        $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));

        return $n !== '' ? $n : 'User #' . ($u->id ?? '');
    }
}
