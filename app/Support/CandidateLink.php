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
