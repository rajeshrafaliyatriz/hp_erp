<?php

namespace App\Support;

/**
 * THE SINGLE PLACE A CANDIDATE-FACING LINK IS BUILT.
 *
 * WHY THIS EXISTS
 *
 * Three links are emailed to people who have no account here - the assessment
 * paper, the offer letter, and a re-issued offer link. All three were built from
 * `config('app.url')`:
 *
 *     $url = rtrim(config('app.url'), '/') . '/assessment/' . $token;
 *
 * `app.url` is THIS application: Laravel, on 127.0.0.1:8000 locally. But
 * /assessment/{token} and /offer/{token} are Next.js pages in a separate app on
 * a separate origin, and Laravel has no route for either. So every one of those
 * emails carried a link that 404s - measured, not assumed: `grep` over
 * routes/web.php returns nothing for either path.
 *
 * The failure is silent in the worst way. The API returns 200, the row is
 * written, the token is valid for seven days, HR sees "Assessment created and
 * emailed to the candidate" - and the candidate receives a dead link. Nothing in
 * the system knows the difference.
 *
 * WHY A CLASS AND NOT THREE STRING FIXES
 *
 * A per-call-site fix leaves the next candidate-facing link to make the same
 * mistake, and there is no natural moment at which anyone would notice. Routing
 * every such link through here means the question "which origin serves this
 * page?" is answered once, with the reasoning attached.
 *
 * WHAT IT DOES NOT COVER
 *
 * Links to pages Laravel itself serves. Those are correct on `app.url` and must
 * stay there.
 */
final class CandidateLink
{
    /**
     * The origin serving the candidate-facing pages.
     *
     * FRONTEND_URL when set. Otherwise `app.url`, which reproduces exactly the
     * behaviour every call site had before this class existed - so an
     * installation that has not set the variable is no worse off than it was,
     * and one that has is fixed.
     */
    public static function base(): string
    {
        $configured = config('app.frontend_url');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    /**
     * An absolute URL to a candidate-facing page.
     *
     * @param string $path e.g. 'assessment' or 'offer'
     */
    public static function to(string $path, string $token): string
    {
        return self::base() . '/' . trim($path, '/') . '/' . $token;
    }

    /**
     * A public careers page.
     *
     * The same origin question as to(), for the same reason: /careers/{slug} is
     * a Next.js page and Laravel has no route for it. This is here rather than
     * inline because a hiring poster PRINTS the URL - once a poster is shared
     * there is no fixing a wrong one, so the origin has to be decided in the
     * one place that already knows the answer.
     */
    public static function careers(string $slug): string
    {
        return self::base() . '/careers/' . trim($slug, '/');
    }

    /** One public job advert: /careers/{slug}/jobs/{id}. */
    public static function careersPosting(string $slug, int $postingId): string
    {
        return self::careers($slug) . '/jobs/' . $postingId;
    }

    /**
     * The careers origin, allowing a REQUEST to supply it when config has not.
     *
     * ── WHY THIS EXISTS, AND WHY ONLY FOR CAREERS ───────────────────────────
     *
     * FRONTEND_URL is unset on production, so base() falls back to app.url -
     * the API host - and https://hp.triz.co.in/careers/{slug} is a 404, as are
     * /assessment/{token} and /offer/{token}. Every candidate-facing link the
     * system emails is currently dead. That is a configuration fault and the
     * real fix is one environment variable; this is not a substitute for it.
     *
     * But a careers page is different from an emailed link in one way that
     * matters: it is being viewed RIGHT NOW by a browser that knows which
     * origin serves it. When an operator downloads a hiring poster from
     * https://g2g.scholarclone.com, that origin is a fact about the request,
     * not a guess - so the poster can be correct even while the config is not.
     *
     * Deliberately NOT used for assessment or offer links. Those are built
     * inside queued work and emails, where there is no request to ask, and a
     * silently-wrong link there is exactly the failure CandidateLink exists to
     * prevent. They must keep failing loudly until FRONTEND_URL is set.
     *
     * The supplied origin is accepted only when config is absent, only from
     * the Origin/Referer header the browser sets (not a query parameter a
     * caller types), and only as a well-formed http(s) origin with no path.
     */
    public static function careersOrigin(?string $requestOrigin, ?string $apiHost = null): ?string
    {
        if (!self::pointsAtApi()) {
            return self::base();
        }

        $origin = trim((string) $requestOrigin);

        if ($origin === '') {
            return null;
        }

        $parts = parse_url($origin);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        /*
         * Never the API host - that is the dead link this whole path exists to
         * avoid.
         *
         * Checked against the HOST SERVING THIS REQUEST as well as app.url,
         * because app.url can itself be wrong on a misconfigured deployment -
         * and it was, in the first test of this method: with app.url still
         * pointing at localhost, an Origin of https://hp.triz.co.in was
         * accepted and produced the exact 404 URL the guard is for. The
         * request's own host cannot be misconfigured; it is where the call
         * actually arrived.
         */
        $forbidden = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            $apiHost,
        ]);

        if (in_array($parts['host'], $forbidden, true)) {
            return null;
        }

        return $rebuilt;
    }

    /**
     * True when the link about to be sent will not open for a candidate.
     *
     * Callers use it to tell HR that, instead of reporting success and leaving
     * the candidate stuck.
     *
     * ── THE NAME IS NOW NARROWER THAN THE QUESTION ──────────────────────────
     *
     * This asked only "does base() equal app.url", i.e. "is FRONTEND_URL
     * unset". Measured on the API host, FRONTEND_URL is SET - to
     * `http://localhost:3000`. So base() does not equal app.url, this returned
     * FALSE, and all five guards concluded the link was fine while every
     * emailed candidate link pointed at a machine the candidate does not have.
     *
     * A guard against a misconfiguration that only recognises one spelling of
     * it is worse than no guard: it reports safety.
     *
     * Kept as a boolean with the same name so the five existing call sites
     * start catching this without a change; unreachableForCandidates() below
     * is the one to use for a specific message.
     */
    public static function pointsAtApi(): bool
    {
        return self::unreachableForCandidates() !== null;
    }

    /**
     * Why an emailed link would not open, or null when it looks reachable.
     *
     * Deliberately conservative about what counts as unreachable: a loopback or
     * private address, or the API's own origin. Anything else is assumed fine,
     * because guessing that a real hostname is wrong would block legitimate
     * sends - and this runs on the path that emails a candidate.
     */
    public static function unreachableForCandidates(): ?string
    {
        $base = self::base();

        if ($base === rtrim((string) config('app.url'), '/')) {
            return 'FRONTEND_URL is not set, so links point at the API instead of the careers site.';
        }

        $host = strtolower((string) parse_url($base, PHP_URL_HOST));

        if ($host === '') {
            return 'FRONTEND_URL is not a usable address: ' . $base;
        }

        // Loopback and the .local / .internal suffixes nobody outside the
        // network can resolve.
        if ($host === 'localhost' || $host === '::1'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return 'FRONTEND_URL points at ' . $host . ', which a candidate cannot open.';
        }

        // RFC1918. A private address is reachable from the office and from
        // nowhere a candidate will ever be.
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return 'FRONTEND_URL points at the private address ' . $host . ', which a candidate cannot open.';
        }

        if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host)) {
            return 'FRONTEND_URL points at the private address ' . $host . ', which a candidate cannot open.';
        }

        return null;
    }
}
