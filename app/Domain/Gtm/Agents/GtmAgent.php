<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\GtmAiRunner;
use App\Domain\Gtm\PlaybookResolver;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use Illuminate\Support\Facades\DB;

/**
 * A GTM agent: gather real records for ONE organisation, ask the model to apply a playbook
 * to them, then check the answer against what was gathered.
 *
 * Contract every agent keeps:
 *  - It reads; it never writes business records. Anything consequential is returned as a
 *    draft for a person to approve.
 *  - Nothing is sent to the model that was not read from the database for this tenant.
 *  - With nothing to analyse it raises NoDataException instead of asking a model to fill the gap.
 *  - Identifiers and URLs in the answer are checked against the input; the ones the model
 *    invented are dropped and counted in `integrity`.
 */
abstract class GtmAgent
{
    public function __construct(protected readonly GtmAiRunner $ai, protected readonly PlaybookResolver $playbooks)
    {
    }

    abstract public static function slug(): string;

    abstract public static function name(): string;

    abstract public static function description(): string;

    /** @return array<string, array{type: string, required: bool, label: string, options?: array<int,string>}> */
    abstract public static function inputs(): array;

    /** @return list<string> the tables it reads, shown on the agents screen */
    abstract public static function reads(): array;

    /** Null when it can run; otherwise the plain-language reason it cannot yet. */
    public static function unavailable(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>, analysis_id: int, provider: string, model: string, playbook: array<string, mixed>, integrity: array<string, mixed>}
     *
     * @throws NoDataException|\DomainException
     */
    abstract public function run(int $tenant, ?int $userId, array $input): array;

    /** @return array<string, mixed> */
    protected function productProfile(int $tenant): array
    {
        $profile = ProductProfile::where('sub_institute_id', $tenant)->first();
        $check = app(ProductProfileService::class)->completeness($profile);
        if (! $check['complete']) {
            throw new \DomainException('Define your product and ideal customer under Signals → Company research first ('.implode(', ', $check['missing']).' missing).');
        }

        return [
            'product' => $profile->product_name, 'what_it_does' => $profile->description, 'problems_solved' => $profile->problems_solved,
            'ideal_customer' => $profile->ideal_customer_profile, 'target_industries' => (array) $profile->target_industries,
            'target_markets' => (array) $profile->target_markets,
        ];
    }

    /** Live, non-dismissed research signals for a company, newest first. Each carries its source URLs. */
    protected function signalsFor(int $tenant, ?int $companyId, int $limit = 10): array
    {
        if (! $companyId) {
            return [];
        }

        return DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('company_id', $companyId)
            ->where('review_status', '!=', 'Dismissed')->orderByDesc('first_discovered_at')->limit($limit)
            ->get(['id', 'title', 'signal_kind', 'observed_event', 'why_indicates_need', 'priority', 'event_date', 'sources'])
            ->map(fn ($s) => [
                'signal_id' => (int) $s->id, 'title' => $s->title, 'kind' => $s->signal_kind, 'event' => $s->observed_event,
                'why_it_matters' => $s->why_indicates_need, 'priority' => $s->priority, 'event_date' => $s->event_date,
                'source_urls' => array_values(array_filter(array_column(json_decode($s->sources ?? '[]', true) ?: [], 'url'))),
            ])->all();
    }

    /** Ask the model to apply the playbook; returns the AiRunner payload plus the playbook reference. */
    protected function ask(int $tenant, ?int $userId, string $playbookSlug, string $kind, string $subjectType, ?int $subjectId, array $records, array $sources, string $summary, int $maxTokens = 1800): array
    {
        $playbook = $this->playbooks->resolve($tenant, $playbookSlug);
        $run = $this->ai->json($tenant, $userId, $kind, $subjectType, $subjectId, [
            ['role' => 'system', 'content' => $this->playbooks->systemPrompt($playbook)],
            ['role' => 'user', 'content' => json_encode($records, JSON_UNESCAPED_UNICODE)],
        ], $sources, $summary, $playbook->id, $maxTokens);

        $run['playbook'] = ['id' => $playbook->id, 'slug' => $playbook->slug, 'version' => $playbook->version, 'source' => $playbook->sub_institute_id === null ? 'platform' : 'organisation'];

        return $run;
    }
}
