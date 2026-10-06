<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * THE ONE IMPLEMENTATION OF `g2gActorId`.
 *
 * It existed FIFTEEN TIMES, byte-identical, as a private method copied into
 * fifteen controllers. This trait replaces all fifteen.
 *
 * ── WHY THIS ONE WAS SAFE TO CONSOLIDATE AND THE RESOLVERS ARE NOT ─────────
 *
 * The four identity RESOLVERS differ in behaviour - one throws on a mismatched
 * tenant where the shared trait silently ignores it - so consolidating them is a
 * live behaviour change, and 21 of the 54 exposed callers are files that cannot
 * be committed (G-BLOCK-01).
 *
 * These fifteen were **byte-identical**, re-verified at the moment of
 * consolidating rather than trusted from an earlier count: 15 files, 1 distinct
 * method body. **NO BEHAVIOUR CHANGES**, which is the whole justification, and it
 * is asserted after rather than assumed.
 *
 * ── WHAT IT RESOLVES, AND WHAT IT DOES NOT ────────────────────────────────
 *
 * An ACTOR - who did this - not a TENANT. That distinction is why the original
 * cleared the G-SEC-26 triage: it reads a token, then a SESSION, and never a
 * request-supplied value. A session is server-side; a request body is not.
 *
 *   1. `apiUserId($request)`  - the token's owner. Proven identity.
 *   2. `session('user_id')`   - server-side, for the Blade-era callers.
 *   3. NULL                   - never a request value, at any point.
 *
 * Returning NULL is correct and deliberate: an unattributable action is recorded
 * as unattributed rather than as somebody's guess.
 *
 * ── THE CLEARANCE THAT COVERED THIS ───────────────────────────────────────
 *
 * The register cleared `jobroletaskcontroller::g2gActorId` - one helper, one
 * class, and it named the wrong path. There were fifteen. The claim was correct
 * and generalised only because every copy was identical, **which nobody had
 * measured.** A CLEARANCE OF ONE INSTANCE IS NOT A CLEARANCE OF A PATTERN, EVEN
 * WHEN IT TURNS OUT TO BE. Consolidating removes the gap between the two.
 *
 * Requires `ResolvesApiIdentity` for `apiUserId()`. All fifteen already used it.
 */
trait ResolvesG2gActor
{
    private function g2gActorId(Request $request): ?int
    {
        $fromToken = $this->apiUserId($request);
        if ($fromToken) {
            return $fromToken;
        }
        $fromSession = $request->session()->get('user_id');

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }
}
