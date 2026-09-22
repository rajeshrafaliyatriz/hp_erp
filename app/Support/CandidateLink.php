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
     * True when the origin still points at this API rather than the front end.
     *
     * Callers use it to tell HR that the link they are about to send will not
     * open, instead of reporting success and leaving the candidate stuck.
     */
    public static function pointsAtApi(): bool
    {
        return self::base() === rtrim((string) config('app.url'), '/');
    }
}
