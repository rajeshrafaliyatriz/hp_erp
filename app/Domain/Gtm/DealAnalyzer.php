<?php

namespace App\Domain\Gtm;

use Illuminate\Support\Facades\DB;

/**
 * AI analysis of one deal: methodology scoring and discovery-call analysis.
 *
 * Scoring: the model scores each dimension of the chosen methodology playbook from the deal's
 * recorded evidence. The TOTAL is computed here from the playbook's own weights, dimensions the
 * model returned that the playbook does not define are dropped, and a dimension it skipped is
 * recorded as not assessed rather than as zero. Results are stored as ESTIMATES in gtm_analyses.
 */
final class DealAnalyzer
{
    public function __construct(private readonly GtmAiRunner $ai, private readonly PlaybookResolver $playbooks)
    {
    }

    /** The recorded facts a model may use for one deal. @return array<string, mixed> */
    public function context(GtmDeal $deal, int $activityLimit = 15): array
    {
        $t = (int) $deal->sub_institute_id;
        $account = GtmAccount::where('sub_institute_id', $t)->find($deal->account_id);

        return [
            'deal' => array_filter([
                'name' => $deal->name, 'stage' => $deal->stage, 'amount' => $deal->amount, 'currency' => $deal->currency,
                'expected_close_date' => $deal->expected_close_date?->toDateString(), 'next_step' => $deal->next_step,
                'next_step_due' => $deal->next_step_due?->toDateString(), 'days_in_stage' => $deal->stage_changed_at ? (int) $deal->stage_changed_at->diffInDays(now()) : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'account' => $account ? array_filter(['name' => $account->name, 'industry' => $account->industry, 'location' => $account->location], fn ($v) => $v) : null,
            'contacts' => DB::table('gtm_contacts')->where('sub_institute_id', $t)->where('account_id', $deal->account_id)->whereNull('deleted_at')
                ->get(['id', 'full_name', 'title', 'role_in_deal', 'status'])->map(fn ($c) => ['contact_id' => $c->id, 'name' => $c->full_name, 'title' => $c->title, 'role_in_deal' => $c->role_in_deal, 'status' => $c->status])->all(),
            'activities' => DB::table('gtm_activities')->where('sub_institute_id', $t)->where('type', '!=', 'system')
                ->where(fn ($w) => $w->where('deal_id', $deal->id)->orWhere('account_id', $deal->account_id))
                ->orderByDesc('occurred_at')->limit($activityLimit)->get(['type', 'direction', 'subject', 'body', 'occurred_at'])
                ->map(fn ($a) => ['type' => $a->type, 'direction' => $a->direction, 'subject' => $a->subject, 'detail' => $a->body ? mb_substr($a->body, 0, 600) : null, 'at' => $a->occurred_at])->all(),
        ];
    }

    /**
     * @return array{total: ?int, coverage: array{scored: int, of: int}, dimensions: list<array<string, mixed>>, analysis_id: int, methodology: array<string, mixed>, provider: string, model: string}
     *
     * @throws \DomainException when the playbook is not a usable methodology
     */
    public function score(GtmDeal $deal, string $methodologySlug, ?int $userId): array
    {
        $t = (int) $deal->sub_institute_id;
        $playbook = $this->playbooks->resolve($t, $methodologySlug);
        $defs = $playbook->definition['dimensions'] ?? null;
        if ($playbook->kind !== 'methodology' || ! is_array($defs) || count($defs) < 2) {
            throw new \DomainException("'{$methodologySlug}' is not a scoring methodology.");
        }
        $keys = array_column($defs, 'key');

        $context = $this->context($deal) + ['methodology' => ['name' => $playbook->title, 'dimensions' => array_map(fn ($d) => ['key' => $d['key'], 'label' => $d['label'], 'looks_for' => $d['looks_for'] ?? ''], $defs)]];
        $run = $this->ai->json($t, $userId, 'methodology_score', 'deal', (int) $deal->id, [
            ['role' => 'system', 'content' => $this->playbooks->systemPrompt($playbook).' Return exactly one entry per methodology dimension key: '.implode(', ', $keys).'.'],
            ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE)],
        ], [], "{$playbook->title} score for {$deal->name}", $playbook->id, 2600);

        $returned = [];
        foreach ((array) ($run['data']['dimensions'] ?? []) as $row) {
            if (is_array($row) && in_array($row['key'] ?? null, $keys, true)) {
                $returned[$row['key']] = $row;
            }
        }
        $dimensions = [];
        $num = 0.0;
        $den = 0.0;
        $scored = 0;
        foreach ($defs as $def) {
            $row = $returned[$def['key']] ?? null;
            $score = $row && isset($row['score']) && is_numeric($row['score']) ? (int) round(max(0, min(100, (float) $row['score']))) : null;
            if ($score !== null) {
                $num += $score * (float) $def['weight'];
                $den += (float) $def['weight'];
                $scored++;
            }
            $dimensions[] = [
                'key' => $def['key'], 'label' => $def['label'], 'weight' => (float) $def['weight'], 'score' => $score,
                'evidence' => $score === null ? 'Not assessed.' : (string) ($row['evidence'] ?? ''),
                'next_action' => (string) ($row['next_action'] ?? $row['next_question'] ?? ''),
            ];
        }
        // Honest about coverage: a total over fewer than half the dimensions is not a total.
        $total = $scored * 2 >= count($defs) && $den > 0 ? (int) round($num / $den) : null;
        $methodology = ['slug' => $playbook->slug, 'title' => $playbook->title, 'version' => $playbook->version, 'source' => $playbook->sub_institute_id === null ? 'platform' : 'organisation'];

        $final = ['total' => $total, 'coverage' => ['scored' => $scored, 'of' => count($defs)], 'dimensions' => $dimensions, 'methodology' => $methodology, 'missing' => (array) ($run['data']['missing'] ?? [])];
        $this->ai->amend($t, $run['analysis_id'], $final);

        return $final + ['analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model']];
    }

    /**
     * Analyse discovery notes the rep supplied. The notes are the evidence: quotes the model returns
     * are checked to appear in them, and the ones that do not are dropped.
     *
     * @return array<string, mixed>
     */
    public function discovery(GtmDeal $deal, string $notes, ?int $userId): array
    {
        $t = (int) $deal->sub_institute_id;
        $playbook = $this->playbooks->resolve($t, 'deal-discovery-call-analysis');
        $context = $this->context($deal, 8) + ['notes' => $notes];
        $run = $this->ai->json($t, $userId, 'discovery_analysis', 'deal', (int) $deal->id, [
            ['role' => 'system', 'content' => $this->playbooks->systemPrompt($playbook)],
            ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE)],
        ], [], "Discovery analysis for {$deal->name}", $playbook->id, 2600);

        $norm = fn (string $s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($s))) ?? '';
        $haystack = $norm($notes);
        $dropped = 0;
        $problems = [];
        foreach ((array) ($run['data']['problems'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $quote = trim((string) ($p['quote'] ?? ''));
            if ($quote !== '' && str_contains($haystack, $norm($quote))) {
                $problems[] = $p;
            } else {
                $dropped++;
            }
        }
        $final = $run['data'];
        $final['problems'] = $problems;
        $this->ai->amend($t, $run['analysis_id'], $final + ['integrity' => ['quotes_dropped' => $dropped]]);

        return ['result' => $final, 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'],
            'playbook' => ['slug' => $playbook->slug, 'version' => $playbook->version],
            'integrity' => ['quotes_dropped' => $dropped, 'note' => $dropped > 0 ? 'Problems whose quote did not appear in your notes were removed.' : null]];
    }
}
