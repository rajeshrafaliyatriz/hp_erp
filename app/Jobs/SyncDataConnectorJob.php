<?php

namespace App\Jobs;

use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Connectors\SocialConnectorApiException;
use App\Domain\Signals\Connectors\SocialDataConnectorAdapter;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\IngestionAnalysis;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\IngestionService;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Support\UnsafeUrlException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncDataConnectorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;
    public int $timeout = 900;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $connectorId,
        public readonly int $syncId,
        public readonly ?int $userId,
    ) {
        $this->onQueue((string) config('signals.queue', 'signals'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(DataConnectorRegistry $registry, IngestionService $ingestion, IngestionAnalyzer $analyzer): void
    {
        $connector = DataConnector::where('sub_institute_id', $this->tenantId)->whereKey($this->connectorId)->first();
        $sync = DataConnectorSync::where('sub_institute_id', $this->tenantId)->whereKey($this->syncId)->first();
        if (! $connector || ! $sync || $sync->status !== 'queued') {
            return;
        }

        $lock = Cache::lock("signals:connector-sync:{$this->tenantId}:{$this->connectorId}", 1800);
        if (! $lock->get()) {
            $this->finish($connector, $sync, 'skipped', 'Another sync is already running for this connector.');

            return;
        }

        try {
            if (! $connector->sync_enabled || $connector->connection_status === 'disconnected') {
                $this->finish($connector, $sync, 'skipped', 'This connector is disabled or disconnected.');
                return;
            }
            if ($connector->type !== 'public_web_page' && ! in_array($connector->connection_status, ['connected', 'rate_limited'], true)) {
                $this->finish($connector, $sync, 'failed', $connector->last_error ?: 'Complete platform authorization before syncing.');

                return;
            }

            $product = ProductCatalog::normalise((string) $connector->product_key, $this->tenantId);
            if ($product === null) {
                $this->finish($connector, $sync, 'failed', 'The selected product is unknown.');

                return;
            }

            $sync->forceFill(['status' => 'running', 'started_at' => now()])->save();
            $adapter = $registry->get((string) $connector->type);
            $sources = [];
            if ($adapter instanceof SocialDataConnectorAdapter) {
                $items = $adapter->fetch($connector);
                $sync->forceFill(['records_fetched' => count($items)])->save();
                foreach ($items as $item) {
                    $alreadySeen = mb_strlen(trim($item->externalId)) <= 180
                        && IngestionSource::where('sub_institute_id', $this->tenantId)
                            ->where('connector_id', $connector->id)
                            ->where('external_content_id', trim($item->externalId))
                            ->exists();
                    $source = $ingestion->ingestSocialContent($connector, $sync, $item, $this->userId);
                    if ($source) {
                        $sources[] = $source;
                    } elseif ($alreadySeen) {
                        $sync->increment('records_duplicate');
                        $sync->increment('records_skipped');
                    } else {
                        $sync->increment('records_skipped');
                    }
                }
            } else {
                $hash = hash('sha256', 'url:' . mb_strtolower(trim((string) $connector->url)));
                $recent = IngestionSource::where('sub_institute_id', $this->tenantId)->where('content_hash', $hash)
                    ->where('created_at', '>=', now()->subSeconds(30))->orderByDesc('id')->first();
                if ($recent) {
                    $sync->forceFill([
                        'source_id' => $recent->id, 'records_fetched' => 1,
                        'records_skipped' => 1, 'records_duplicate' => 1,
                    ])->save();
                    $this->finish($connector, $sync, 'skipped', 'This page was submitted recently and is already being processed.');

                    return;
                }
                $source = $adapter->sync($connector, $this->userId);
                $source->forceFill([
                    'content_hash' => $hash,
                    'product_key' => $product,
                    'offer_id' => $connector->offer_id,
                    'customer_model' => $connector->customer_model ?: 'b2b',
                    'region_scope' => $connector->region_scope,
                    'connector_id' => $connector->id,
                    'connector_sync_id' => $sync->id,
                ])->save();
                $sources[] = $source;
                $sync->forceFill(['source_id' => $source->id, 'records_fetched' => 1])->save();
            }

            foreach ($sources as $source) {
                if (! $sync->source_id) {
                    $sync->forceFill(['source_id' => $source->id])->save();
                }
                $analysis = IngestionAnalysis::where('sub_institute_id', $this->tenantId)
                    ->where('source_id', $source->id)
                    ->whereIn('status', ['success', 'partial'])
                    ->first();
                if ($analysis) {
                    $sync->increment('records_duplicate');
                    $sync->increment('records_skipped');

                    continue;
                }

                $analysis = IngestionAnalyzer::begin($source, $this->userId);
                $analysis = $analyzer->analyze($source, $analysis);
                if (in_array($analysis->status, ['success', 'partial'], true)) {
                    $sync->increment('records_processed');
                    $sync->increment('records_created', (int) $analysis->opportunities_created);
                } else {
                    $sync->increment('records_failed');
                }
            }

            $sync->refresh();
            $status = $sync->records_failed > 0
                ? ($sync->records_processed > 0 ? 'partial' : 'failed')
                : ($sync->records_processed === 0 && $sync->records_skipped > 0 ? 'skipped' : 'completed');
            $message = $sync->records_failed > 0 ? 'One or more items could not be processed.' : null;
            $this->finish($connector, $sync, $status, $message);
        } catch (SocialConnectorApiException $e) {
            if (($e->connectionStatus === 'rate_limited' || $e->httpStatus >= 500) && $this->attempts() < $this->tries) {
                $delay = min(900, max(1, $e->retryAfterSeconds ?? $this->backoff()[$this->attempts() - 1]));
                $connector->forceFill([
                    'connection_status' => $e->connectionStatus === 'rate_limited' ? 'rate_limited' : $connector->connection_status,
                    'last_error' => $e->getMessage(),
                ])->save();
                $sync->forceFill(['status' => 'queued', 'error_message' => $e->getMessage()])->save();
                $this->release($delay);

                return;
            }
            $this->finish($connector, $sync, 'failed', $e->getMessage(), $e->connectionStatus);
        } catch (UnsafeUrlException $e) {
            $this->finish($connector, $sync, 'failed', $e->getMessage(), 'error');
        } catch (ConnectionException $e) {
            if ($this->attempts() < $this->tries) {
                $sync->forceFill(['status' => 'queued', 'error_message' => 'The platform could not be reached. The sync will retry.'])->save();
                $this->release($this->backoff()[$this->attempts() - 1]);

                return;
            }
            $this->finish($connector, $sync, 'failed', 'The platform could not be reached after multiple attempts.', 'error');
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $connector = DataConnector::where('sub_institute_id', $this->tenantId)->whereKey($this->connectorId)->first();
        $sync = DataConnectorSync::where('sub_institute_id', $this->tenantId)->whereKey($this->syncId)->first();
        if ($connector && $sync && in_array($sync->status, ['queued', 'running'], true)) {
            $this->finish($connector, $sync, 'failed', 'The connector sync failed unexpectedly. Please retry.', 'error');
            Log::warning('signals.connector_sync_failed', ['tenant' => $this->tenantId, 'connector' => $this->connectorId, 'exception' => $exception ? get_class($exception) : null]);
        }
    }

    private function finish(DataConnector $connector, DataConnectorSync $sync, string $status, ?string $message, ?string $connectionStatus = null): void
    {
        $attributes = [
            'status' => $status,
            'error_message' => $message ? mb_substr($message, 0, 500) : null,
            'completed_at' => now(),
        ];
        if ($status === 'failed' && (int) $sync->records_failed === 0) {
            $attributes['records_failed'] = max(1, (int) $sync->records_fetched);
        }
        $sync->forceFill($attributes)->save();

        $changes = [
            'last_sync_at' => now(),
            'last_error' => $message ? mb_substr($message, 0, 500) : null,
        ];
        if ($connectionStatus !== null) {
            $changes['connection_status'] = $connectionStatus;
        } elseif (in_array($status, ['completed', 'partial', 'skipped'], true)) {
            $changes['connection_status'] = 'connected';
            $changes['connected_at'] = $connector->connected_at ?: now();
            $changes['last_successful_sync_at'] = now();
            $changes['last_error'] = null;
        } elseif ($status === 'failed' && $connector->type === 'public_web_page') {
            $changes['connection_status'] = 'error';
        }
        if ($connector->schedule_enabled) {
            $changes['next_sync_at'] = now((string) ($connector->schedule_timezone ?: 'UTC'))
                ->addMinutes(max(15, (int) ($connector->schedule_interval_minutes ?: 60)));
        }
        $connector->forceFill($changes)->save();
    }
}
