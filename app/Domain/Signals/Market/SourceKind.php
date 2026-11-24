<?php

namespace App\Domain\Signals\Market;

/**
 * Recognises sources that describe a topic but are not a buying event: opinion pieces, blogs, columns and academic papers. Pure and deliberately
 * conservative: it only says yes on a clear marker in the host or the path, never from guessing at the text.
 * Used by SignalGrade through the researcher; it is an annotation input and never rejects a candidate by itself.
 */
final class SourceKind
{
    /** Hosts of journals, repositories and preprint servers (the registrable domain or a subdomain of it). */
    private const RESEARCH_HOSTS = [
        'researchgate.net', 'academia.edu', 'arxiv.org', 'ncbi.nlm.nih.gov', 'pubmed.ncbi.nlm.nih.gov', 'sciencedirect.com', 'springer.com', 'link.springer.com',
        'jstor.org', 'tandfonline.com', 'wiley.com', 'onlinelibrary.wiley.com', 'academic.oup.com', 'ieeexplore.ieee.org', 'semanticscholar.org', 'ssrn.com', 'doi.org',
    ];

    /** Path markers of editorial content. */
    private const OPINION_PATH = '#/(opinion|opinions|editorial|editorials|column|columns|blog|blogs|viewpoint|perspective|commentary|thought-leadership)(/|$|-)#i';

    /** Company directories and "top N" list sites: they list organisations, they do not report an event that gives one a reason to buy. */
    private const DIRECTORY_HOSTS = [
        'themanifest.com', 'clutch.co', 'goodfirms.co', 'sortlist.com', 'designrush.com', 'topdevelopers.co', 'techbehemoths.com', 'justdial.com', 'indiamart.com',
        'tradeindia.com', 'builtin.com', 'builtinpune.in', 'builtinchicago.org', 'sulekha.com', 'yellowpages.in', 'crunchbase.com', 'zoominfo.com', 'owler.com', 'glassdoor.com', 'ambitionbox.com', 'naukri.com',
    ];

    private const LISTICLE_PATH = '#/(top|best)[-_]\d+|/(top|best)[-_][a-z0-9-]*(companies|firms|vendors|providers|agencies|startups|consultancies|consulting)(-|/|$)|/[a-z0-9-]*(companies|firms|startups|agencies|vendors|consultancies)-(in|of|to-watch)-[a-z-]+|/list[-_]of[-_]|/companies/|/directory/|/rankings?/|/leaderboard#i';

    public static function isDirectoryOrListicle(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.|in\.)/', '', $host) ?? $host;
        foreach (self::DIRECTORY_HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return true;
            }
        }

        return preg_match(self::LISTICLE_PATH, (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    public static function isOpinionOrResearch(?string $url, ?string $title = null): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
        foreach (self::RESEARCH_HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return true;
            }
        }
        if ($host !== '' && preg_match('/(^|\.)(edu|ac\.in|edu\.in)$/', $host) === 1 && preg_match('#/(publication|publications|journal|journals|article|paper|papers|thesis|abstract)/#i', (string) parse_url($url, PHP_URL_PATH)) === 1) {
            return true;
        }

        return preg_match(self::OPINION_PATH, (string) parse_url($url, PHP_URL_PATH)) === 1;
    }
}
