<?php
/**
 * SHARED HELPERS FOR EVERY PATTERN SCRIPT IN THIS DIRECTORY.
 *
 * WHY THIS FILE EXISTS - it is a remedy, not a tidy-up.
 *
 * Failing to strip comments before matching has now cost two measurements in one
 * session:
 *
 *   Q3   counted `// return $request;exit;` - a line somebody had already
 *        DISABLED - and reported 47 echo-the-request endpoints. The real number
 *        is 2.
 *   the mail-gate check
 *        SKIPPED, because its known-negative matched a commented send. It was
 *        testing the raw pattern while the scan strips first.
 *
 * That is the FIFTH instance this session of a rule that was current, written
 * down, and not reached for at the point of use. Restating it a sixth time would
 * be the error the observation describes, so the stripper stops being a thing to
 * remember and becomes a thing you import.
 *
 * THE PATTERN IS THE CORRECTED ONE. The suite carried
 *
 *     a pattern anchored with ^ and the m flag, i.e. LINE-LEADING ONLY
 *
 * which leaves `$x = 1;  // Mail::send(` intact - exactly the shape that broke
 * the known-negative. The form below strips trailing comments too, while the
 * `(?<!:)` guard protects `https://` and `foo::bar` from being eaten.
 */

if (!function_exists('stripComments')) {
    /**
     * Remove PHP/JS comments so a pattern matches CODE rather than prose.
     *
     * NOT string-aware: a `//` inside a quoted string is removed as well. That is
     * a known limit, stated rather than discovered - it makes this stripper
     * conservative for "does the code do X" and unsuitable for anything that
     * needs to read string literals.
     */
    function stripComments(string $s): string
    {
        $s = preg_replace('#/\*.*?\*/#s', '', $s);
        return preg_replace('#(?<!:)//[^\n]*#', '', $s);
    }
}

if (!function_exists('stripCommentsSelfTest')) {
    /**
     * The stripper's own known-positive AND known-negative, run through the SAME
     * function every caller uses.
     *
     * A KNOWN-NEGATIVE APPLIED TO HALF THE INSTRUMENT REPORTS ON AN INSTRUMENT
     * THAT DOES NOT EXIST - that is what happened to the mail-gate check, whose
     * negative tested a raw pattern while the scan stripped first.
     *
     * @return string  '' when sound, otherwise the reason it cannot be trusted
     */
    function stripCommentsSelfTest(): string
    {
        // KNOWN-POSITIVE: a disabled line must disappear - the Q3 case.
        if (str_contains(stripComments('// return $request;'), 'return')) {
            return 'a line-leading comment survived the stripper';
        }
        // KNOWN-POSITIVE: a TRAILING comment must disappear - the case the old
        // suite pattern missed and the mail-gate negative tripped on.
        if (str_contains(stripComments('$x = 1;  // Mail::send($y);'), 'Mail::send')) {
            return 'a trailing comment survived the stripper';
        }
        // KNOWN-NEGATIVE: live code must NOT be eaten.
        if (!str_contains(stripComments('Mail::to($x)->send($y);'), 'Mail::to')) {
            return 'live code was removed - the stripper is too greedy';
        }
        // KNOWN-NEGATIVE: a URL must survive, or every path string is mangled.
        if (!str_contains(stripComments('$u = "https://example.test/a";'), 'example.test')) {
            return 'a URL was eaten by the // rule';
        }
        return '';
    }
}
