<?php

namespace App\Domain\Signals\AiSignals;

use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\ScanLog;
use Illuminate\Support\Facades\Log;

/**
 * Imports the deliveries a connector has waiting into the EXISTING signals pipeline, through the EXISTING market importer: the same quality gate,
 * the same duplicate and "same signal advanced" rules, the same scan log. No model is called here or by anything this class calls.
 *
 * Every signal is stored with `import_source = ai:<provider>` (so the list can say which AI it came from) and the original record stays in the delivery
 * (`payload`), with what happened to each record in `results` (what it was, why it was rejected and how to fix it, any look-alike organisation it was
 * NOT merged with). Dates must be ISO calendar dates (the gate's strict mode). A delivery that fails with an error is retried by a later sync, up to
 * MAX_ATTEMPTS; one stuck in `importing` after a worker died is retried once it has been silent for `stuck_minutes`. A re-import is safe: the importer
 * recognises what was already imported and counts it as a duplicate. A record the gate rejects is a verdict on the data, not an error to retry.
 */
final class AiDeliveryImporter
{
    private const MAX_ROUNDS = 5;

    public function __construct(private readonly MarketImporter $importer)
    {
    }

    /**
     * @param  callable|null  $beat  called between deliveries (the sync heartbeat)
     * @return array{deliveries: int, records: int, accepted: int, updated: int, duplicate: int, rejected: int, failed_deliveries: int, error: ?string}
     */
    public function process(DataConnector $connector, ?int $userId = null, ?callable $beat = null): array
    {
        $total = ['deliveries' => 0, 'records' => 0, 'accepted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected' => 0, 'failed_deliveries' => 0, 'error' => null];
        $provider = AiSignalProviders::keyFromType($connector->type);
        if ($provider === null || ! AiDeliveryReceiver::tableReady()) {
            $total['error'] = 'AI signal delivery is not set up on this server yet.';

            return $total;
        }

        $this->recoverStuck($connector);

        // A delivery that arrives while this runs is picked up by the next round, so it is never stranded behind a running sync.
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $pending = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->where('connector_id', $connector->id)
                ->where(fn ($q) => $q->where('status', 'received')->orWhere(fn ($r) => $r->where('status', 'failed')->where('attempts', '<', AiDelivery::MAX_ATTEMPTS)))
                ->orderBy('id')->limit(25)->get();
            if ($pending->isEmpty()) {
                break;
            }
            foreach ($pending as $delivery) {
                if ($beat) {
                    $beat();
                }
                $r = $this->import($connector, $delivery, $provider, $userId);
                $total['deliveries']++;
                foreach (['records', 'accepted', 'updated', 'duplicate', 'rejected'] as $k) {
                    $total[$k] += $r[$k];
                }
                if ($r['failed']) {
                    $total['failed_deliveries']++;
                }
            }
        }

