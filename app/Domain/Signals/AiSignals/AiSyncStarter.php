<?php

namespace App\Domain\Signals\AiSignals;

use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Jobs\SyncDataConnectorJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starts a sync of an AI signal connector: the same lifecycle as every other connector (a `queued` sync row, the existing SyncDataConnectorJob, the
 * existing reaper and history), used by the Sync button, Sync All and the delivery endpoint, so they cannot drift apart. The sync imports what has been
 * delivered; it never fetches anything and never calls a model.
 */
final class AiSyncStarter
{
    /**
     * @return array{code: string, sync: ?DataConnectorSync}  code: started | already_running | disabled | dispatch_failed
     */
    public function start(DataConnector $connector, ?int $userId): array
    {
        if (! $connector->sync_enabled || $connector->connection_status === 'disconnected') {
            return ['code' => 'disabled', 'sync' => null];
        }
        $tenantId = (int) $connector->sub_institute_id;

        $sync = DB::transaction(function () use ($connector, $tenantId, $userId) {
            $locked = DataConnector::where('sub_institute_id', $tenantId)->whereKey($connector->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->sync_enabled) {
                return null;
            }
            if (DataConnectorSync::where('sub_institute_id', $tenantId)->where('connector_id', $connector->id)->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }
            $sync = DataConnectorSync::create([
                'sub_institute_id' => $tenantId, 'connector_id' => $connector->id, 'status' => 'queued', 'created_by' => $userId, 'started_at' => now(),
            ]);
            $locked->forceFill(['last_sync_at' => now()])->save();

            return $sync;
        });
        if (! $sync) {
            return ['code' => 'already_running', 'sync' => null];
        }

        try {
            SyncDataConnectorJob::dispatch($tenantId, (int) $connector->id, (int) $sync->id, $userId)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('signals.ai_sync_dispatch_failed', ['tenant' => $tenantId, 'connector' => $connector->id, 'exception' => get_class($e)]);
            $sync->forceFill(['status' => 'failed', 'error_message' => 'The sync could not be queued. Please retry.', 'completed_at' => now()])->save();

            return ['code' => 'dispatch_failed', 'sync' => $sync];
        }
        if (config('queue.default') !== 'sync') {
            \App\Support\QueueWorkerLauncher::start((string) config('signals.queue', 'signals'));
        }

        return ['code' => 'started', 'sync' => $sync->refresh()];
    }
}
