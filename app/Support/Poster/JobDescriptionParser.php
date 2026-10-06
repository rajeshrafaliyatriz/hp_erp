<?php

namespace App\Support\Poster;

/**
 * Pulls poster panels out of a job description.
 *
 * ── WHY PARSING AT ALL ──────────────────────────────────────────────────────
 *
 * `description` is the only place this content exists. talent_job_postings has
 * no responsibilities or requirements column; s_user_jobrole.responsibilities
 * is empty on all 271 roles on tenant 6; benefits and certifications are empty
 * on all six live postings.
 *
 * Measured against every live posting, these rules produced 8-12 items for the
 * first panel and 5-6 for the second, and none of the six lost a panel.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────────
 *
 * It never guesses which half of a list is "responsibilities" and which is
 * "requirements". If the headings are not there, it fills one honest panel
 * rather than two whose titles lie.
 */
class JobDescriptionParser
{
    /** Bullet glyphs and numbering seen in real descriptions. */
    private const ITEM = '/^\s*(?:[-*\x{2022}\x{00B7}\x{25CF}\x{25AA}\x{2023}\x{2043}\x{25E6}\x{2013}\x{2014}>]|\(?\d{1,2}[.)]|\(?[a-z][.)])\s+/u';

    /** A heading is short, unbulleted, and says what follows. */
    private const MAX_HEADING = 80;

    /** Below this an item is a fragment ("Excel", "Good") rather than a point. */
    private const MIN_ITEM = 12;

    private const SECTIONS = [
        'responsibilities' => '/^(key\s+)?responsibilit|^what\s+you.{0,3}(ll|\s+will)\s+do|^role\s+(overview|summary)|^duties|^the\s+role\b/i',
        'requirements'     => '/^what\s+we.{0,3}re\s+looking\s+for|^requirements?\b|^must[\s-]?have|^qualifications?\b|^who\s+you\s+are|^eligibilit|^skills\s+(and|&)\s+experience/i',
        /*
         * Recognised ONLY so that it closes the section above it. Its content
         * is discarded: "Why join" is company marketing that no reviewer sees
         * before the poster is published, and "Benefits"/"Compensation" have
         * their own columns which are authoritative when set.
         */
        'ignore'           => '/^why\s+join|^about\s+(us|the\s+company|scholar)|^benefits?\b|^perks|^what\s+we\s+offer|^how\s+to\s+apply|^compensation|^salary|^success\s+metrics|^the\s+journey/i',
    ];

    /**
     * @return array{responsibilities: string[], requirements: string[]}
     */
    public function parse(?string $description, int $limit, int $width): array
    {
        $text = PosterText::normalise($description);
        $empty = ['responsibilities' => [], 'requirements' => []];

        if ($text === '') {
            return $empty;
        }

        $items = ['responsibilities' => [], 'requirements' => []];
        $prose = ['responsibilities' => [], 'requirements' => []];
        $loose = [];                 // bullets found before any heading
        $section = null;

        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }

            if ($heading = $this->headingFor($line)) {
                $section = $heading === 'ignore' ? null : $heading;
                continue;
            }

            $isItem = (bool) preg_match(self::ITEM, $line);

            if ($section === null) {
                if ($isItem) {
                    $loose[] = $this->stripMarker($line);
                }
                continue;
            }

            if ($isItem) {
                $items[$section][] = $this->stripMarker($line);
            } else {
                /*
                 * Prose under a numbered heading is the detail beneath a
                 * headline - "1. Implementation ownership" then a paragraph
                 * explaining it. The headline is the poster bullet; the
                 * paragraph belongs on the careers page. Kept only to rescue a
                 * section that produced no bullets at all.
                 */
                $prose[$section][] = $line;
            }
        }

        $panels = [];
        foreach (['responsibilities', 'requirements'] as $key) {
            $source = $items[$key] !== []
                ? $items[$key]
                : $this->fromProse($prose[$key]);

            $panels[$key] = $this->finish($source, $limit, $width);
        }

        /*
         * No headings anywhere, but the description is a list. Rather than
         * split it down the middle and invent two headings, put everything in
         * the first panel and leave the second genuinely empty - the layout
         * has a designed single-panel variant for exactly this.
         */
        if ($panels['responsibilities'] === [] && $panels['requirements'] === [] && $loose !== []) {
            $panels['responsibilities'] = $this->finish($loose, $limit, $width);
        }

        // Still nothing, and the description is one long paragraph.
        if ($panels['responsibilities'] === [] && $panels['requirements'] === []) {
            $panels['responsibilities'] = $this->finish(PosterText::sentences($text), $limit, $width);
        }

        return $panels;
    }

    private function headingFor(string $line): ?string
    {
        if (mb_strlen($line) > self::MAX_HEADING || preg_match(self::ITEM, $line)) {
            return null;
        }

        // Markdown emphasis is common in pasted descriptions.
        $clean = trim(preg_replace('/^[#*_\s]+|[#*_:\s]+$/u', '', $line) ?? $line);

        foreach (self::SECTIONS as $name => $pattern) {
            if (preg_match($pattern, $clean)) {
                return $name;
            }
        }

        return null;
    }

    private function stripMarker(string $line): string
    {
        return trim(preg_replace(self::ITEM, '', $line) ?? $line);
    }

    /** @param string[] $lines */
    private function fromProse(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            foreach (PosterText::sentences($line) as $sentence) {
                $out[] = $sentence;
            }
        }

        return $out;
    }

    /**
     * Trim, shorten and de-duplicate, KEEPING THE AUTHOR'S ORDER.
     *
     * An earlier version sorted shortest-first, to maximise how many bullets
     * printed without truncation. Measured against posting #358 that turned a
     * numbered process - "1. Understand existing school administration,
     * 2. Process discovery, 3. Process design, 4. Configuration" - into items
     * 4, 6, 3, 2, 7, which is a sequence presented out of sequence.
     *
     * Document order is the author's priority order, and for a numbered list
     * the order IS part of the meaning. Printing one fewer bullet is a smaller
     * cost than reordering their steps.
     *
     * @param  string[]  $source
     * @return string[]
     */
    private function finish(array $source, int $limit, int $width): array
    {
        $cleaned = [];
        foreach ($source as $raw) {
            $item = PosterText::tighten(PosterText::deverbose(rtrim(trim($raw), '.;')), $width);
            if ($item !== null && mb_strlen($item) >= self::MIN_ITEM) {
                $cleaned[] = $item;
            }
        }

        $seen = [];
        $out = [];
        foreach ($cleaned as $item) {
            $key = mb_strtolower(mb_substr($item, 0, 24));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
