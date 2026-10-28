<?php

namespace App\Domain\Gtm;

use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use Illuminate\Support\Facades\DB;

/**
 * Scores how well one account matches the organisation's own Ideal Customer Profile.
 *
 * Inputs are only rows that exist: the tenant's product profile (g2g_product_profiles),
 * the account record, and the account's live research signals. The model is told to use
 * nothing else and to say which facts are missing instead of guessing. The score is an
 * ESTIMATE and is stored as one (gtm_analyses.is_estimate, and the account's icp_fit_basis
 * lists what drove it).
 */
final class IcpScorer
{
    public function __construct(private readonly GtmAiRunner $ai, private readonly ProductProfileService $profiles)
    {
    }

    /**
     * @return array{score: int, basis: array<string, mixed>, analysis_id: int}
     *
     * @throws \DomainException when the ICP is not defined yet (nothing to score against)
     */
    public function score(GtmAccount $account, ?int $userId): array
    {
        $tenant = (int) $account->sub_institute_id;
        $profile = ProductProfile::where('sub_institute_id', $tenant)->first();
        $check = $this->profiles->completeness($profile);
        if (! $check['complete']) {
            throw new \DomainException('Define your product and ideal customer under Signals → Company research first ('.implode(', ', $check['missing']).' missing). Fit cannot be scored without it.');
        }

        $signals = $account->company_id
            ? DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('company_id', $account->company_id)
                ->where('review_status', '!=', 'Dismissed')->orderByDesc('first_discovered_at')->limit(8)
                ->get(['title', 'signal_kind', 'observed_event', 'priority', 'sources'])
                ->map(fn ($s) => ['title' => $s->title, 'kind' => $s->signal_kind, 'event' => $s->observed_event, 'priority' => $s->priority,
                    'source_url' => (json_decode($s->sources ?? '[]', true)[0]['url'] ?? null)])->all()
            : [];

        $icp = [
            'product' => $profile->product_name, 'what_it_does' => $profile->description,
            'problems_solved' => $profile->problems_solved, 'ideal_customer' => $profile->ideal_customer_profile,
            'target_industries' => (array) $profile->target_industries, 'target_company_types' => (array) $profile->target_company_types,
            'target_company_size' => $profile->target_company_size, 'target_markets' => (array) $profile->target_markets,
            'excluded' => (array) $profile->excluded,
        ];
        $facts = array_filter([
            'name' => $account->name, 'website' => $account->website ?: $account->domain, 'industry' => $account->industry,
            'location' => $account->location, 'employee_range' => $account->employee_range, 'notes' => $account->notes,
        ], fn ($v) => $v !== null && $v !== '');

        $system = 'You score how well ONE company fits a seller\'s Ideal Customer Profile. '
            .'Use ONLY the facts in the user message. Never invent facts about the company. '
            .'If a fact needed for a dimension is absent, score that dimension null and list it in "missing". '
            .'Return a JSON object: {"score": integer 0-100 or null, "dimensions": [{"name": "industry"|"market"|"size"|"need"|"exclusions", '
            .'"score": integer 0-100 or null, "reason": string citing the provided fact}], "summary": string (max 2 sentences), '
            .'"missing": [string], "confidence": "low"|"medium"|"high"}. '
            .'"need" may only use the listed research signals as evidence. Set "score" null when fewer than two dimensions can be scored.';
        $user = json_encode(['ideal_customer_profile' => $icp, 'company_facts' => $facts, 'research_signals' => $signals], JSON_UNESCAPED_UNICODE);

        $run = $this->ai->json($tenant, $userId, 'icp_fit', 'account', (int) $account->id, [
            ['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user],
        ], array_values(array_filter(array_column($signals, 'source_url'))), "ICP fit for {$account->name}");

        $d = $run['data'];
        $dimensions = array_values(array_filter((array) ($d['dimensions'] ?? []), 'is_array'));
        [$score, $adjustments] = $this->overall($dimensions, (string) ($d['confidence'] ?? 'low'));
        $basis = [
            'dimensions' => $dimensions,
            'summary' => (string) ($d['summary'] ?? ''), 'missing' => (array) ($d['missing'] ?? []),
            'confidence' => (string) ($d['confidence'] ?? 'low'), 'adjustments' => $adjustments, 'is_estimate' => true,
            'provider' => $run['provider'], 'model' => $run['model'], 'analysis_id' => $run['analysis_id'],
        ];

        $account->forceFill(['icp_fit_score' => $score, 'icp_fit_basis' => $basis, 'icp_scored_at' => now()])->save();

        return ['score' => $score, 'basis' => $basis, 'analysis_id' => $run['analysis_id']];
    }

    /**
     * Computed here, not taken from the model (its own total proved inconsistent with its
     * parts, and generous). Mean of the dimensions that could be scored (at least two),
     * minus 10 for each dimension with no data, capped at 70 when the model reports low
     * confidence, and at 20 when the company hits an exclusion. Every adjustment is
     * returned so the screen can show why the number is what it is.
     *
     * @param  array<int, array<string, mixed>>  $dimensions
     * @return array{0: ?int, 1: array<int, string>}
     */
    private function overall(array $dimensions, string $confidence): array
    {
        $scores = [];
        $unscored = 0;
        $exclusion = null;
        foreach ($dimensions as $dim) {
            if (! isset($dim['score']) || ! is_numeric($dim['score'])) {
                $unscored++;
                continue;
            }
            $v = max(0, min(100, (float) $dim['score']));
            $scores[] = $v;
            if (($dim['name'] ?? '') === 'exclusions') {
                $exclusion = $v;
            }
        }
        if (count($scores) < 2) {
            return [null, ['Fewer than two dimensions could be scored from the data available.']];
        }
        $adjustments = [];
        $score = array_sum($scores) / count($scores);
        if ($unscored > 0) {
            $score -= 10 * $unscored;
            $adjustments[] = "−".(10 * $unscored)." for {$unscored} dimension(s) with no data";
        }
        if ($confidence === 'low' && $score > 70) {
            $score = 70;
            $adjustments[] = 'Capped at 70: low confidence in the available facts';
        }
        if ($exclusion !== null && $exclusion <= 20 && $score > 20) {
            $score = 20;
            $adjustments[] = 'Capped at 20: matches an exclusion';
        }

        return [(int) round(max(0, $score)), $adjustments];
    }
}
