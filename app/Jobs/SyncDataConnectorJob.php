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
use App\Domain\Signals\Ingestion\SyncNoLongerActiveException;
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

    /** TTL of the per-connector cache lock (and the longest a lost worker's lock can linger without the sweep). */
    private const LOCK_SECONDS = 1800;
    /** Minimum gap between throttled heartbeats, so a long item loop does not write on every iteration. */
    private const HEARTBEAT_MIN_INTERVAL_SECONDS = 10.0;

    private float $lastBeat = 0.0;

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

        $lock = Cache::lock(self::lockName($this->tenantId, $this->connectorId), self::LOCK_SECONDS);
        if (! $lock->get()) {
            $this->finish($connector, $sync, 'skipped', 'Another sync is already running for this connector.');

            return;
        }

        try {
            // Record whose lock this is, under a key of this sync's own id. The recovery sweep restores exactly this token to
            // free a lost worker's lock, so it can never free the lock of a newer sync (see DataConnectorSyncReaper).
            Cache::put(self::lockOwnerKey($this->syncId), $lock->owner(), self::LOCK_SECONDS + 300);

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

            // Claim the sync atomically: only the worker that moves it queued -> running proceeds. A second worker holding the
            // same job (a retry after a presumed-dead worker), or one arriving after the sync was closed, gets 0 rows and exits.
            $claimed = DataConnectorSync::where('sub_institute_id', $this->tenantId)
                ->whereKey($sync->id)->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            $this->lastBeat = microtime(true);   // the claim itself wrote updated_at
            $sync->refresh();
            $adapter = $registry->get((string) $connector->type);
            $sources = [];
            if ($adapter instanceof SocialDataConnectorAdapter) {
                $items = $adapter->fetch($connector);
                // Checkpoint after the provider fetch (the longest silent stretch of a social sync): still ours? then record the result.
                $this->heartbeat($sync, true);
                $sync->forceFill(['records_fetched' => count($items)])->save();
                // Store every post first (cheap), then find signals in BATCHES: one AI call covers several posts, so a sync of
                // dozens of posts takes a minute or two instead of many. A post stored by an earlier sync that never got analysed
                // (it failed, or the time budget ran out) is analysed now instead of being skipped for ever.
                $pending = [];
                foreach ($items as $item) {
                    $this->heartbeat($sync);
                    $externalId = trim($item->externalId);
                    $existing = mb_strlen($externalId) <= 180
                        ? IngestionSource::where('sub_institute_id', $this->tenantId)
                            ->where('connector_id', $connector->id)
                            ->where('external_content_id', $externalId)
                            ->first()
                        : null;
                    if ($existing) {
                        $analysed = IngestionAnalysis::where('sub_institute_id', $this->tenantId)
                            ->where('source_id', $existing->id)->whereIn('status', ['success', 'partial'])->exists();
                        if ($analysed) {
                            $sync->increment('records_duplicate');
                            $sync->increment('records_skipped');
                        } else {
                            $pending[] = $existing;
                        }

                        continue;
                    }
                    $source = $ingestion->ingestSocialContent($connector, $sync, $item, $this->userId);
                    if ($source) {
                        $pending[] = $source;
                    } else {
                        $sync->increment('records_skipped');
                    }
                }
                $summary = $this->analyseInBatches($sync, $pending);
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
                // Checkpoint after the website fetch (robots.txt, redirects and the page, all bounded by one total deadline).
                $this->heartbeat($sync, true);

                // Same page, same text as the last analysed copy: do not store or re-analyse (and re-bill) it.
                $previous = IngestionSource::where('sub_institute_id', $this->tenantId)
                    ->where('connector_id', $connector->id)
                    ->where('id', '<', $source->id)
                    ->whereIn('id', IngestionAnalysis::where('sub_institute_id', $this->tenantId)
                        ->whereIn('status', ['success', 'partial'])->select('source_id'))
                    ->orderByDesc('id')
                    ->first();
                if ($previous && hash_equals(hash('sha256', (string) $previous->segments), hash('sha256', (string) $source->segments))) {
                    $ingestion->delete($source);
                    $sync->forceFill([
                        'source_id' => $previous->id, 'records_fetched' => 1,
                        'records_skipped' => 1, 'records_duplicate' => 1,
                    ])->save();
                    $this->finish($connector, $sync, 'skipped', 'The page content has not changed since the last sync.');

                    return;
                }

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
                // Heartbeat: DataConnectorSyncReaper reads this row's updated_at to tell a working sync from a lost one.
                // It is a conditional write on status = running, so it can never reopen a closed sync; it also tells a worker
                // that was presumed lost (and replaced) to stop here.
                $this->heartbeat($sync, true);
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
                // The analyzer calls this before each AI chunk (throttled), the other long silent stretch of a sync.
                $analysis = $analyzer->analyze($source, $analysis, fn () => $this->heartbeat($sync));
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
            $message = $this->explainOutcome($sync, $summary ?? null) ?? $message;
            $this->finish($connector, $sync, $status, $message);
        } catch (SyncNoLongerActiveException $e) {
            // Our sync was closed under us (recovered as lost, or finished by a retry). Write nothing more: not the sync, not the
            // connector (a newer sync may own it now), and the finally below releases only this job's own lock.
            Log::warning('signals.connector_sync_abandoned', ['tenant' => $this->tenantId, 'connector' => $this->connectorId, 'sync' => $this->syncId]);
        } catch (SocialConnectorApiException $e) {
            if (($e->connectionStatus === 'rate_limited' || $e->httpStatus >= 500) && $this->attempts() < $this->tries) {
                $delay = min(900, max(1, $e->retryAfterSeconds ?? $this->backoff()[$this->attempts() - 1]));
                $connector->forceFill([
                    'connection_status' => $e->connectionStatus === 'rate_limited' ? 'rate_limited' : $connector->connection_status,
                    'last_error' => $e->getMessage(),
                ])->save();
                $this->requeue($sync, $e->getMessage());
                $this->release($delay);

                return;
            }
            $this->finish($connector, $sync, 'failed', $e->getMessage(), $e->connectionStatus);
        } catch (UnsafeUrlException $e) {
            $this->finish($connector, $sync, 'failed', $e->getMessage(), 'error');
        } catch (ConnectionException $e) {
            if ($this->attempts() < $this->tries) {
                $this->requeue($sync, 'The platform could not be reached. The sync will retry.');
                $this->release($this->backoff()[$this->attempts() - 1]);

                return;
            }
            $this->finish($connector, $sync, 'failed', 'The platform could not be reached after multiple attempts.', 'error');
        } finally {
            // Ownership-checked: releases the lock only if this job still holds it. A job whose lock expired or was freed by the
            // sweep, and whose connector has since been locked by a newer sync, cannot free that newer lock.
            $lock->release();
            Cache::forget(self::lockOwnerKey($this->syncId));
        }
    }

    /**
     * Finds signals in stored posts, several posts per AI call, and records them as company opportunities through the same gate
     * and importer as every other source. Stops starting new batches once the time budget is spent; the posts left over stay
     * un-analysed and are picked up by the next sync.
     *
     * @param  array<int, IngestionSource>  $pending
     */
    private function analyseInBatches(DataConnectorSync $sync, array $pending): array
    {
        $summary = ['ai_unavailable' => false, 'ai_stopped' => false, 'posts' => count($pending), 'candidates' => 0, 'rejected' => 0, 'qualified' => 0, 'left_for_next_sync' => 0];
        if ($pending === []) {
            return $summary;
        }
        // Without a usable AI nothing can be read for signals. Say so, and do NOT record the posts as analysed: they must be analysed
        // by the next sync once the AI is set up, not skipped for ever with a silent "0 signals".
        if (! app(\App\Domain\Signals\SignalRunner::class)->aiConfigured($this->tenantId)) {
            $sync->increment('records_failed', count($pending));
            $summary['ai_unavailable'] = true;

            return $summary;
        }
        $researcher = app(\App\Domain\Signals\Opportunities\OpportunityResearcher::class);
        $size = max(1, (int) config('signals_connectors.analysis_batch_size', 10));
        $deadline = microtime(true) + max(30, (int) config('signals_connectors.sync_time_budget_seconds', 240));
        $abort = false;
        foreach (array_chunk($pending, $size) as $batch) {
            $this->heartbeat($sync, true);
            if ($abort) {
                $sync->increment('records_failed', count($batch));
                $summary['left_for_next_sync'] += count($batch);

                continue;
            }
            if (microtime(true) > $deadline) {
                $sync->increment('records_skipped', count($batch));
                $summary['left_for_next_sync'] += count($batch);

                continue;
            }
            $started = now();
            try {
                $result = $researcher->leadsFromSources($batch, $this->userId);
            } catch (SyncNoLongerActiveException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $sync->increment('records_failed', count($batch));
                Log::warning('signals.connector_batch_failed', ['tenant' => $this->tenantId, 'connector' => $this->connectorId, 'exception' => get_class($e)]);
                $abort = $e instanceof \App\Domain\AI\Support\AiNotConfiguredException || $e instanceof \App\Domain\AI\Support\AiQuotaExceededException;
                $summary['ai_stopped'] = $summary['ai_stopped'] || $abort;
                $summary['left_for_next_sync'] += count($batch);

                continue;
            }
            foreach ($batch as $source) {
                IngestionAnalysis::create([
                    'sub_institute_id' => $this->tenantId, 'source_id' => $source->id, 'status' => 'success',
                    'triggered_by' => $this->userId, 'started_at' => $started, 'completed_at' => now(),
                ]);
            }
            $sync->increment('records_processed', count($batch));
            $sync->increment('records_created', (int) ($result['new'] ?? 0));
            $summary['candidates'] += (int) ($result['candidates'] ?? 0);
            $summary['rejected'] += (int) ($result['rejected'] ?? 0);
            $summary['qualified'] += (int) ($result['qualified'] ?? 0);
        }

        return $summary;
    }

    /**
     * One plain sentence for the run when the usual summary would hide the reason: no AI, an AI stop, time left over, or posts read with no signal.
     * Counts only, never post text. A signal is never created without evidence, so "none found" is explained, not forced.
     *
     * @param  array<string, mixed>|null  $summary
     */
    private function explainOutcome(DataConnectorSync $sync, ?array $summary): ?string
    {
        if ($summary === null || (int) ($summary['posts'] ?? 0) === 0) {
            return null;
        }
        if (! empty($summary['ai_unavailable'])) {
            return 'The AI is not set up for this organisation, so the posts could not be read for signals. Add a provider under AI & Intelligence > AI Providers, then click Sync Now. Nothing was lost: these posts will be analysed on the next sync.';
        }
        $parts = [];
        if (! empty($summary['ai_stopped'])) {
            $parts[] = 'The AI provider stopped (key missing or quota used up) part-way through.';
        }
        if ((int) ($summary['left_for_next_sync'] ?? 0) > 0) {
            $parts[] = (int) $summary['left_for_next_sync'] . ' post(s) were left for the next sync (time limit or AI stop); click Sync Now again to continue.';
        }
        if ((int) $sync->records_created === 0 && (int) ($summary['posts'] ?? 0) > (int) ($summary['left_for_next_sync'] ?? 0)) {
            $read = (int) $summary['posts'] - (int) $summary['left_for_next_sync'];
            $parts[] = sprintf(
                'No new signal: %d post(s) read, the AI proposed %d candidate signal(s), %d were rejected by the quality checks (a signal needs a named school or company, a stated need, and an exact quote from the post).',
                $read, (int) $summary['candidates'], (int) $summary['rejected']
            );
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** Cache lock that serialises syncs of one connector. */
    public static function lockName(int $tenantId, int $connectorId): string
    {
        return "signals:connector-sync:{$tenantId}:{$connectorId}";
    }

    /** Where a running job records its own lock token, so the sweep can release exactly that lock and no other. */
    public static function lockOwnerKey(int $syncId): string
    {
        return "signals:connector-sync-lock-owner:{$syncId}";
    }

    /**
     * Records that this sync is still alive, and confirms it is still ours. The write is conditional on `status = running`,
     * so it can never reopen or modify a closed sync; if the row is no longer running the job is told to stop.
     * Throttled (unless forced) so a long item loop does not write on every iteration.
     *
     * @throws SyncNoLongerActiveException
     */
    private function heartbeat(DataConnectorSync $sync, bool $force = false): void
    {
        $now = microtime(true);
        if (! $force && ($now - $this->lastBeat) < self::HEARTBEAT_MIN_INTERVAL_SECONDS) {
            return;
        }
        $alive = DataConnectorSync::where('sub_institute_id', $this->tenantId)
            ->whereKey($sync->id)->where('status', 'running')
            ->update(['updated_at' => now()]);
        if ($alive !== 1) {
            throw new SyncNoLongerActiveException();
        }
        $this->lastBeat = $now;
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

    /** Back to `queued` for a delayed retry, but only if the sync is still open (never resurrects one that was closed). */
    private function requeue(DataConnectorSync $sync, string $message): void
    {
        DataConnectorSync::where('sub_institute_id', $sync->sub_institute_id)
            ->whereKey($sync->id)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'queued', 'error_message' => mb_substr($message, 0, 500), 'updated_at' => now()]);
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
        // Compare-and-set on the stored status. If the sync was already closed (finished by a retry, or recovered by
        // DataConnectorSyncReaper after this worker was presumed lost), this late writer must not overwrite it, nor
        // touch the connector's state on its behalf.
        $closedNow = DataConnectorSync::where('sub_institute_id', $sync->sub_institute_id)
            ->whereKey($sync->id)
            ->whereIn('status', ['queued', 'running'])
            ->update($attributes + ['updated_at' => now()]);
        if ($closedNow !== 1) {
            return;
        }
        $sync->refresh();

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
