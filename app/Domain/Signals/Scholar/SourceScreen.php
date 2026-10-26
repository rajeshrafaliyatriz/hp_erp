<?php

namespace App\Domain\Signals\Scholar;

use App\Domain\Signals\Market\EvidenceGrader;

/**
 * Two small judgements about a retrieved page, both made BEFORE the model sees it. Pure.
 *
 * 1. injection(): retrieved web text is untrusted DATA. A page that tries to give the model instructions ("ignore the previous
 *    instructions", "reveal your key", a forged JSON answer) is not evidence of anything a school did, so it is kept out of the
 *    prompt entirely (the run still records that it was retrieved). The prompt also tells the model to treat sources as data; this
 *    removes the cheapest attacks instead of trusting that instruction alone.
 *
 * 2. reliability(): how much a source type can be trusted on its own, kept separate from how well the CLAIM is evidenced.
 *      high    the school's own site, an official/government/academic domain, a tender portal
 *      medium  a job post or a notice the organisation itself publishes on a job board
 *      low     a social post, a forum answer, a news story or any other third party
 */
final class SourceScreen
{
    private const INJECTION = [
        '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(previous|prior|above|earlier|all|any|your|the)\b[^.\n]{0,30}\b(instruction|prompt|rule|message|direction)s?\b/i',
        '/\b(system|developer)\s+(prompt|message|instruction)s?\b/i',
        '/\byou\s+are\s+now\b|\bact\s+as\s+(an?\s+)?(different|new|unrestricted)\b|\bjailbreak\b/i',
        '/\b(reveal|print|output|show|leak|send)\b[^.\n]{0,30}\b(api[\s_-]?key|secret|password|credential|token|system prompt)s?\b/i',
        '/"opportunities"\s*:\s*\[/i',
        '/\bmark\s+(this|it|the\s+school)\b[^.\n]{0,30}\b(hot|high\s+priority|explicit|confirmed)\b/i',
    ];

    private const JOB_BOARDS = ['naukri.com', 'indeed.com', 'shine.com', 'foundit.in', 'glassdoor', 'apna.co', 'timesjobs.com'];
    private const SOCIAL = ['linkedin.com', 'quora.com', 'reddit.com', 'facebook.com', 'x.com', 'twitter.com', 'instagram.com'];

    private const EDUCATION = '/\b(schools?|vidyalaya|academy|cbse|icse|gseb|campus(?:es)?|principal|education|students?|teachers?|admissions?|erp|lms)\b/i';
    private const EDUCATION_GU = '/(\x{0AB6}\x{0ABE}\x{0AB3}\x{0ABE}|\x{0AB5}\x{0ABF}\x{0AA6}\x{0ACD}\x{0AAF}\x{0ABE}\x{0AB2}\x{0AAF}|\x{0AB5}\x{0ABF}\x{0AA6}\x{0ACD}\x{0AAF}\x{0ABE}\x{0AB0}\x{0ACD}\x{0AA5}\x{0AC0}|\x{0AB6}\x{0ABF}\x{0A95}\x{0ACD}\x{0AB7}\x{0A95}|\x{0AB6}\x{0ABF}\x{0A95}\x{0ACD}\x{0AB7}\x{0AA3})/u';
    private const INDIA = '/\b(india|indian|cbse|icse|gseb|gujarat|maharashtra|daman|mumbai|pune|surat|ahmedabad|vadodara|rajkot|nashik|vapi|delhi|bengaluru|bangalore|chennai|hyderabad|kolkata|crore|lakh)\b|\x{20B9}/iu';
    private const ABROAD = '/\b(new york|nyc|colorado|chicago|california|texas|florida|los angeles|washington|america|american|u\.s\.|united states|israel|canada|australia|london|uk|britain|dubai|pakistan|nigeria|kenya|philippines)\b/i';

    /**
     * A page that cannot be about an Indian school is not worth sending to the model (it costs tokens and invites a stretched reading):
     * no education word at all (a hotel appointment, a military story), or a clearly foreign place and nothing Indian. Cheap and
     * deliberately lenient: the model and the gate still decide everything else.
     */
    public static function offTopic(string $title, string $text): bool
    {
        $all = $title . ' ' . mb_substr($text, 0, 3000);

        // A Gujarati-language page (the Gujarati press) has no English education word; its own words count the same way.
        return (preg_match(self::EDUCATION, $all) !== 1 && preg_match(self::EDUCATION_GU, $all) !== 1)
            || (preg_match(self::ABROAD, $all) === 1 && preg_match(self::INDIA, $all) !== 1);
    }

    public static function injection(string $text): bool
    {
        foreach (self::INJECTION as $re) {
            if (preg_match($re, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{name?: ?string, website?: ?string}  $buyer
     * @return array{class: string, reliability: string, host: string}
     */
    public static function reliability(string $url, array $buyer = []): array
    {
        $host = strtolower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $any = fn (array $list) => (bool) array_filter($list, fn ($h) => $host === $h || str_ends_with($host, '.' . $h) || str_contains($host, $h));

        if ((new EvidenceGrader())->isPrimary($url, $buyer)) {
            return ['class' => 'official', 'reliability' => 'high', 'host' => $host];
        }
        if ($any(self::JOB_BOARDS) || ($any(['linkedin.com']) && str_contains($path, '/jobs'))) {
            return ['class' => 'job_post', 'reliability' => 'medium', 'host' => $host];
        }
        if ($any(self::SOCIAL)) {
            return ['class' => 'social_post', 'reliability' => 'low', 'host' => $host];
        }

        return ['class' => 'third_party', 'reliability' => 'low', 'host' => $host];
    }

    /**
     * What kind of pages a run had to work with, so a run that found nothing can say why in plain words. Counts only; nothing is judged here.
     *
     * @param  list<string>  $urls
     * @return array{official: int, job_post: int, social_post: int, third_party: int}
     */
    public static function mix(array $urls): array
    {
        $mix = ['official' => 0, 'job_post' => 0, 'social_post' => 0, 'third_party' => 0];
        foreach ($urls as $url) {
            $mix[self::reliability((string) $url)['class']]++;
        }

        return $mix;
    }

    /** "Pages: 2 official, 5 job posts, 14 social posts, 7 other." Empty when there were no pages. */
    public static function describeMix(array $urls): string
    {
        if ($urls === []) {
            return '';
        }
        $m = self::mix($urls);
        $parts = [];
        foreach (['official' => 'official school site', 'job_post' => 'job post', 'social_post' => 'social post', 'third_party' => 'other page'] as $k => $label) {
            if ($m[$k] > 0) {
                $parts[] = $m[$k] . ' ' . $label . ($m[$k] === 1 ? '' : 's');
            }
        }

        return 'Pages: ' . implode(', ', $parts) . '.';
    }
}
