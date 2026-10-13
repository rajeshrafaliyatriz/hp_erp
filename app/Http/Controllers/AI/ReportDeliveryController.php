<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Chat\NotFoundReport;
use App\Domain\AI\Chat\ReportDeliveryService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Send a saved AI report to real people of the caller's organisation, and read back the history.
 * Tenant and role come from the token (`scope()`); see ReportDeliveryService for the rules.
 */
class ReportDeliveryController extends AiController
{
    public function __construct(private readonly ReportDeliveryService $deliveries)
    {
    }

    /** GET /chat/report-recipients?q= */
    public function recipients(Request $request)
    {
        try {
            $data = $request->validate(['q' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:50']);

            return $this->success('Recipients resolved.', [
                'recipients' => $this->deliveries->recipients($this->scope($request), $data['q'] ?? null, (int) ($data['limit'] ?? 25)),
                'max_recipients' => ReportDeliveryService::MAX_RECIPIENTS,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** GET /chat/reports?module_key= : recent saved reports of the caller's organisation for a module. */
    public function recent(Request $request)
    {
        try {
            $data = $request->validate(['module_key' => 'required|string|max:60']);

            return $this->success('Reports resolved.', [
                'reports' => $this->deliveries->recentReports($this->scope($request), $data['module_key']),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** POST /chat/reports/{id}/send */
    public function send(Request $request, int $id)
    {
        try {
            $data = $request->validate([
                'recipient_ids' => 'required|array|min:1|max:' . ReportDeliveryService::MAX_RECIPIENTS,
                'recipient_ids.*' => 'required|integer|min:1',
                'note' => 'nullable|string|max:500',
                'request_key' => 'nullable|string|max:80',
            ]);

            $result = $this->deliveries->send(
                $this->scope($request), $id, $data['recipient_ids'], $data['note'] ?? null, $data['request_key'] ?? null
            );

            $message = $result['failed'] > 0
                ? sprintf('Sent to %d, failed for %d.', $result['sent'], $result['failed'])
                : sprintf('Report sent to %d recipient(s).', $result['sent']);

            return $this->success($message, $result);
        } catch (NotFoundReport $exception) {
            return $this->failure($exception->getMessage(), 404);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** GET /chat/reports/{id}/deliveries */
    public function history(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);

            return $this->success('Deliveries resolved.', [
                'can_send' => $this->deliveries->canSend($scope),
                'deliveries' => $this->deliveries->deliveries($scope, $id),
            ]);
        } catch (NotFoundReport $exception) {
            return $this->failure($exception->getMessage(), 404);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
