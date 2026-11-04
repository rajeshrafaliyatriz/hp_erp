<?php

namespace App\Console\Commands;

use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Jobs\SyncDataConnectorJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScheduleDataConnectorSyncs extends Command
{
    protected $signature = 'signals:connectors:sync-scheduled';

    protected $description = 'Queue due, enabled data connector syncs for their owning organisations.';

    public function handle(): int
    {
        if (! config('signals_connectors.schedule_enabled')) {
            return self::SUCCESS;
        }

        $due = DataConnector::where('sync_enabled', true)
            ->where('schedule_enabled', true)
            ->whereIn('connection_status', ['connected', 'rate_limited'])
            ->whereNotNull('next_sync_at')
            ->where('next_sync_at', '<=', now())
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('g2g_data_connector_syncs as pending_syncs')
                    ->whereColumn('pending_syncs.connector_id', 'g2g_data_connectors.id')
                    ->whereColumn('pending_syncs.sub_institute_id', 'g2g_data_connectors.sub_institute_id')
                    ->whereIn('pending_syncs.status', ['queued', 'running']);
            })
            ->orderBy('next_sync_at')
            ->limit(100)
            ->get(['id', 'sub_institute_id', 'created_by']);

        $dispatchFailures = 0;
        foreach ($due as $candidate) {
            $dispatch = DB::transaction(function () use ($candidate) {
                $connector = DataConnector::where('sub_institute_id', $candidate->sub_institute_id)
                    ->whereKey($candidate->id)
                    ->lockForUpdate()
                    ->first();
                if (! $connector || ! $connector->sync_enabled || ! $connector->schedule_enabled || ! in_array($connector->connection_status, ['connected', 'rate_limited'], true) || ! $connector->next_sync_at?->isPast()) {
                    return null;
                }

                $pending = DataConnectorSync::where('sub_institute_id', $connector->sub_institute_id)
                    ->where('connector_id', $connector->id)
                    ->whereIn('status', ['queued', 'running'])
                    ->exists();
                if ($pending) {
                    return null;
                }

                $sync = DataConnectorSync::create([
                    'sub_institute_id' => $connector->sub_institute_id,
                    'connector_id' => $connector->id,
                    'status' => 'queued',
                    'created_by' => $connector->created_by,
                    'started_at' => now(),
                ]);
                $connector->forceFill(['last_sync_at' => now()])->save();

                return [$connector, $sync];
            });

            if ($dispatch !== null) {
                [$connector, $sync] = $dispatch;
                try {
                    SyncDataConnectorJob::dispatch((int) $connector->sub_institute_id, (int) $connector->id, (int) $sync->id, $connector->created_by ? (int) $connector->created_by : null);
                } catch (Throwable $exception) {
                    $message = 'The scheduled sync could not be queued. It will be retried on the next scheduler run.';
                    $sync->forceFill([
                        'status' => 'failed',
                        'records_failed' => max(1, (int) $sync->records_fetched),
                        'error_message' => $message,
                        'completed_at' => now(),
                    ])->save();
                    $connector->forceFill(['last_error' => $message])->save();
                    Log::warning('signals.connector_sync_dispatch_failed', [
                        'tenant' => $connector->sub_institute_id,
                        'connector' => $connector->id,
                        'exception' => get_class($exception),
                    ]);
                    $dispatchFailures++;
                }
            }
        }

        return $dispatchFailures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
