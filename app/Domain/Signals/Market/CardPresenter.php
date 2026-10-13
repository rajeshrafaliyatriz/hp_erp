<?php

namespace App\Domain\Signals\Market;

use Illuminate\Support\Carbon;

/**
 * Turns a stored lead into the plain-English face of the card. Pure.
 *
 * "Store the enum, display the plain words": every internal value (urgency band, entry point,
 * evidence level, organisation type) is translated here and nowhere else. The face never carries an
 * internal word; the stored values travel separately in the collapsed detail.
 */
final class CardPresenter
{
    public const URGENCY_LINES = [
        'act_this_week' => 'Needs attention this week',
        'act_this_month' => 'Worth acting on this month',
        'nurture' => 'Keep an eye on this',
    ];

    public const ENTRY_PHRASES = [
        'discovery_meeting' => 'Ask for an introductory meeting',
        'pilot' => 'Propose a small paid pilot',
        'poc' => 'Offer to build a proof of concept',
        'paid_assessment' => 'Offer a paid assessment first',
        'workshop' => 'Run a workshop for their team',
        'si_partnership' => 'Go in through a partner already doing the work',
        'rfp_response' => 'Respond to their tender',
    ];

    public const SURENESS = [
        'confirmed' => 'Their own announcement says this.',
        'inference' => 'The facts are solid. That they need us is our read, not their statement.',
        'hypothesis' => 'One source only, unverified. Treat as a lead to check, not a fact.',
    ];

    /** Stored organisation type -> plain words. Anything unknown reads as "company". */
    public const ORG_KINDS = [
        'school' => 'school', 'school_group' => 'school group', 'college' => 'college', 'university' => 'university',
        'government' => 'government department', 'public_sector' => 'public sector company', 'hospital' => 'hospital',
        'training_institute' => 'training institute', 'institutional' => 'institution',
        'enterprise' => 'company', 'sme' => 'company', 'other' => 'organisation',
    ];

    /** Urgency band stored in `urgency`, derived from the deadline, the urgency score and the section. */
    public function band(?int $daysLeft, ?int $scoreUrgency, ?string $section): string
    {
        $cfg = (array) config('signals.card.urgency');

        if ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= (int) $cfg['this_week_days']) {
            return 'act_this_week';
        }
        if ($scoreUrgency !== null && $scoreUrgency >= (int) $cfg['this_week_min_score']) {
            return 'act_this_week';
        }
        if (($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= (int) $cfg['this_month_days'])
            || ($scoreUrgency !== null && $scoreUrgency >= (int) $cfg['this_month_min_score'])
            || $section === SectionAssigner::ACT_NOW) {
            return 'act_this_month';
        }

        return 'nurture';
    }

    /** Map whatever is stored in `urgency` (the band, or an older free-text value) onto a band. */
    public function normaliseBand(?string $stored, ?int $daysLeft, ?int $scoreUrgency, ?string $section): string
    {
        if ($stored !== null && isset(self::URGENCY_LINES[$stored])) {
            return $stored;
        }

        return match (true) {
            $stored === 'Immediate' => 'act_this_week',
            $stored === 'Within 30 days' => 'act_this_month',
            default => $this->band($daysLeft, $scoreUrgency, $section),
        };
    }

    /**
     * @param  array<string, mixed>  $r  a lead as the API flattens it (opportunity columns + company columns)
     * @return array<string, mixed>
     */
    public function face(array $r): array
    {
        $daysLeft = $r['days_left'] ?? null;
        $band = $this->normaliseBand($r['urgency'] ?? null, $daysLeft, $r['score_urgency'] ?? null, $r['feed_section'] ?? null);

        $urgency = self::URGENCY_LINES[$band];
        if ($band === 'act_this_week' && $daysLeft !== null && $daysLeft >= 0) {
            $urgency .= $daysLeft === 0 ? ' — closes today' : ' — closes in ' . $daysLeft . ' ' . ($daysLeft === 1 ? 'day' : 'days');
        }

        [$headline, $context] = $this->headlineAndContext((string) ($r['observed_event'] ?? ''));
        $plain = ($r['import_source'] ?? null) === 'web-research';

        $soWhat = $plain
            ? ($r['likely_problem'] ?? $r['why_indicates_need'] ?? '')
            : ($r['why_indicates_need'] ?? $r['likely_problem'] ?? '');

        $entry = $r['entry_point'] ?? null;
        $todo = trim((string) ($r['soft_marketing_angle'] ?? ''));
        if ($todo === '') {
            $todo = $entry && isset(self::ENTRY_PHRASES[$entry]) ? self::ENTRY_PHRASES[$entry] . '.' : trim((string) ($r['recommended_action'] ?? ''));
        }

        return [
            'urgency_band' => $band,
            'urgency_line' => $urgency,
            'organisation_line' => $this->organisationLine($r),
            'headline' => $headline,
            'context' => $context,
            'so_what' => trim((string) $soWhat),
            'what_to_do' => $todo,
            'how_sure' => self::SURENESS[$r['claim_level'] ?? 'hypothesis'] ?? self::SURENESS['hypothesis'],
            'source' => $this->source($r),
            'written_plainly' => $plain,
        ];
    }

    /** "ShikshaNext — education funding initiative, India": name, plain kind, place. */
    private function organisationLine(array $r): string
    {
        $name = trim((string) ($r['company_name'] ?? ''));
        $kind = self::ORG_KINDS[$r['company_type'] ?? ''] ?? null;
        $place = trim((string) ($r['company_state'] ?? '')) ?: trim((string) ($r['location'] ?? ''));

        $tail = trim(implode(', ', array_filter([$kind, $place])));

        return $tail === '' ? $name : "{$name} — {$tail}";
    }

    /** @return array{0: string, 1: string} first sentence (the bold line) and the rest (context) */
    private function headlineAndContext(string $text): array
    {
        $sentences = (new PlainLanguageRules())->sentences($text);
        if ($sentences === []) {
            return ['', ''];
        }

        return [$sentences[0], implode(' ', array_slice($sentences, 1))];
    }

    /** @return array{text: string, url: ?string} "From their announcement, 2 September 2026" */
    private function source(array $r): array
    {
        $url = $r['source_url'] ?? ($r['primary_source']['url'] ?? null);
        $host = $url ? strtolower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST))) : '';
        $own = (new EvidenceGrader())->isPrimary((string) $url, ['name' => $r['company_name'] ?? '', 'website' => $r['website'] ?? null]);
        $who = $own ? 'their announcement' : ($host !== '' ? $host : 'the source');

        $date = $r['source_published_at'] ?? $r['event_date'] ?? null;
        try {
            $when = $date ? Carbon::parse($date)->format('j F Y') : null;
        } catch (\Throwable) {
            $when = null;
        }

        return ['text' => 'From ' . $who . ($when ? ", {$when}" : ''), 'url' => $url];
    }
}
