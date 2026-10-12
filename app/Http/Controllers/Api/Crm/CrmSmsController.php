<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Controller;
use App\Support\MenuRight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SMS Notifier - a real send action plus an append-only sent-message log
 * (`crm_sms_log` has no `deleted_at`; nothing here is ever edited or
 * un-sent, so there is deliberately no update()/destroy()/Recycle-Bin/
 * Picklist wiring - CrmSavedViewController already carries an `sms_log`
 * entry from Phase 0 since saved filter presets on the log view are a
 * real, separate thing from "can this row be edited").
 *
 * send() resolves credentials VAULT-FIRST (`g2g_integration_credentials`,
 * provider_key='sms' - the generic encrypted store Settings > Integrations
 * already manages), falling back to the legacy `sms_api_details` row so
 * the one tenant already configured there keeps working without a forced
 * migration. `authController::sendSMS()` (the live login-OTP path) is
 * deliberately NOT touched and NOT called from here - this is a new,
 * separate adapter, matching the plan's own scope decision. One
 * documented difference from that legacy method, not silently matched:
 * its hardcoded 9-tenant `$cn` template-id special case is NOT replicated
 * here (undocumented, OTP-specific, and copying an undocumented hack into
 * a second code path would make the debt worse, not better) - a CRM send
 * on one of those tenants may need its own template id added to that
 * tenant's own `pram`/`last_var` config instead.
 *
 * Success/failure is TRANSPORT-level only (did the gateway respond at
 * all), same honesty limit the legacy method has - a generic, template-
 * based adapter cannot parse an arbitrary vendor's success/failure body
 * format, so "sent" means "the gateway accepted the request", not "the
 * carrier delivered it".
 *
 * export() and bulkSend() added in Phase 6. export() is a compliance/audit
 * CSV export of the same append-only log, same HasCrmExport trait every
 * other Sales entity uses - read-only, not in tension with the no-edit/
 * no-Recycle-Bin stance above. bulkSend() is vtiger's own SMSNotifier list-
 * view mass-action (select N records, send one message to all) - still NOT
 * built: per-recipient delivery-status re-poll, unchanged from this
 * migration's own original reasoning (needs inbound webhook infrastructure
 * this app doesn't have) - re-confirmed, not overlooked, during the Phase 6
 * audit.
 */
