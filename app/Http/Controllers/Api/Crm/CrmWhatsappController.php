<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Whatsapp\TenantWhatsappCloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CrmWhatsappController extends Controller
{
    use ResolvesApiIdentity;

    private const PHONE_SOURCES = [
        'lead' => ['table' => 'crm_leads', 'columns' => ['mobile', 'phone']],
        'organization' => ['table' => 'crm_organizations', 'columns' => ['phone']],
        'contact' => ['table' => 'crm_contacts', 'columns' => ['mobile', 'phone']],
    ];

    /** POST /api/crm/whatsapp/send */
    public function send(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'toNumber' => 'required|string',
            'message' => 'required|string|max:4096',
            'relatedType' => 'nullable|string|in:lead,contact,organization,opportunity',
            'relatedId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $account = $this->resolveWhatsappConfig($tenantId);

        if (! $account) {
            return response()->json([
                'status' => 0,
                'message' => 'WhatsApp Cloud API is not configured. Please configure WhatsApp under Settings → Email, SMS & WhatsApp.',
            ], 422);
        }

        $toNumber = $this->cleanPhoneNumber($request->input('toNumber'));
        if (! $toNumber) {
            return response()->json(['status' => 0, 'message' => 'Invalid phone number format.'], 422);
        }

        $message = trim((string) $request->input('message'));
        $cloud = app(TenantWhatsappCloud::class);
        $result = $cloud->send($account, $toNumber, $message);

        $status = $result['status'] === 'sent' ? 1 : 0;
        $statusText = $result['status'] === 'sent' ? 'sent' : 'failed';
        $msg = $result['status'] === 'sent' ? 'WhatsApp message sent.' : ($result['error'] ?? 'WhatsApp delivery failed.');

        // Log into crm_sms_log if available
        try {
            if (Schema::hasTable('crm_sms_log')) {
                DB::table('crm_sms_log')->insert([
                    'to_number' => $toNumber,
                    'message' => '[WhatsApp] ' . $message,
                    'status' => $statusText,
                    'error_reason' => $result['error'] ?? null,
                    'related_type' => $request->input('relatedType'),
                    'related_id' => $request->input('relatedId'),
                    'sent_by' => $identity['user_id'],
                    'sub_institute_id' => $tenantId,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable) {}

        return response()->json([
            'status' => $status,
            'message' => $msg,
            'data' => $result,
        ], $status === 1 ? 200 : 422);
    }

    /** POST /api/crm/whatsapp/bulk-send */
    public function bulkSend(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'relatedType' => 'required|string|in:' . implode(',', array_keys(self::PHONE_SOURCES)),
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
            'message' => 'required|string|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $account = $this->resolveWhatsappConfig($tenantId);

        if (! $account) {
            return response()->json([
                'status' => 0,
                'message' => 'WhatsApp Cloud API is not configured. Please configure WhatsApp under Settings → Email, SMS & WhatsApp.',
            ], 422);
        }

        $relatedType = $request->input('relatedType');
        $source = self::PHONE_SOURCES[$relatedType];
        $message = trim((string) $request->input('message'));
        $ids = array_unique(array_map('intval', $request->input('ids')));

        $rows = DB::table($source['table'])
            ->whereIn('id', $ids)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();

        $rowsById = $rows->keyBy('id');
        $cloud = app(TenantWhatsappCloud::class);
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

            $rawPhone = null;
            foreach ($source['columns'] as $column) {
                if (! empty($row->{$column})) {
                    $rawPhone = $row->{$column};
                    break;
                }
            }

            $cleanPhone = $this->cleanPhoneNumber($rawPhone);
            if (! $cleanPhone) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => 'No valid phone number on file.'];
                $failed++;
                continue;
            }

            $result = $cloud->send($account, $cleanPhone, $message);
            $isSent = $result['status'] === 'sent';

            try {
                if (Schema::hasTable('crm_sms_log')) {
                    DB::table('crm_sms_log')->insert([
                        'to_number' => $cleanPhone,
                        'message' => '[WhatsApp] ' . $message,
                        'status' => $isSent ? 'sent' : 'failed',
                        'error_reason' => $result['error'] ?? null,
                        'related_type' => $relatedType,
                        'related_id' => $id,
                        'sent_by' => $identity['user_id'],
                        'sub_institute_id' => $tenantId,
                        'created_at' => now(),
                    ]);
                }
            } catch (\Throwable) {}

            if ($isSent) {
                $results[] = ['id' => (string) $id, 'ok' => true];
                $sent++;
            } else {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => $result['error'] ?? 'WhatsApp delivery failed.'];
                $failed++;
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $failed === 0
                ? "{$sent} WhatsApp message(s) sent."
                : "{$sent} sent, {$failed} failed of " . count($ids) . ' record(s).',
            'data' => ['sent' => $sent, 'failed' => $failed, 'results' => $results],
        ]);
    }

    /** Resolve WhatsApp credentials from vault or whatapp_user_details */
    private function resolveWhatsappConfig(int $tenantId): ?array
    {
        // 1. Try TenantWhatsappCloud resolver (checks g2g_integration_credentials)
        $account = app(TenantWhatsappCloud::class)->resolve($tenantId);
        if ($account) {
            return $account;
        }

        // 2. Fallback to whatapp_user_details table
        if (Schema::hasTable('whatapp_user_details')) {
            $row = DB::table('whatapp_user_details')->where('sub_institute_id', $tenantId)->first();
            if ($row && ! empty($row->cloud_api_phone_number_id) && ! empty($row->cloud_api_access_token)) {
                return [
                    'token' => $row->cloud_api_access_token,
                    'phone_number_id' => $row->cloud_api_phone_number_id,
                    'version' => 'v25.0',
                ];
            }
        }

        return null;
    }

    /** Clean phone number: remove non-digits, ensure valid length */
    private function cleanPhoneNumber(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/[^\d]/', '', $phone);
        if (strlen($digits) < 10) {
            return null;
        }
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }
        return $digits;
    }
}
