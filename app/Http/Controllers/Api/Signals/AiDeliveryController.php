<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\AiSignals\AiDeliveryReceiver;
use App\Domain\Signals\AiSignals\AiDeliveryToken;
use App\Domain\Signals\AiSignals\AiSignalProviders;
use App\Domain\Signals\AiSignals\AiSyncStarter;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The public door a scheduled AI task knocks on to deliver its finished signals: POST /api/signals/ai-delivery.
 *
 * Authenticated ONLY by the connector's secret token (`Authorization: Bearer g2gai_...`). The token says which connector; the connector says which
 * organisation. Nothing in the body can name another. Unknown connector, wrong token and malformed token all answer the same 401, so the endpoint
 * cannot be used to find out which connectors exist. The token and the body are never logged. The delivery is stored as received and imported by the
 * existing sync; no model is called.
 */
class AiDeliveryController extends Controller
{
    public function receive(Request $request, AiDeliveryReceiver $receiver, AiSyncStarter $starter): JsonResponse
    {
        if (! AiDeliveryReceiver::enabled()) {
            return response()->json(['status' => 0, 'message' => 'AI signal delivery is switched off on this server.'], 503);
        }

        $token = AiDeliveryToken::fromHeader($request->header('Authorization'));
        $connector = null;
        if (($id = AiDeliveryToken::connectorIdOf($token)) !== null && AiDeliveryReceiver::tableReady()) {
            $connector = DataConnector::whereKey($id)->whereIn('type', AiSignalProviders::types())->first();
        }
        $stored = is_array($connector?->credentials) ? ($connector->credentials['delivery_token_hash'] ?? null) : null;
        // Always do the comparison, even for a connector that does not exist, so the answer takes the same time either way.
        $valid = AiDeliveryToken::matches($token, $stored ?? hash('sha256', 'no-such-connector')) && $connector !== null && $stored !== null;
        if (! $valid) {
            Log::warning('signals.ai_delivery_rejected', ['reason' => 'bad_token', 'ip' => $request->ip()]);

            return response()->json(['status' => 0, 'message' => 'Invalid or missing delivery token.'], 401);
        }
        if (! $connector->sync_enabled || $connector->connection_status === 'disconnected') {
            return response()->json(['status' => 0, 'message' => 'This AI signal connector is switched off in G2G.'], 403);
        }

        $result = $receiver->receive($connector, (string) $request->getContent(), 'webhook');
        $http = ['not_ready' => 503, 'empty' => 422, 'invalid' => 422, 'too_many_records' => 422, 'provider_mismatch' => 422, 'too_large' => 413];
        if (! $result['ok']) {
            Log::info('signals.ai_delivery_refused', ['tenant' => $connector->sub_institute_id, 'connector' => $connector->id, 'reason' => $result['code']]);

            return response()->json(['status' => 0, 'code' => $result['code'], 'message' => $result['message'], 'delivery_id' => $result['delivery']?->id], $http[$result['code']] ?? 422);
        }
        if ($result['duplicate']) {
            return response()->json(['status' => 1, 'data' => ['duplicate' => true, 'delivery_id' => $result['delivery']->id, 'message' => $result['message']]], 200);
        }

        Log::info('signals.ai_delivery_received', ['tenant' => $connector->sub_institute_id, 'connector' => $connector->id, 'delivery' => $result['delivery']->id, 'records' => $result['records']]);
        // Daily automatic ingestion: a delivery starts its own import. If a sync is already running it will pick this delivery up before it finishes.
        $sync = $starter->start($connector, null);

        return response()->json(['status' => 1, 'data' => [
            'duplicate' => false, 'delivery_id' => $result['delivery']->id, 'records' => $result['records'],
            'import' => $sync['code'] === 'started' ? 'started' : ($sync['code'] === 'already_running' ? 'queued_behind_running_sync' : 'waiting_for_sync'),
        ]], 202);
    }
}
