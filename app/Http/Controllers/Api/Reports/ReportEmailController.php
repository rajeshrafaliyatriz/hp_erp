<?php

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Reports\ReportAdHocMailer;
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
