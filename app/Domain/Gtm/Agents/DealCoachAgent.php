<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\DealAnalyzer;
use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\GtmDeal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coaches one open deal. The risk FLAGS come from DealHealth (rules over recorded data); the model
 * is asked to explain and prioritise them, with the latest methodology score as extra evidence.
 * Recommendations are advice: nothing here edits the deal.
 */
final class DealCoachAgent extends GtmAgent
{
    public static function slug(): string { return 'gtm-deal-coach'; }
    public static function name(): string { return 'Deal Coach'; }
    public static function description(): string { return 'Reviews one open deal against its recorded activity, contacts, health flags and latest methodology score, and recommends the next moves. Advice only - it never edits the deal.'; }
    public static function reads(): array { return ['gtm_deals', 'gtm_contacts', 'gtm_activities', 'gtm_analyses']; }
    public static function inputs(): array { return ['deal_id' => ['type' => 'deal', 'required' => true, 'label' => 'Open deal']]; }

    public static function unavailable(): ?string
    {
        return Schema::hasTable('gtm_deals') ? null : 'Needs the deals tables. Run the GTM deals migration.';
    }

    public function run(int $tenant, ?int $userId, array $input): array
    {
        $deal = GtmDeal::where('sub_institute_id', $tenant)->find((int) ($input['deal_id'] ?? 0));
        if (! $deal) {
            throw new \DomainException('Choose one of your deals.');
        }
        if (! $deal->isOpen()) {
            throw new \DomainException("This deal is {$deal->stage}. The coach reviews open deals only.");
        }

        $health = app(DealHealth::class)->assess($tenant, [$deal])[$deal->id];
        $records = app(DealAnalyzer::class)->context($deal) + [
            'measured_health' => [
                'flags' => array_map(fn ($f) => ['flag' => $f, 'meaning' => DealHealth::FLAGS[$f]], $health['flags']),
                'days_since_activity' => $health['days_since_activity'], 'days_in_stage' => $health['days_in_stage'],
            ],
        ];
        $score = DB::table('gtm_analyses')->where('sub_institute_id', $tenant)->where('subject_type', 'deal')->where('subject_id', $deal->id)
            ->where('kind', 'methodology_score')->where('status', 'success')->orderByDesc('id')->value('result');
        $score = $score ? json_decode($score, true) : null;
        if ($score) {
            $records['methodology_scores'] = ['methodology' => $score['methodology']['title'] ?? null, 'total' => $score['total'] ?? null,
                'dimensions' => array_map(fn ($d) => ['label' => $d['label'], 'score' => $d['score'], 'evidence' => $d['evidence']], $score['dimensions'] ?? [])];
        }

        $run = $this->ask($tenant, $userId, 'deal-coach', 'deal_coach', 'deal', (int) $deal->id, $records, [], "Coaching for deal {$deal->name}", 2600);
        $data = $run['data'];
        // A risk with no evidence is an assertion; keep only the ones that cite something recorded.
        $risks = array_values(array_filter((array) ($data['risks'] ?? []), fn ($r) => is_array($r) && trim((string) ($r['evidence'] ?? '')) !== ''));
        $dropped = count((array) ($data['risks'] ?? [])) - count($risks);
        $data['risks'] = $risks;
        $data['measured_flags'] = $health['flags'];
        $data['measured_labels'] = array_intersect_key(DealHealth::FLAGS, array_flip($health['flags']));
        $data['deal'] = ['id' => (int) $deal->id, 'name' => $deal->name, 'stage' => $deal->stage];
        $this->ai->amend($tenant, $run['analysis_id'], $data);

        return ['result' => $data, 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'], 'playbook' => $run['playbook'],
            'integrity' => ['references_dropped' => $dropped, 'note' => $dropped > 0 ? 'Risks that cited no recorded evidence were removed.' : null]];
    }
}
