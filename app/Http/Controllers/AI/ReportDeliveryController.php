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
                // Whether this organisation has its own SMS gateway, so the dialog can say so before sending.
                'whatsapp_configured' => app(\App\Services\Whatsapp\TenantWhatsappCloud::class)->resolve((int) $this->scope($request)->selectedInstituteId) !== null,
                'sms_configured' => app(\App\Services\Sms\TenantSmsGateway::class)->resolve((int) $this->scope($request)->selectedInstituteId) !== null,
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
                'channel' => 'nullable|string|in:email,sms,whatsapp,both',
                'report_url' => 'nullable|url|max:300',
            ]);

            $channel = $data['channel'] ?? 'email';
            $result = $channel === 'both'
                ? $this->sendBoth($request, $id, $data)
                : ($channel === 'email'
                    ? $this->deliveries->send(
                        $this->scope($request), $id, $data['recipient_ids'], $data['note'] ?? null, $data['request_key'] ?? null
                    )
                    : $this->deliveries->sendToMobile(
                        $this->scope($request), $id, $data['recipient_ids'], $channel, $data['note'] ?? null, $data['request_key'] ?? null, $data['report_url'] ?? null
                    ));

            $message = ($result['prepared'] ?? 0) > 0 && $result['failed'] === 0
                ? sprintf('WhatsApp message prepared for %d recipient(s); open each link to send it.', $result['prepared'])
                : ($result['failed'] > 0
                ? sprintf('Sent to %d, failed for %d.', $result['sent'], $result['failed'])
                : sprintf('Report sent to %d recipient(s).', $result['sent']));

            return $this->success($message, $result);
        } catch (NotFoundReport $exception) {
            return $this->failure($exception->getMessage(), 404);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * SMS and WhatsApp for the same recipients, each attempted on its own: a channel that cannot run (no gateway,
     * gateway refused, no connected account) is reported with its exact reason and never stops the other one.
     * Every result row names its channel, and each channel writes its own `ai_report_deliveries` rows.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function sendBoth(Request $request, int $id, array $data): array
    {
        $merged = ['report_id' => $id, 'title' => '', 'channel' => 'both', 'sent' => 0, 'failed' => 0, 'duplicate' => 0, 'prepared' => 0, 'results' => []];
        $people = null;

        foreach (['sms', 'whatsapp'] as $channel) {
            try {
                $part = $this->deliveries->sendToMobile(
                    $this->scope($request), $id, $data['recipient_ids'], $channel, $data['note'] ?? null, $data['request_key'] ?? null, $data['report_url'] ?? null
                );
            } catch (NotFoundReport $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $people ??= \Illuminate\Support\Facades\DB::table('tbluser')
                    ->whereIn('id', $data['recipient_ids'])
                    ->where('sub_institute_id', (int) $this->scope($request)->selectedInstituteId)
                    ->get(['id', 'first_name', 'last_name', 'email', 'mobile'])->keyBy('id');
                $part = ['sent' => 0, 'failed' => 0, 'duplicate' => 0, 'prepared' => 0, 'results' => []];
                foreach (array_values(array_unique($data['recipient_ids'])) as $rid) {
                    $u = $people->get($rid);
                    $part['failed']++;
                    $part['results'][] = [
                        'recipient_user_id' => (int) $rid,
                        'name' => $u ? trim($u->first_name . ' ' . $u->last_name) : "#$rid",
                        'email' => $u->email ?? null,
                        'mobile' => $u->mobile ?? null,
                        'channel' => $channel,
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                    ];
                }
            }

            $merged['title'] = $merged['title'] !== '' ? $merged['title'] : (string) ($part['title'] ?? '');
            foreach (['sent', 'failed', 'duplicate', 'prepared'] as $k) {
                $merged[$k] += (int) ($part[$k] ?? 0);
            }
            $merged['results'] = array_merge($merged['results'], $part['results']);
        }

        return $merged;
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