        return $total;
    }

    /**
     * A delivery left `importing` by a worker that died (a deploy, a crash, a timeout) would otherwise wait for ever. After `stuck_minutes` without a write it is
     * put back to `received` and imported again, or, once it has had MAX_ATTEMPTS tries, marked failed with the reason. The conditional update means a delivery
     * a live worker is still writing to is never touched. This changes nothing about how a sync itself is recovered (DataConnectorSyncReaper).
     */
    private function recoverStuck(DataConnector $connector): void
    {
        $cutoff = now()->subMinutes(max(1, (int) config('signals_connectors.ai_delivery.stuck_minutes', 30)));
        $stale = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->where('connector_id', $connector->id)
            ->where('status', 'importing')->where('updated_at', '<', $cutoff)->get(['id', 'attempts']);
        foreach ($stale as $d) {
            $exhausted = (int) $d->attempts >= AiDelivery::MAX_ATTEMPTS;
            $changed = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->whereKey($d->id)->where('status', 'importing')->where('updated_at', '<', $cutoff)
                ->update($exhausted
                    ? ['status' => 'failed', 'error_message' => 'The import was interrupted and has been tried ' . AiDelivery::MAX_ATTEMPTS . ' times. Check the Signals queue worker, then paste the output again.']
                    : ['status' => 'received', 'error_message' => 'Retried after an interrupted import. Signals imported before it stopped are counted as already known.']);
            if ($changed === 1) {
                Log::warning('signals.ai_delivery_recovered', ['tenant' => $connector->sub_institute_id, 'connector' => $connector->id, 'delivery' => $d->id, 'gave_up' => $exhausted]);
            }
        }
    }

    /** @return array{records: int, accepted: int, updated: int, duplicate: int, rejected: int, failed: bool} */
    private function import(DataConnector $connector, AiDelivery $delivery, string $provider, ?int $userId): array
    {
        $zero = ['records' => 0, 'accepted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected' => 0, 'failed' => true];
        $wasRetry = str_starts_with((string) $delivery->error_message, 'Retried after');
        $delivery->forceFill(['status' => 'importing', 'attempts' => (int) $delivery->attempts + 1])->save();
        try {
            $parsed = AiSignalNormalizer::parse((string) $delivery->payload);
            if (! $parsed['ok']) {
                $delivery->forceFill(['status' => 'failed', 'attempts' => AiDelivery::MAX_ATTEMPTS, 'error_message' => (string) $parsed['error']])->save();

                return $zero;
            }
            $tenant = (int) $connector->sub_institute_id;
            $res = $this->importer->import($tenant, $parsed['records'], [
                'source_label' => AiSignalProviders::sourceLabel($provider), 'origin' => 'ai-delivery', 'user_id' => $userId, 'strict_dates' => true,
            ]);
            $items = $this->items($tenant, $parsed['records'], $res);
        } catch (\Throwable $e) {
            $delivery->forceFill(['status' => 'failed', 'error_message' => mb_substr(class_basename($e) . ': ' . $e->getMessage(), 0, 480)])->save();

            return $zero;
        }

        $good = $res['accepted'] + $res['updated'] + $res['duplicate'];
        $everyRecordRejected = $res['received'] > 0 && $good === 0;
        $delivery->forceFill([
            'status' => $everyRecordRejected ? 'failed' : ($res['rejected'] > 0 ? 'partial' : 'imported'),
            // Rejected by the quality gate is a verdict on the data, not an error to retry.
            'attempts' => $everyRecordRejected ? AiDelivery::MAX_ATTEMPTS : $delivery->attempts,
            'records_total' => $res['received'], 'records_accepted' => $res['accepted'], 'records_updated' => $res['updated'],
            'records_duplicate' => $res['duplicate'], 'records_rejected' => $res['rejected'], 'scan_log_id' => $res['scan_log_id'],
            'results' => json_encode(array_slice($items, 0, 500), JSON_UNESCAPED_UNICODE),
            'error_message' => $everyRecordRejected
                ? 'Every signal in this delivery was rejected by the quality checks. See the reasons.'
                : ($wasRetry ? 'Re-imported after an interrupted import. Signals imported before it stopped are counted as already known.' : null),
            'imported_at' => now(),
        ])->save();

        return ['records' => $res['received'], 'accepted' => $res['accepted'], 'updated' => $res['updated'], 'duplicate' => $res['duplicate'], 'rejected' => $res['rejected'], 'failed' => $everyRecordRejected];
    }

    /**
     * The contacts a record carries (`contacts`: a list), stored for the signal's organisation exactly like the contacts the public-contact lookup finds:
     * tenant-scoped, `unverified`, shown on the signal with the page each came from. Nothing is made up or completed here:
     *   - a contact with no http(s) `source_url` (the page that printed it) is dropped;
     *   - an email or phone is kept only with a source page (its own, or the contact's), and still has to pass the same email / phone checks as everywhere else;
     *   - a `linkedin_url` must be a linkedin.com address; a `website` must be http(s);
     *   - at most 10 contacts per signal. A problem here never fails the import.
     *
     * @param  array<string, mixed>  $record
     */
    private function saveContacts(int $tenant, int $opportunityId, array $record): void
    {
        $list = $record['contacts'] ?? null;
        if (! is_array($list) || $list === [] || ! array_is_list($list)) {
            return;
        }
        try {
            $clean = [];
            foreach (array_slice($list, 0, 10) as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $url = is_scalar($c['source_url'] ?? null) ? trim((string) $c['source_url']) : '';
                if (preg_match('~^https?://~i', $url) !== 1) {
                    continue;   // no page it came from, no contact
                }
                $row = ['source_url' => $url, 'role_searched' => 'ai-delivery'];
                foreach (['full_name', 'designation', 'email', 'phone', 'linkedin_url', 'website', 'location', 'email_source_url', 'phone_source_url'] as $f) {
                    if (isset($c[$f]) && is_scalar($c[$f]) && trim((string) $c[$f]) !== '') {
                        $row[$f] = trim((string) $c[$f]);
                    }
                }
                foreach (['email_source_url', 'phone_source_url'] as $f) {
                    if (isset($row[$f]) && preg_match('~^https?://~i', $row[$f]) !== 1) {
                        unset($row[$f]);
                    }
                }
                if (isset($row['email'])) {
                    $row['email_source_url'] ??= $url;
                }
                if (isset($row['phone'])) {
                    $row['phone_source_url'] ??= $url;
                }
                if (isset($row['linkedin_url']) && preg_match('~^https?://([a-z0-9-]+\.)*linkedin\.com/~i', $row['linkedin_url']) !== 1) {
                    unset($row['linkedin_url']);
                }
                if (isset($row['website']) && preg_match('~^https?://~i', $row['website']) !== 1) {
                    unset($row['website']);
                }
                $clean[] = $row;
            }
            if ($clean === []) {
                return;
            }
            $companyId = (int) \App\Domain\Signals\Opportunities\CompanyOpportunity::where('sub_institute_id', $tenant)->whereKey($opportunityId)->value('company_id');
            if ($companyId > 0) {
                \App\Domain\Signals\Opportunities\SignalContactStore::save($tenant, $companyId, $opportunityId, $clean);
            }
        } catch (\Throwable $e) {
            Log::warning('signals.ai_contacts_failed', ['tenant' => $tenant, 'error' => class_basename($e)]);
        }
    }

    /**
     * What happened to each record, for the delivery's history, and the classification of each one that was stored.
     *
     * @param  list<mixed>  $records
     * @param  array<string, mixed>  $res  the market importer's answer
     * @return list<array<string, mixed>>
     */
    private function items(int $tenant, array $records, array $res): array
    {
        $notes = $res['scan_log_id'] ? (ScanLog::where('sub_institute_id', $tenant)->whereKey($res['scan_log_id'])->first()?->notes ?? []) : [];
        $suggest = [];
        foreach ((array) ($notes['buyer_suggestions'] ?? []) as $s) {
            $suggest[(int) ($s['index'] ?? -1)] = ['id' => $s['id'] ?? null, 'name' => $s['name'] ?? null, 'similarity' => $s['similarity'] ?? null];
        }
        $conflicts = [];
        foreach ((array) ($notes['alias_conflicts'] ?? []) as $c) {
            $conflicts[(int) ($c['index'] ?? -1)][] = (string) ($c['alias'] ?? '');
        }

        $items = [];
        foreach ($res['results'] as $x) {
            $i = (int) ($x['index'] ?? -1);
            $record = is_array($records[$i] ?? null) ? $records[$i] : null;
            $label = $record !== null && is_array($record['buyer'] ?? null) ? trim((string) ($record['buyer']['name'] ?? '')) : '';
            $outcome = (string) ($x['outcome'] ?? '');

            // Classify what was stored: only from what the record itself said, only into empty columns. It never fails an import.
            if ($record !== null && in_array($outcome, ['accepted', 'updated', 'duplicate'], true) && ! empty($x['id'])) {
                try {
                    AiSignalScope::fillGaps($tenant, (int) $x['id'], AiSignalScope::forRecord($record, $tenant));
                } catch (\Throwable $e) {
                    Log::warning('signals.ai_scope_failed', ['tenant' => $tenant, 'error' => class_basename($e)]);
                }
                $this->saveContacts($tenant, (int) $x['id'], $record);
            }

            $item = [
                'index' => $i, 'outcome' => $outcome, 'id' => $x['id'] ?? null, 'label' => $label !== '' ? mb_substr($label, 0, 120) : null,
                'reason_code' => $x['reason_code'] ?? null, 'reason' => isset($x['reason']) ? mb_substr((string) $x['reason'], 0, 600) : null,
                'hint' => $outcome === 'rejected' ? AiRejectionHints::for($x['reason_code'] ?? null) : null,
            ];
            if (isset($suggest[$i])) {
                $item['possible_duplicate'] = $suggest[$i];   // reported, never merged
            }
            if (! empty($conflicts[$i])) {
                $item['alias_conflicts'] = $conflicts[$i];
            }
            $items[] = $item;
        }

        return $items;
    }
}
