<?php

namespace App\Support;

/**
 * A HUMAN NAME FOR THE THING SOMEBODY SIGNED IN FROM.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every access token in this product is called `api-token`. All 4,955 of them on
 * live. So the "Where you are signed in" list - the one screen whose entire job
 * is to let somebody recognise a session and end the ones they do not know -
 * showed a wall of identical rows. Nobody can audit that, and nobody can act on
 * it: you cannot decide whether to sign out `api-token` when the other eleven
 * are also `api-token`.
 *
 * `personal_access_tokens.name` is a VARCHAR(191) that already exists, so this
 * needs no migration. The label is written once, at sign-in, from the
 * User-Agent of the request that created the token.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * HAND-ROLLED, AND DELIBERATELY SHALLOW
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * There is no UA parser in composer.json and this does not add one. A
 * device-detection library is a large dependency with a data file that needs
 * updating, and the cost of being wrong here is a slightly odd row in a list -
 * not a security decision, not a routing decision, nothing that branches.
 *
 * So the rules below are short and ordered by specificity, and everything
 * unrecognised becomes `Unknown browser` rather than a guess. Being visibly
 * unsure is better than confidently mislabelling somebody's phone.
 *
 * ── ORDER MATTERS, AND IT IS THE WHOLE TRICK ────────────────────────────────
 *
 * Every browser lies in its User-Agent for compatibility. Chrome claims Safari,
 * Edge claims Chrome AND Safari, Opera claims all three. So the checks run from
 * the most specific token to the least, and the first hit wins - which is why
 * Edge is tested before Chrome and Chrome before Safari. Reversing any pair
 * silently relabels a large share of real traffic.
 */
final class DeviceLabel
{
    /** Kept well inside `name`'s VARCHAR(191) - the longest output is ~40 chars. */
    private const MAX = 120;

    /**
     * Browser needles, MOST SPECIFIC FIRST. See the note on order above.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        // Edge announces itself as Edg/ (and the legacy Edge/), while also
        // claiming Chrome and Safari. It has to come first.
        'edg' => 'Edge',
        'opr' => 'Opera',
        'opera' => 'Opera',
        'samsungbrowser' => 'Samsung Internet',
        'firefox' => 'Firefox',
        'fxios' => 'Firefox',
        // Chrome on iOS is CriOS, and is NOT Chrome's engine - but calling it
        // Chrome is what the person expects to read.
        'crios' => 'Chrome',
        'chrome' => 'Chrome',
        // Last, because almost everything above also contains "safari".
        'safari' => 'Safari',
    ];

    /**
     * Platform needles, MOST SPECIFIC FIRST.
     *
     * `windows nt 10.0` covers both Windows 10 and 11 - Microsoft never
     * incremented the token, so a UA cannot tell them apart. "Windows 10 or 11"
     * is the honest answer and is what this returns; inventing a version would
     * be a guess presented as a fact.
     *
     * @var array<string, string>
     */
    private const PLATFORMS = [
        'windows nt 10.0' => 'Windows 10 or 11',
        'windows nt 6.3' => 'Windows 8.1',
        'windows nt 6.1' => 'Windows 7',
        'windows' => 'Windows',
        // Checked before "mac", because an iPad's UA contains "Macintosh".
        'iphone' => 'iPhone',
        'ipad' => 'iPad',
        'ipod' => 'iPod',
        'android' => 'Android',
        'cros' => 'ChromeOS',
        'mac os x' => 'macOS',
        'macintosh' => 'macOS',
        'ubuntu' => 'Ubuntu',
        'linux' => 'Linux',
    ];

    /**
     * "Chrome on Windows 10 or 11", or something honest about not knowing.
     *
     * Never throws and never returns an empty string: this value goes straight
     * into a NOT NULL column on the sign-in path, and a sign-in must not fail
     * because a User-Agent was strange.
     */
    public static function from(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));

        if ($ua === '') {
            /*
             * No User-Agent at all is the normal case for a script, a curl call
             * or an app that does not set one - and saying so is more useful than
             * "Unknown", because it tells the reader this was probably not a
             * person at a browser.
             */
            return 'API or script';
        }

        $browser = self::match($ua, self::BROWSERS);
        $platform = self::match($ua, self::PLATFORMS);

        if ($browser && $platform) {
            return self::cap($browser . ' on ' . $platform);
        }

        if ($browser) {
            return self::cap($browser);
        }

        if ($platform) {
            return self::cap('Unknown browser on ' . $platform);
        }

        return 'Unknown browser';
    }

    /** @param array<string, string> $needles */
    private static function match(string $ua, array $needles): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($ua, $needle)) {
                return $label;
            }
        }

        return null;
    }

    private static function cap(string $label): string
    {
        return strlen($label) > self::MAX ? substr($label, 0, self::MAX) : $label;
    }
}
