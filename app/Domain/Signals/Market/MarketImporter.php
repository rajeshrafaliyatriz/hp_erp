<?php

namespace App\Domain\Signals\Market;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use Illuminate\Support\Facades\DB;

/**
 * Imports structured demand-side records (the daily scan's output) into the opportunity
 * pipeline: g2g_company_opportunities, with the buyer in g2g_companies.
 *
 * One service behind three entry points (API endpoint, `signals:import-market`, tests), so
 * they cannot drift apart. Per record the outcome is exactly one of:
 *
 *   accepted  — new opportunity stored
 *   updated   — the same signal advanced (a later date, a moved deadline, stronger evidence)
 *   duplicate — the same signal, nothing new; never re-flagged
 *   rejected  — failed a gate; stored with its payload in g2g_signal_import_rejections
 *
 * Every import writes one g2g_signal_scan_log row. The tenant is always passed in by the caller
 * (the token, or --tenant on the command line); a record can never name its own tenant.
 */
class MarketImporter
{
    /** Imported trigger types mapped onto the categories the existing feed and filters know. */
    private const CATEGORY = [
        'tender' => 'project', 'rfp' => 'project', 'eoi' => 'project', 'rfq' => 'project', 'programme' => 'project',
        'regulation' => 'regulatory', 'expansion' => 'expansion', 'funding' => 'funding', 'acquisition' => 'funding',
        'leadership' => 'leadership_change', 'hiring' => 'hiring', 'partnership' => 'project', 'other' => 'project',
    ];

    public function __construct(private readonly QualificationGate $gate, private readonly BuyerResolver $buyers)
    {
    }

