<?php

namespace App\Domain\Signals\Market;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Portfolio\OpportunityMatch;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ResearchRun;
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
        'vendor_search' => 'technology_change', 'pain_point' => 'pain_point',
    ];

    public function __construct(
        private readonly QualificationGate $gate,
        private readonly BuyerResolver $buyers,
        private readonly MatchingEngine $matcher,
        private readonly SectionAssigner $sections = new SectionAssigner(),
    ) {
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

        // The same organisation, event and page can be a signal for two products: each product keeps its own record. (The school product's
        // fingerprint formula is unchanged, so its existing records still match.)
        $fit = ($r['business_fit'] ?? null) !== 'scholar' ? ($r['business_fit'] ?? null) : null;
        $scopeKey = isset($meta['research_run_id']) && \App\Support\ColumnExists::has('g2g_research_runs', 'scope_key')
            ? ResearchRun::where('sub_institute_id', $tenantId)->whereKey($meta['research_run_id'])->value('scope_key')
            : null;
        $fingerprintParts = [$tenantId, $company->id, $r['trigger_type'], $r['reference_no'] ?? $this->urlKey($r['source_url']), $fit];
        if (is_string($scopeKey) && $scopeKey !== '') $fingerprintParts[] = $scopeKey;
        $fingerprint = hash('sha256', implode('|', array_filter($fingerprintParts, fn ($v) => $v !== null)));
        $existing = CompanyOpportunity::where('sub_institute_id', $tenantId)->where('fingerprint', $fingerprint)->first();
        $legacy = false;
        if ($existing === null) {
            // Rows the web-research pipeline wrote before it used this importer carry a different
            // fingerprint formula and no trigger_type. Match them on company + source URL so the first
            // run through the gate enriches them instead of duplicating them.
            $existing = $scopeKey === null ? $this->legacyMatch($tenantId, $company->id, $r['source_url']) : null;
            $legacy = $existing !== null;
        }

        $base = ['suggestion' => $resolved['suggestion'], 'alias_conflicts' => $resolved['alias_conflicts']];

        // Same fingerprint (for example a tender reference) but a source this opportunity does not have yet: corroboration.
        if ($existing !== null && ! $legacy && ! $this->hasSource($existing, $r['source_url'])) {
            return $this->corroborate($tenantId, $existing, $company, $r, $scanLogId, $base);
        }

        // One event reported on two sites is ONE opportunity. The fingerprint includes the source URL, so a second URL
        // never matches it: look for the same event instead (same buyer, same trigger, dates within a week; or the
        // same tender reference). A hit from a new URL corroborates the existing opportunity.
        if ($existing === null) {
            $sameEvent = $this->sameEvent($tenantId, $company->id, $r, $scopeKey);
            if ($sameEvent !== null) {
                return $this->corroborate($tenantId, $sameEvent, $company, $r, $scanLogId, $base);
            }
        }

        if ($existing === null) {
            $opportunity = CompanyOpportunity::create($this->columns($tenantId, $company->id, $r, $fingerprint, $meta));

            $created = ['claim_level' => [null, $r['claim_level']], 'fetch_level' => [null, $r['fetch_level']]];
            if (($r['entry_point_reason'] ?? null) !== null) {
                $created['entry_point_reason'] = $r['entry_point_reason'];
            }
            $this->event($tenantId, $opportunity->id, 'created', $created, $scanLogId);
            if ($r['evidence_note'] !== null) {
                $this->event($tenantId, $opportunity->id, 'evidence_downgraded', ['claim_level' => ['confirmed', 'inference'], 'note' => $r['evidence_note']], $scanLogId);
            }

            $matches = $this->snapshotMatches($tenantId, $opportunity);

            return ['outcome' => 'accepted', 'public' => ['outcome' => 'accepted', 'id' => $opportunity->id, 'buyer_id' => $company->id, 'buyer_created' => $resolved['created'], 'matches' => $matches], ...$base];
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
        foreach (['event_date', 'expires_at', 'claim_level', 'fetch_level', 'trigger_summary', 'score_total', 'entry_point', 'what_we_could_sell', 'feed_section'] as $field) {
            $old = $existing->{$field};
            $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;
            if (($new[$field] ?? null) !== null && (string) $new[$field] !== (string) $old) {
                $changes[$field] = [$old, $new[$field]];
            }
        }

        // Re-imports refresh the evidence fields; they never touch review_status / pipeline_status
        // (what a person decided) or first_discovered_at.
        unset($new['review_status'], $new['pipeline_status'], $new['first_discovered_at']);
        if (! $legacy) {
            unset($new['fingerprint']);
        }
        // first_discovered_at is assigned to ITSELF in the UPDATE. Migration 2026_10_08_110000
        // removes the column's ON UPDATE clause, but until it has run (or if it never does) any
        // UPDATE that omits the column makes MySQL/MariaDB reset it to now(). An explicit
        // assignment prevents that, and Eloquent would drop an unchanged attribute from the SQL,
        // so this goes through the query builder with the model's own cast-encoded values.
        $existing->forceFill(array_filter($new, fn ($v) => $v !== null));
        CompanyOpportunity::where('id', $existing->id)->update(
            $existing->getDirty() + [
                'first_discovered_at' => DB::raw(DB::getQueryGrammar()->wrap('first_discovered_at')),
                'updated_at' => now(),
            ]
        );
        $this->event($tenantId, $existing->id, 'updated', $changes, $scanLogId);
        $matches = $this->snapshotMatches($tenantId, $existing->refresh());

        return ['outcome' => 'updated', 'public' => ['outcome' => 'updated', 'id' => $existing->id, 'changes' => array_keys($changes), 'matches' => $matches], ...$base];
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

        // A ScholarClone signal carries its own strength (hot | warm | watch). Everything else keeps the score-based rule.
        $strength = $r['strength'] ?? null;
        $context = $r['context'] ?? null;
        $section = $strength !== null && $context === null
            ? SignalStrength::SECTION[$strength]
            : ($this->sections->assign(['claim_level' => $claim, 'fetch_level' => $r['fetch_level'], 'score_total' => $total, 'context' => $context], $daysLeft) ?? SectionAssigner::WATCHING);

        $row = [
            'sub_institute_id' => $tenantId, 'company_id' => $companyId, 'research_run_id' => $meta['research_run_id'] ?? null,
            'ingestion_source_id' => $meta['ingestion_source_id'] ?? null,
            'title' => mb_substr($r['headline'] ?? $r['trigger_summary'], 0, 191),
            'category' => self::CATEGORY[$r['trigger_type']] ?? 'project', 'signal_kind' => 'opportunity',
            'observed_event' => $fact, 'why_indicates_need' => $r['likely_problem'] ?? $r['trigger_summary'],
            'product_fit' => $r['what_we_could_sell'] ?? '', 'recommended_action' => $r['what_we_could_sell'] ?? '',
            'priority' => $strength !== null
                ? ['hot' => 'High', 'warm' => 'Medium', 'watch' => 'Low'][$strength]
                : ($total === null ? 'Low' : ($total >= 28 ? 'High' : ($total >= 20 ? 'Medium' : 'Low'))),
            // Stored as a band (act_this_week | act_this_month | nurture); the deadline itself is expires_at.
            'urgency' => $strength !== null && $context === null
                ? SignalStrength::BAND[$strength]
                : (new CardPresenter())->band($daysLeft, $scores['urgency'] ?? null, $section),
            // Evidence decides the section, not urgency alone: only a confirmed claim read from the
            // document or page text, and not yet expired, is eligible for Immediate action. Everything
            // else (inference, hypothesis, a snippet or a blocked page) is Watchlist. Expired items are
            // hidden by the feed unless the "Expired" filter is on.
            'feed_section' => $section,
            'qualification' => $this->qualificationFor($claim, $r['trigger_type']),
            'confidence' => $this->confidenceFor($claim),
            'review_status' => 'New', 'pipeline_status' => 'NEW',
            'sources' => [['url' => $r['source_url'], 'title' => $r['source_title'], 'published_at' => $r['event_date'], 'excerpt' => $r['source_excerpt'] ?? null, 'retrieved_at' => now()->toDateTimeString(), 'content_hash' => $r['content_hash'] ?? null]],
            'confirmed_facts' => $claim === 'confirmed' && $r['what_happened'] ? [$r['what_happened']] : [],
            'unverified_claims' => $claim !== 'confirmed' ? [$fact] : [],
            'source_published_at' => $r['source_published_at'] ?? $r['event_date'], 'event_date' => $r['event_date'], 'fingerprint' => $fingerprint,
            'first_discovered_at' => now(), 'last_verified_at' => now(),
            'trigger_type' => $r['trigger_type'], 'trigger_summary' => $r['trigger_summary'], 'reference_no' => $r['reference_no'],
            'source_url' => $r['source_url'], 'source_title' => $r['source_title'], 'observed_at' => $r['observed_at'], 'expires_at' => $expires,
            'claim_level' => $claim, 'fetch_level' => $r['fetch_level'], 'evidence_note' => $r['evidence_note'],
            'business_fit' => $r['business_fit'], 'candidate_need_codes' => $r['candidate_need_codes'], 'buyer_segment' => $r['buyer_segment'],
            'likely_problem' => $r['likely_problem'], 'likely_stakeholder' => $r['likely_stakeholder'], 'what_we_could_sell' => $r['what_we_could_sell'],
            'entry_point' => $r['entry_point'], 'scale' => $r['scale'], 'estimated_value' => $r['estimated_value'],
            'is_government_track' => $r['is_government_track'], 'partner_route_note' => $r['partner_route_note'], 'soft_marketing_angle' => $r['soft_marketing_angle'],
            'score_total' => $total, 'score_reasoning' => $r['strength_reason'] ?? $r['score_reasoning'],
            'import_source' => isset($meta['source_label']) ? mb_substr((string) $meta['source_label'], 0, 100) : null,
            'is_sample' => $r['is_sample'],
            'buying_intent' => $r['buying_intent'] ?? null,
            'assessment' => $r['assessment'] ?? null,
        ];

        foreach (QualificationGate::SCORE_KEYS as $key) {
            $row['score_' . $key] = $scores[$key] ?? null;
        }

        return $row;
    }

    /**
     * Store (or refresh) the offer matches for one opportunity, with the readiness snapshot.
     *
     * Rows a person has already reviewed keep their status and notes: only the score and the
     * snapshot fields are refreshed. Readiness never changes a score; it only sets
     * is_deliverable / readiness_at_match so a strong match to an unverified offer is visible.
     *
     * @return int number of matches stored or refreshed
     */
    private function snapshotMatches(int $tenantId, CompanyOpportunity $opportunity): int
    {
        // Off by default: set SIGNALS_SCHOLAR_ONLY=true to skip offer matching entirely.
        if ((bool) config('signals.scholar_only', false)) {
            return 0;
        }

        $min = (int) config('signals.matching.store_min_score', 30);
        $count = 0;

        // The offering the research named for this signal (a child product, checked against the product's own list when it was found) is the
        // explicit match; the keyword scoring then ranks the others around it. Only a real portfolio offer counts: a part of the ICP is not one.
        $signal = $opportunity;
        $namedOffer = null;
        $named = strtolower((string) ($opportunity->assessment['product_fit']['part'] ?? ''));
        if ($named !== '') {
            $offerId = \App\Domain\Portfolio\ProductOffer::query()->forTenant($tenantId)->whereRaw('lower(offer_id) = ?', [$named])->value('offer_id');
            if ($offerId !== null) {
                $signal = clone $opportunity;   // a copy: the extra attribute is read by the matcher and never saved
                $signal->relevant_offer_id = $offerId;
                $namedOffer = $offerId;
            }
        }

        foreach ($this->matcher->matchForSignal($signal, 'opportunity', $tenantId)['offer_matches'] as $m) {
            if ($m['match_score'] < $min) {
                continue;
            }
            // Another product is kept beside the one the research named only when it matches clearly: shared keywords alone are not a match.
            if ($namedOffer !== null && $m['offer']->offer_id !== $namedOffer && $m['match_score'] < (int) config('signals.matching.secondary_min_score', 45)) {
                continue;
            }

            $offer = $m['offer'];
            $attrs = [
                'matched_need_codes' => $m['matched_need_codes'],
                'match_score' => $m['match_score'],
                'readiness_at_match' => $offer->readiness_status,
                'is_deliverable' => (bool) $offer->readiness_confirmed,
                'confidence' => round($m['match_score'] / 100, 2),
                'rationale' => mb_substr(implode(' ', $m['match_reasons']), 0, 1000),
            ];
            $where = ['sub_institute_id' => $tenantId, 'signal_type' => 'opportunity', 'signal_id' => $opportunity->id, 'offer_id' => $offer->offer_id];

            $row = OpportunityMatch::where($where)->first();
            $row ? $row->forceFill($attrs)->save() : OpportunityMatch::create($where + ['match_status' => 'Matched'] + $attrs);
            $count++;
        }

        return $count;
    }

    private const TENDER_TYPES = ['tender', 'rfp', 'eoi', 'rfq'];
    private const SAME_EVENT_DAYS = 7;

    private function qualificationFor(string $claim, string $triggerType): string
    {
        return $claim === 'confirmed'
            ? (in_array($triggerType, self::TENDER_TYPES, true) ? 'Relevant Requirement Found' : 'New Opportunity')
            : ($claim === 'inference' ? 'Needs Verification' : 'Monitoring');
    }

    private function confidenceFor(string $claim): string
    {
        return ['confirmed' => 'High', 'inference' => 'Medium', 'hypothesis' => 'Low'][$claim] ?? 'Low';
    }

    /** The date an event happened, or the date its source was published when the event has none. */
    private function effectiveDate(?string $eventDate, ?string $publishedAt): ?\Illuminate\Support\Carbon
    {
        foreach ([$eventDate, $publishedAt] as $d) {
            if ($d !== null && $d !== '') {
                try {
                    return \Illuminate\Support\Carbon::parse($d)->startOfDay();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * An opportunity already stored for the SAME event: same tenant and buyer, and either the same tender reference
     * (tender, RFP, EOI, RFQ) or the same trigger type with an event date within 7 days. Undated records never match
     * (no basis to say it is the same event). Existing rows' fingerprints are never rewritten: this is a lookup.
     *
     * @param  array<string, mixed>  $r
     */
    private function sameEvent(int $tenantId, int $companyId, array $r, ?string $scopeKey = null): ?CompanyOpportunity
    {
        $base = CompanyOpportunity::where('sub_institute_id', $tenantId)->where('company_id', $companyId)
            // Only the same product's records can be the same event: another product's signal about this organisation is its own.
            ->when(($r['business_fit'] ?? null) !== null, fn ($q) => $q->where('business_fit', $r['business_fit']));
        if ($scopeKey !== null && \App\Support\ColumnExists::has('g2g_research_runs', 'scope_key')) {
            $base->whereIn('research_run_id', ResearchRun::where('sub_institute_id', $tenantId)->where('scope_key', $scopeKey)->select('id'));
        }

        if (in_array($r['trigger_type'], self::TENDER_TYPES, true) && ($r['reference_no'] ?? null) !== null) {
            return $base->where('reference_no', $r['reference_no'])->whereIn('trigger_type', self::TENDER_TYPES)->orderBy('id')->first();
        }

        $date = $this->effectiveDate($r['event_date'] ?? null, $r['source_published_at'] ?? null);
        if ($date === null) {
            return $this->similarRecent($base, $r);
        }
        $from = $date->copy()->subDays(self::SAME_EVENT_DAYS)->toDateString();
        $to = $date->copy()->addDays(self::SAME_EVENT_DAYS)->toDateString();

        $hits = (clone $base)->where('trigger_type', $r['trigger_type'])
            ->where(fn ($q) => $q->whereBetween('event_date', [$from, $to])
                ->orWhere(fn ($w) => $w->whereNull('event_date')->whereBetween('source_published_at', [$from, $to])))
            ->orderBy('id')->limit(20)->get();

        // Both dated and outside the window: different events, however alike the words. Only an UNDATED mention falls back to wording.
        // Inside the window, a record built on a DIFFERENT product part is another need of the same buyer (a school hiring an ERP
        // administrator and, that week, a career counsellor), not the same event: it is the same event only if it is described alike.
        return $hits->first(fn ($row) => ! $this->otherNeed($row, $r));
    }

    /**
     * Is `$row` about a DIFFERENT need than candidate `$r`? True when both name the product part they are built on, the parts differ, and the
     * two are not described in nearly the same words (the model may name another part for the same story). A record without a part is never
     * "another need": nothing says it is.
     *
     * @param  array<string, mixed>  $r
     */
    private function otherNeed(CompanyOpportunity $row, array $r): bool
    {
        $mine = strtolower((string) ($r['assessment']['product_fit']['part'] ?? ''));
        $theirs = strtolower((string) ($row->assessment['product_fit']['part'] ?? ''));
        if ($mine === '' || $theirs === '' || $mine === $theirs) {
            return false;
        }
        $words = fn (?string $t) => array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower((string) $t)) ?: [], fn ($w) => strlen($w) > 3)));
        $a = $words($r['what_happened'] ?? $r['trigger_summary'] ?? '');
        $b = $words($row->observed_event ?: $row->trigger_summary);
        $union = count(array_unique(array_merge($a, $b)));

        return ! ($union > 0 && count(array_intersect($a, $b)) / $union >= (float) config('signals.dedupe_similarity', 0.6));
    }

    /**
     * A near-duplicate: the same buyer and trigger, seen in the last 60 days, described in nearly the same words. This is how an
     * undated mention, or the same story re-worded by a second site, joins the opportunity it is about instead of becoming a
     * second one. A genuinely different event of the same kind (different words) stays separate.
     *
     * @param  array<string, mixed>  $r
     */
    private function similarRecent($base, array $r): ?CompanyOpportunity
    {
        $words = fn (?string $t) => array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower((string) $t)) ?: [], fn ($w) => strlen($w) > 3)));
        $mine = $words($r['what_happened'] ?? $r['trigger_summary'] ?? '');
        if (count($mine) < 4) {
            return null;
        }
        // UNDATED evidence has no event identity: nothing says two pages describe different events. Same buyer, same trigger, both undated,
        // seen on the same SITE within 60 days is one standing signal, so a new page of that site corroborates it instead of repeating it.
        $host = strtolower(preg_replace('/^www\./', '', (string) parse_url((string) ($r['source_url'] ?? ''), PHP_URL_HOST)));
        if ($host !== '' && ($r['event_date'] ?? null) === null && ($r['source_published_at'] ?? null) === null) {
            foreach ((clone $base)->where('trigger_type', $r['trigger_type'])->whereNull('event_date')->whereNull('source_published_at')
                ->where('first_discovered_at', '>=', now()->subDays(60))->orderByDesc('id')->limit(20)->get() as $row) {
                $rowHost = strtolower(preg_replace('/^www\./', '', (string) parse_url((string) $row->source_url, PHP_URL_HOST)));
                if ($rowHost === $host && ! $this->otherNeed($row, $r)) {
                    return $row;
                }
            }
        }

        $threshold = (float) config('signals.dedupe_similarity', 0.6);

        foreach ((clone $base)->where('trigger_type', $r['trigger_type'])->where('first_discovered_at', '>=', now()->subDays(60))->orderByDesc('id')->limit(20)->get() as $row) {
            $theirs = $words($row->observed_event ?: $row->trigger_summary);
            $union = count(array_unique(array_merge($mine, $theirs)));
            if ($union > 0 && count(array_intersect($mine, $theirs)) / $union >= $threshold) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Add a new source to an opportunity that is already stored for the same event.
     *
     * The source is appended to `sources`, a "corroborated" event is recorded, and the evidence is graded again by
     * EvidenceGrader with the number of independent domains now reporting it (a higher level is kept; a level is never
     * lowered). review_status, pipeline_status and first_discovered_at are never touched. A source the opportunity
     * already has is a duplicate.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $base
     * @return array{outcome: string, public: array<string, mixed>, suggestion: ?array, alias_conflicts: list<string>}
     */
    private function corroborate(int $tenantId, CompanyOpportunity $existing, \App\Domain\Signals\Opportunities\Company $company, array $r, int $scanLogId, array $base): array
    {
        $sources = array_values((array) $existing->sources);
        $pathKey = fn (string $u) => preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST))) . rtrim((string) parse_url($u, PHP_URL_PATH), '/');
        $key = $pathKey($r['source_url']);   // query string ignored: utm_source and friends do not make a new source
        foreach ($sources as $s) {
            if (is_array($s) && isset($s['url']) && $pathKey((string) $s['url']) === $key) {
                return ['outcome' => 'duplicate', 'public' => ['outcome' => 'duplicate', 'id' => $existing->id, 'reason' => 'Unchanged: this source is already recorded.'], ...$base];
            }
        }

        $hostOf = fn (string $u) => strtolower(preg_replace('/^www\./', '', (string) parse_url($u, PHP_URL_HOST)));
        $domainsBefore = count(array_unique(array_filter(array_map(fn ($s) => $hostOf((string) ($s['url'] ?? '')), $sources))));
        $sources[] = [
            'url' => $r['source_url'], 'title' => $r['source_title'], 'published_at' => $r['source_published_at'] ?? $r['event_date'],
            'excerpt' => $r['source_excerpt'] ?? null, 'retrieved_at' => now()->toDateTimeString(), 'content_hash' => $r['content_hash'] ?? null,
        ];
        $domains = count(array_unique(array_filter(array_map(fn ($s) => $hostOf((string) ($s['url'] ?? '')), $sources))));

        // Grade again, with every independent domain counted. Never lower what is stored.
        $rank = fn (?string $c) => QualificationGate::CLAIM_LEVELS[$c ?? ''] ?? 0;
        $fetch = (QualificationGate::FETCH_LEVELS[$r['fetch_level']] ?? 0) > (QualificationGate::FETCH_LEVELS[$existing->fetch_level ?? ''] ?? -1) ? $r['fetch_level'] : $existing->fetch_level;
        $claim = $existing->claim_level;
        $note = $existing->evidence_note;
        if (is_array($r['evidence_input'] ?? null)) {
            $g = (new EvidenceGrader())->grade(array_merge($r['evidence_input'], ['corroborating_domains' => $domains]), $r['fetch_level'], $r['source_url'], ['name' => $r['buyer']['name'], 'website' => $company->website]);
            $candidateClaim = $g['claim_level'];
            $candidateNote = $g['note'];
        } else {
            $candidateClaim = $r['claim_level'];
            $candidateNote = $r['evidence_note'];
        }
        $upgraded = $rank($candidateClaim) > $rank($claim);
        if ($upgraded) {
            $claim = $candidateClaim;
            $note = $candidateNote;
        }

        $update = ['sources' => $sources, 'fetch_level' => $fetch, 'last_verified_at' => now()];
        $changes = ['sources' => [count($sources) - 1, count($sources)], 'corroborating_domains' => [$domainsBefore, $domains], 'source_url' => $r['source_url']];
        if ($upgraded) {
            $update += ['claim_level' => $claim, 'evidence_note' => $note, 'confidence' => $this->confidenceFor($claim), 'qualification' => $this->qualificationFor($claim, $existing->trigger_type ?? $r['trigger_type'])];
            // A context item (competitor / market) stays in its section; anything else is placed again on its stored score.
            if (! in_array($existing->feed_section, [SectionAssigner::MARKET, SectionAssigner::COMPETITOR], true)) {
                $daysLeft = $existing->expires_at === null ? null : (int) now()->startOfDay()->diffInDays($existing->expires_at->copy()->startOfDay(), false);
                $update['feed_section'] = $this->sections->assign(['claim_level' => $claim, 'fetch_level' => $fetch, 'score_total' => $existing->score_total], $daysLeft) ?? SectionAssigner::WATCHING;
            }
            $changes['claim_level'] = [$existing->claim_level, $claim];
        }

        // Same pattern as an update: first_discovered_at is assigned to itself so the column can never reset.
        $existing->forceFill($update);
        CompanyOpportunity::where('id', $existing->id)->update(
            $existing->getDirty() + ['first_discovered_at' => DB::raw(DB::getQueryGrammar()->wrap('first_discovered_at')), 'updated_at' => now()]
        );
        $this->event($tenantId, $existing->id, 'corroborated', $changes, $scanLogId);
        $matches = $this->snapshotMatches($tenantId, $existing->refresh());

        return ['outcome' => 'updated', 'public' => ['outcome' => 'updated', 'id' => $existing->id, 'reason' => 'corroborated', 'changes' => array_keys($changes), 'matches' => $matches], ...$base];
    }

    /** Does the opportunity already carry this source? Compared on host and path: tracking parameters do not make a new source. */
    private function hasSource(CompanyOpportunity $opp, string $url): bool
    {
        $key = fn (string $u) => preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST))) . rtrim((string) parse_url($u, PHP_URL_PATH), '/');
        foreach ((array) $opp->sources as $s) {
            if (is_array($s) && isset($s['url']) && $key((string) $s['url']) === $key($url)) {
                return true;
            }
        }

        return false;
    }

    private function legacyMatch(int $tenantId, int $companyId, string $sourceUrl): ?CompanyOpportunity
    {
        $key = $this->urlKey($sourceUrl);

        foreach (CompanyOpportunity::where('sub_institute_id', $tenantId)->where('company_id', $companyId)->whereNull('trigger_type')->get() as $row) {
            foreach ((array) $row->sources as $s) {
                if (is_array($s) && isset($s['url']) && $this->urlKey((string) $s['url']) === $key) {
                    return $row;
                }
            }
        }

        return null;
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