class CrmSmsController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmExport;

    private const SMS_LINK = '/module/crm/sales/sms-notifier';

    /** @var array<string, string> relatedType => table, for the existence check before logging. */
    private const RELATED_TABLES = [
        'organization' => 'crm_organizations',
        'contact' => 'crm_contacts',
        'opportunity' => 'crm_opportunities',
    ];

    /**
     * @var array<string, array{table: string, columns: array<string>}>
     *   relatedType => {table, phone columns in priority order} for
     *   bulkSend() - Opportunities excluded, they carry no phone of their
     *   own (same reasoning send-sms-dialog.tsx already applies).
     */
    private const PHONE_SOURCES = [
        'lead' => ['table' => 'crm_leads', 'columns' => ['mobile', 'phone']],
        'organization' => ['table' => 'crm_organizations', 'columns' => ['phone']],
        'contact' => ['table' => 'crm_contacts', 'columns' => ['mobile', 'phone']],
    ];

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'to_number' => 'Phone Number', 'message' => 'Message', 'status' => 'Status',
        'error_reason' => 'Error Reason', 'related_type' => 'Related To', 'related_id' => 'Related Record ID',
        'sent_by' => 'Sent By (user id)', 'created_at' => 'Sent At',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_sms_log')->where('sub_institute_id', $identity['sub_institute_id']);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('to_number', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->filled('related_type') && $request->filled('related_id')) {
            $query->where('related_type', $request->input('related_type'))
                ->where('related_id', (int) $request->input('related_id'));
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'SMS log.',
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

    /** Exports the same rows index() would list (search/status applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_sms_log')->where('sub_institute_id', $identity['sub_institute_id']);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('to_number', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'sms-log.csv');
    }

    public function send(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->canSend($identity)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to send SMS.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'toNumber' => 'required|string|max:30',
            'message' => 'required|string|max:1000',
            'relatedType' => 'nullable|string|in:' . implode(',', array_keys(self::RELATED_TABLES)),
            'relatedId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $relatedType = $request->input('relatedType');
        $relatedId = $request->filled('relatedId') ? (int) $request->input('relatedId') : null;

        if ($relatedType && $relatedId) {
            $exists = DB::table(self::RELATED_TABLES[$relatedType])
                ->where('id', $relatedId)
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                return response()->json(['status' => 0, 'message' => 'That record was not found.'], 422);
            }
        }

        $toNumber = $request->input('toNumber');
        $message = $request->input('message');
        $gateway = $this->resolveGatewayConfig($identity['sub_institute_id']);

        if (! $gateway) {
            $this->logAttempt($identity, $toNumber, $message, 'failed', 'No SMS gateway configured for this tenant.', $relatedType, $relatedId);

            return response()->json(['status' => 0, 'message' => 'No SMS gateway is configured. Set one up under Settings → Integrations.'], 422);
        }

        [$status, $errorReason] = $this->dispatch($gateway, $toNumber, $message);
        $row = $this->logAttempt($identity, $toNumber, $message, $status, $errorReason, $relatedType, $relatedId);

        if ($status === 'failed') {
            return response()->json(['status' => 0, 'message' => 'The SMS gateway could not be reached.', 'data' => $this->resource($row)]);
        }

        return response()->json(['status' => 1, 'message' => 'SMS sent.', 'data' => $this->resource($row)]);
    }

    /**
     * Phase 6 audit gap: vtiger's own SMSNotifier list-view mass action lets
     * a rep select N records and send one message to all of them at once.
     * One gateway resolve, one `dispatch()` per recipient with a phone on
     * file - every id is logged (even a skip, as a 'failed' row with a
     * clear reason) so the append-only log stays the single source of
     * truth for "what did we attempt," same discipline send() already has.
     */
    public function bulkSend(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->canSend($identity)) {
            return response()->json(['status' => 0, 'message' => 'You do not have permission to send SMS.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'relatedType' => 'required|string|in:' . implode(',', array_keys(self::PHONE_SOURCES)),
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $relatedType = $request->input('relatedType');
        $source = self::PHONE_SOURCES[$relatedType];
        $message = $request->input('message');
        $ids = array_unique(array_map('intval', $request->input('ids')));

        $rows = DB::table($source['table'])
            ->whereIn('id', $ids)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->get();

        $rowsById = $rows->keyBy('id');
        $gateway = $this->resolveGatewayConfig($identity['sub_institute_id']);
        $sent = 0;
        $failed = 0;
        $results = [];

        foreach ($ids as $id) {
            $row = $rowsById->get($id);

            if (! $row) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => 'Record not found.'];
                $failed++;
                continue;
            }

            $toNumber = null;
            foreach ($source['columns'] as $column) {
                if (! empty($row->{$column})) {
                    $toNumber = $row->{$column};
                    break;
                }
            }

            if (! $toNumber) {
                $this->logAttempt($identity, '', $message, 'failed', 'No phone number on file.', $relatedType, $id);
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => 'No phone number on file.'];
                $failed++;
                continue;
            }

            if (! $gateway) {
                $this->logAttempt($identity, $toNumber, $message, 'failed', 'No SMS gateway configured for this tenant.', $relatedType, $id);
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => 'No SMS gateway configured.'];
                $failed++;
                continue;
            }

            [$status, $errorReason] = $this->dispatch($gateway, $toNumber, $message);
            $this->logAttempt($identity, $toNumber, $message, $status, $errorReason, $relatedType, $id);

            if ($status === 'failed') {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => $errorReason ?? 'The SMS gateway could not be reached.'];
                $failed++;
            } else {
                $results[] = ['id' => (string) $id, 'ok' => true];
                $sent++;
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $failed === 0
                ? "{$sent} message(s) sent."
                : "{$sent} sent, {$failed} failed of " . count($ids) . ' record(s).',
            'data' => ['sent' => $sent, 'failed' => $failed, 'results' => $results],
        ]);
    }

    /**
     * @return array{0: 'sent'|'failed', 1: string|null}
     */
    private function dispatch(array $gateway, string $toNumber, string $message): array
    {
        $url = $gateway['url'] . $gateway['pram'] . $gateway['mobileVar'] . $toNumber
            . $gateway['textVar'] . urlencode($message) . urlencode($gateway['lastVar']);

        try {
            $response = Http::timeout(10)->withOptions(['verify' => false])->get($url);

            if (! $response->successful()) {
                return ['failed', 'Gateway returned HTTP ' . $response->status() . '.'];
            }

            return ['sent', null];
        } catch (\Throwable $e) {
            return ['failed', mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private function logAttempt(array $identity, string $toNumber, string $message, string $status, ?string $errorReason, ?string $relatedType, ?int $relatedId): object
    {
        $id = DB::table('crm_sms_log')->insertGetId([
            'to_number' => $toNumber,
            'message' => $message,
            'status' => $status,
            'error_reason' => $errorReason,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'sent_by' => $identity['user_id'],
            'sub_institute_id' => $identity['sub_institute_id'],
            'created_at' => now(),
        ]);

        return DB::table('crm_sms_log')->where('id', $id)->first();
    }

    /**
     * Vault-first: `g2g_integration_credentials` (provider_key='sms'),
     * the same encrypted store Settings > Integrations manages - decrypt
     * exactly the way IntegrationController itself does, since this table
     * has no Eloquent model anywhere in the app. Falls back to the legacy
     * `sms_api_details` row only when the vault has nothing usable, so the
     * one tenant already configured there is never forced to re-enter
     * credentials just because this feature shipped.
     *
     * @return array{url: string, pram: string, mobileVar: string, textVar: string, lastVar: string}|null
     */
    private function resolveGatewayConfig(int $tenantId): ?array
    {
        $vaultRow = DB::table('g2g_integration_credentials')
            ->where('sub_institute_id', $tenantId)
            ->where('provider_key', 'sms')
            ->first();

        if ($vaultRow && $vaultRow->config) {
            try {
                $config = json_decode(Crypt::decryptString($vaultRow->config), true) ?: [];
            } catch (\Throwable $e) {
                $config = [];
            }

            if (! empty($config['url']) && ! empty($config['mobile_var']) && ! empty($config['text_var'])) {
                return [
                    'url' => $config['url'], 'pram' => $config['pram'] ?? '',
                    'mobileVar' => $config['mobile_var'], 'textVar' => $config['text_var'],
                    'lastVar' => $config['last_var'] ?? '',
                ];
            }
        }

        $legacy = DB::table('sms_api_details')->where('sub_institute_id', $tenantId)->first();

        if ($legacy && ! empty($legacy->url)) {
            return [
                'url' => $legacy->url, 'pram' => $legacy->pram ?? '',
                'mobileVar' => $legacy->mobile_var, 'textVar' => $legacy->text_var,
                'lastVar' => $legacy->last_var ?? '',
            ];
        }

        return null;
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private function canSend(array $identity): bool
    {
        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $menuId = MenuRight::idForAccessLink(self::SMS_LINK);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'edit');
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'toNumber' => $row->to_number,
            'message' => $row->message,
            'status' => $row->status,
            'errorReason' => $row->error_reason,
            'relatedType' => $row->related_type,
            'relatedId' => $row->related_id ? (string) $row->related_id : null,
            'sentBy' => $row->sent_by ? (string) $row->sent_by : null,
            'createdAt' => $row->created_at,
        ];
    }
}
