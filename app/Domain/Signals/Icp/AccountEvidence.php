<?php

namespace App\Domain\Signals\Icp;

use App\Domain\Signals\Market\SectionAssigner;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use Illuminate\Support\Carbon;

/**
 * Turns an account's own recent signals into the COUNTED evidence points and the full score, so nothing is typed by hand:
 *   active_triggers    how many different triggers fired in the last 90 days
 *   need_across_parts  how many different parts of the bundle those triggers point at
 * The JUDGED ones (leadership_willingness, financial_capacity) are set by a person on the account
 * (attributes.evidence.<key> = points) and are capped at their maximum by the scorer. Nothing here is written by the model.
 *
 * Only real signals count: dismissed ones, and market/competitor context, are ignored.
 */
final class AccountEvidence
{
    public const WINDOW_DAYS = 90;

    public function __construct(private readonly IcpScorer $scorer = new IcpScorer())
    {
    }

    /**
     * @param  array<string, mixed>  $pack  the ICP configuration
     * @return array{evidence: array<string, int>, triggers: list<string>, parts: list<string>, score: array<string, mixed>}
     */
    public function compute(TargetAccount $account, array $pack, ?Carbon $now = null): array
    {
        $now ??= now();
        $triggers = $this->triggers($account, $now);
        $parts = [];
        foreach ($triggers as $t) {
            $part = $pack['triggers'][$t]['part'] ?? null;
            if (is_string($part) && $part !== '') {
                $parts[$part] = true;
            }
        }
        $parts = array_keys($parts);

        $evidence = [
            'active_triggers' => $this->scorer->evidencePoints('active_triggers', count($triggers), $pack),
            'need_across_parts' => $this->scorer->evidencePoints('need_across_parts', count($parts), $pack),
        ];
        foreach ((array) ($account->attributes['evidence'] ?? []) as $key => $points) {
            if (! isset($evidence[$key]) && is_numeric($points) && isset($pack['evidence_points'][$key])) {
                $evidence[$key] = (int) $points;
            }
        }

        $score = $this->scorer->total([
            'name' => $account->name, 'city' => $account->city, 'state' => $account->state, 'board' => $account->board,
            'level' => $account->level, 'website' => $account->website, 'attributes' => (array) $account->attributes,
        ], $evidence, $pack, $now);

        return ['evidence' => $evidence, 'triggers' => $triggers, 'parts' => $parts, 'score' => $score];
    }

    /** Compute and store on the account (inside icp_notes; no new column). Returns the result. @return array<string, mixed> */
    public function refresh(TargetAccount $account, array $pack, ?Carbon $now = null): array
    {
        $r = $this->compute($account, $pack, $now);
        $notes = (array) $account->icp_notes;
        $notes['evidence'] = [
            'points' => $r['evidence'], 'triggers' => $r['triggers'], 'parts' => $r['parts'],
            'total' => $r['score']['total'], 'priority' => $r['score']['priority'], 'at' => ($now ?? now())->toIso8601String(),
        ];
        $account->forceFill(['icp_notes' => $notes])->save();

        return $r;
    }

    /** @return list<string> the distinct trigger types that fired for this account in the window */
    private function triggers(TargetAccount $account, Carbon $now): array
    {
        if (! $account->company_id) {
            return [];
        }
        $since = $now->copy()->subDays(self::WINDOW_DAYS)->toDateString();
        $types = [];
        foreach (CompanyOpportunity::where('company_id', $account->company_id)
            ->where('sub_institute_id', $account->sub_institute_id)
            ->where('review_status', '!=', 'Dismissed')
            ->whereNotNull('trigger_type')->get() as $o) {
            if (in_array($o->feed_section, [SectionAssigner::MARKET, SectionAssigner::COMPETITOR], true)) {
                continue;
            }
            $when = $o->event_date ?? $o->source_published_at ?? $o->first_discovered_at;
            if ($when !== null && Carbon::parse($when)->toDateString() >= $since) {
                $types[(string) $o->trigger_type] = true;
            }
        }

        return array_keys($types);
    }
}
