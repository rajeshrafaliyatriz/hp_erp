<?php

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Reports\ReportAdHocMailer;
use App\Domain\Reports\ReportMobileSender;
use App\Domain\Reports\ReportQueryExecutionService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportEmailController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(
        private readonly ReportShareService $shares,
        private readonly ReportQueryExecutionService $executor,
        private readonly ReportAdHocMailer $mailer,
        private readonly ReportMobileSender $mobile,
    ) {
    }

    /** POST /api/report-builder/{id}/send-email */
    public function send(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $report = $this->viewableReport($id, $tenantId, (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $data = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:10'],
            'recipients.*' => ['required', 'email'],
        ]);

        $result = $this->mailer->send($report, $data['recipients'], $this->executor);

        return response()->json(['status' => $result['ok'], 'message' => $result['message']], $result['ok'] ? 200 : 422);
    }

    /** GET /api/report-builder/{id}/mobile-recipients - people to pick from (never their numbers) + which channels can send. */
    public function mobileRecipients(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->viewableReport($id, $tenantId, (int) $identity['user_id'])) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        return response()->json(['status' => true, 'data' => [
            'recipients' => $this->mobile->recipients($tenantId, $request->query('q')),
            'max_recipients' => ReportMobileSender::MAX_RECIPIENTS,
        ] + $this->mobile->availability($tenantId)]);
    }

    /** POST /api/report-builder/{id}/send-mobile - SMS, WhatsApp, or both; each channel is attempted on its own. */
    public function sendMobile(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $report = $this->viewableReport($id, $tenantId, (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $data = $request->validate([
            'recipient_ids' => ['required', 'array', 'min:1', 'max:' . ReportMobileSender::MAX_RECIPIENTS],
            'recipient_ids.*' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:sms,whatsapp,both'],
            'note' => ['nullable', 'string', 'max:500'],
            'request_key' => ['nullable', 'string', 'max:80'],
            'report_url' => ['nullable', 'url', 'max:300'],
        ]);

        try {
            $result = $this->mobile->send(
                $report, (int) $identity['user_id'], $data['recipient_ids'], $data['channel'],
                $data['note'] ?? null, $data['request_key'] ?? null, $data['report_url'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        $message = sprintf('%d sent, %d failed%s%s.', $result['sent'], $result['failed'],
            $result['prepared'] ? ", {$result['prepared']} WhatsApp link(s) prepared (not sent)" : '',
            $result['duplicate'] ? ", {$result['duplicate']} already sent a moment ago" : '');

        return response()->json(['status' => $result['sent'] > 0 || $result['prepared'] > 0, 'message' => $message, 'data' => $result]);
    }

    /** Same access rule as ReportController::export() — anyone who can view a report can send a copy of it, same as downloading one. */
    private function viewableReport(int $id, int $tenantId, int $userId): ?object
    {
        $report = DB::table('reports')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$report) {
            return null;
        }

        $canView = $report->sharingtype === 'Public'
            || (int) $report->owner_id === $userId
            || $this->shares->isSharedWith($id, $userId);

        return $canView ? $report : null;
    }
}