    /**
     * @param  list<mixed>  $records
     * @param  array{source_label?: ?string, origin?: string, user_id?: ?int, dry_run?: bool, ingestion_source_id?: ?int}  $meta
     * @return array{scan_log_id: ?int, received: int, accepted: int, updated: int, duplicate: int, rejected: int, results: list<array<string, mixed>>}
     */
    public function import(int $tenantId, array $records, array $meta = []): array
    {
        $dryRun = (bool) ($meta['dry_run'] ?? false);

        $scan = $dryRun ? null : ScanLog::create([
            'sub_institute_id' => $tenantId, 'ran_at' => now(), 'source_label' => $meta['source_label'] ?? null,
            'origin' => $meta['origin'] ?? 'api', 'records_received' => count($records), 'created_by' => $meta['user_id'] ?? null,
        ]);

        $out = ['scan_log_id' => $scan?->id, 'received' => count($records), 'accepted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected' => 0, 'results' => []];
        $notes = ['evidence_downgrades' => 0, 'buyer_suggestions' => [], 'alias_conflicts' => []];

        foreach (array_values($records) as $i => $raw) {
            $verdict = is_array($raw) ? $this->gate->evaluate($raw) : ['ok' => false, 'code' => 'invalid_record', 'reason' => 'The record is not an object.'];

            if (! $verdict['ok']) {
                $out['rejected']++;
                $out['results'][] = ['index' => $i, 'outcome' => 'rejected', 'reason_code' => $verdict['code'], 'reason' => $verdict['reason']];

                if (! $dryRun) {
                    ImportRejection::create([
                        'sub_institute_id' => $tenantId, 'scan_log_id' => $scan->id, 'row_index' => $i,
                        'reason_code' => $verdict['code'], 'reason' => mb_substr($verdict['reason'], 0, 500), 'payload' => is_array($raw) ? $raw : ['value' => $raw],
                    ]);
                }

                continue;
            }

            if ($dryRun) {
                $out['accepted']++;
                $out['results'][] = ['index' => $i, 'outcome' => 'valid', 'notes' => $verdict['notes']];

                continue;
            }

            $result = DB::transaction(fn () => $this->store($tenantId, $verdict['record'], $scan->id, $meta));
            $out[$result['outcome']]++;
            $out['results'][] = ['index' => $i] + $result['public'];

            $notes['evidence_downgrades'] += count($verdict['notes']);
            if ($result['suggestion'] !== null) {
                $notes['buyer_suggestions'][] = ['index' => $i, 'new_buyer' => $verdict['record']['buyer']['name']] + $result['suggestion'];
            }
            foreach ($result['alias_conflicts'] as $alias) {
                $notes['alias_conflicts'][] = ['index' => $i, 'alias' => $alias];
            }
        }

        $scan?->update([
            'records_accepted' => $out['accepted'], 'records_updated' => $out['updated'],
            'records_duplicate' => $out['duplicate'], 'records_rejected' => $out['rejected'], 'notes' => $notes,
        ]);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r  a record that passed the gates
     * @return array{outcome: string, public: array<string, mixed>, suggestion: ?array, alias_conflicts: list<string>}
     */
    private function store(int $tenantId, array $r, int $scanLogId, array $meta): array
    {
        $resolved = $this->buyers->resolve($tenantId, $r['buyer']);
        $company = $resolved['company'];

        $fingerprint = hash('sha256', implode('|', [$tenantId, $company->id, $r['trigger_type'], $r['reference_no'] ?? $this->urlKey($r['source_url'])]));
        $existing = CompanyOpportunity::where('sub_institute_id', $tenantId)->where('fingerprint', $fingerprint)->first();

        $base = ['suggestion' => $resolved['suggestion'], 'alias_conflicts' => $resolved['alias_conflicts']];

        if ($existing === null) {
            $opportunity = CompanyOpportunity::create($this->columns($tenantId, $company->id, $r, $fingerprint, $meta));

            $this->event($tenantId, $opportunity->id, 'created', ['claim_level' => [null, $r['claim_level']], 'fetch_level' => [null, $r['fetch_level']]], $scanLogId);
            if ($r['evidence_note'] !== null) {
                $this->event($tenantId, $opportunity->id, 'evidence_downgraded', ['claim_level' => ['confirmed', 'inference'], 'note' => $r['evidence_note']], $scanLogId);
            }

            return ['outcome' => 'accepted', 'public' => ['outcome' => 'accepted', 'id' => $opportunity->id, 'buyer_id' => $company->id, 'buyer_created' => $resolved['created']], ...$base];
        }

        $stored = [
            'event_date' => $existing->event_date?->toDateString(), 'expires_at' => $existing->expires_at?->toDateString(),
            'claim_level' => $existing->claim_level, 'fetch_level' => $existing->fetch_level,
        ];

        if (! QualificationGate::hasAdvanced($stored, $r)) {
            return ['outcome' => 'duplicate', 'public' => ['outcome' => 'duplicate', 'id' => $existing->id, 'reason' => 'Unchanged: nothing new since it was last imported.'], ...$base];
        }

        $new = $this->columns($tenantId, $company->id, $r, $fingerprint, $meta);
        $changes = [];
        foreach (['event_date', 'expires_at', 'claim_level', 'fetch_level', 'trigger_summary', 'score_total', 'entry_point'] as $field) {
            $old = $existing->{$field};
            $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;
            if (($new[$field] ?? null) !== null && (string) $new[$field] !== (string) $old) {
                $changes[$field] = [$old, $new[$field]];
            }
        }

        // Re-imports refresh the evidence fields; they never touch review_status / pipeline_status
        // (what a person decided) or first_discovered_at.
        unset($new['review_status'], $new['pipeline_status'], $new['first_discovered_at'], $new['fingerprint']);
        $existing->forceFill(array_filter($new, fn ($v) => $v !== null))->save();

        $this->event($tenantId, $existing->id, 'updated', $changes, $scanLogId);

        return ['outcome' => 'updated', 'public' => ['outcome' => 'updated', 'id' => $existing->id, 'changes' => array_keys($changes)], ...$base];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function columns(int $tenantId, int $companyId, array $r, string $fingerprint, array $meta): array
    {
        $scores = $r['scores'];
        $total = $scores['total'] ?? null;
        $expires = $r['expires_at'];
        $daysLeft = $expires === null ? null : (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($expires)->startOfDay(), false);

        $claim = $r['claim_level'];
        $fact = $r['what_happened'] ?? $r['trigger_summary'];

        $row = [
            'sub_institute_id' => $tenantId, 'company_id' => $companyId, 'research_run_id' => null,
            'ingestion_source_id' => $meta['ingestion_source_id'] ?? null,
            'title' => mb_substr($r['trigger_summary'], 0, 191),
            'category' => self::CATEGORY[$r['trigger_type']] ?? 'project', 'signal_kind' => 'opportunity',
            'observed_event' => $fact, 'why_indicates_need' => $r['likely_problem'] ?? $r['trigger_summary'],
            'product_fit' => $r['what_we_could_sell'] ?? '', 'recommended_action' => $r['what_we_could_sell'] ?? '',
            'priority' => $total === null ? 'Low' : ($total >= 28 ? 'High' : ($total >= 20 ? 'Medium' : 'Low')),
            'urgency' => $expires === null ? null : ($daysLeft < 0 ? 'Expired' : 'Closes ' . \Illuminate\Support\Carbon::parse($expires)->format('d M Y')),
            'feed_section' => ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 14) ? 'immediate_action' : ($claim === 'confirmed' ? 'market_intelligence' : 'watchlist'),
            'qualification' => $claim === 'confirmed'
                ? (in_array($r['trigger_type'], ['tender', 'rfp', 'eoi', 'rfq'], true) ? 'Relevant Requirement Found' : 'New Opportunity')
                : ($claim === 'inference' ? 'Needs Verification' : 'Monitoring'),
            'confidence' => ['confirmed' => 'High', 'inference' => 'Medium', 'hypothesis' => 'Low'][$claim],
            'review_status' => 'New', 'pipeline_status' => 'NEW',
            'sources' => [['url' => $r['source_url'], 'title' => $r['source_title'], 'published_at' => $r['event_date'], 'excerpt' => null, 'retrieved_at' => now()->toDateTimeString()]],
            'confirmed_facts' => $claim === 'confirmed' && $r['what_happened'] ? [$r['what_happened']] : [],
            'unverified_claims' => $claim !== 'confirmed' ? [$fact] : [],
            'source_published_at' => $r['event_date'], 'event_date' => $r['event_date'], 'fingerprint' => $fingerprint,
            'first_discovered_at' => now(), 'last_verified_at' => now(),
            'trigger_type' => $r['trigger_type'], 'trigger_summary' => $r['trigger_summary'], 'reference_no' => $r['reference_no'],
            'source_url' => $r['source_url'], 'source_title' => $r['source_title'], 'observed_at' => $r['observed_at'], 'expires_at' => $expires,
            'claim_level' => $claim, 'fetch_level' => $r['fetch_level'], 'evidence_note' => $r['evidence_note'],
            'business_fit' => $r['business_fit'], 'candidate_need_codes' => $r['candidate_need_codes'], 'buyer_segment' => $r['buyer_segment'],
            'likely_problem' => $r['likely_problem'], 'likely_stakeholder' => $r['likely_stakeholder'], 'what_we_could_sell' => $r['what_we_could_sell'],
            'entry_point' => $r['entry_point'], 'scale' => $r['scale'], 'estimated_value' => $r['estimated_value'],
            'is_government_track' => $r['is_government_track'], 'partner_route_note' => $r['partner_route_note'], 'soft_marketing_angle' => $r['soft_marketing_angle'],
            'score_total' => $total, 'score_reasoning' => $r['score_reasoning'],
            'import_source' => isset($meta['source_label']) ? mb_substr((string) $meta['source_label'], 0, 100) : null,
            'is_sample' => $r['is_sample'],
        ];

        foreach (QualificationGate::SCORE_KEYS as $key) {
            $row['score_' . $key] = $scores[$key] ?? null;
        }

        return $row;
    }

    /** @param array<string, mixed> $changes */
    private function event(int $tenantId, int $opportunityId, string $type, array $changes, int $scanLogId): void
    {
        OpportunityEvent::create(['sub_institute_id' => $tenantId, 'opportunity_id' => $opportunityId, 'event_type' => $type, 'changes' => $changes, 'scan_log_id' => $scanLogId]);
    }

    private function urlKey(string $url): string
    {
        $p = parse_url(strtolower($url));

        return preg_replace('/^www\./', '', $p['host'] ?? '') . rtrim($p['path'] ?? '', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
