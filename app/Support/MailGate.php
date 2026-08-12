<?php

namespace App\Support;

/**
 * THE SINGLE GATE EVERY OUTBOUND MAIL PATH PASSES THROUGH.
 *
 * WHY THIS EXISTS
 *
 * "Email is off" was treated as a global guarantee for the whole of this
 * engagement. Measured 2026-08-13, it was a property of ONE FILE:
 *
 *     send sites in app/                    9
 *     files containing a send               7
 *       CONSULT G2G_NOTIFY_EMAIL            1   <- NotificationSender only
 *       DO NOT consult it                   6
 *
 * A CONSTRAINT THAT READS AS GLOBAL AND HOLDS LOCALLY IS WORSE THAN NO
 * CONSTRAINT, BECAUSE IT STOPS PEOPLE LOOKING. Six files could send while the
 * flag said off, and nobody checked because the flag looked like the answer.
 *
 * WHY A GATE AND NOT SIX FIXES
 *
 * A per-route fix leaves the same false guarantee in place for whatever is added
 * next. The suite asserts that NO send site bypasses this class, so an eighth
 * file cannot quietly reintroduce the gap - which is the only form of this that
 * survives the codebase growing.
 *
 * THE FLAG'S MEANING IS UNCHANGED. This does not turn email on, weaken the three
 * conditions on G2G_NOTIFY_EMAIL, or add a second switch. It makes the existing
 * switch mean what everyone already believed it meant.
 */
final class MailGate
{
    /**
     * May this process send mail at all?
     *
     * Reads the same env var, the same way, as NotificationSender did when it was
     * the only file consulting it - so nothing about the flag's semantics moves.
     */
    public static function allowed(): bool
    {
        return filter_var(env('G2G_NOTIFY_EMAIL', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Why a send was refused, for logs and for API responses.
     *
     * Callers are expected to report this rather than to fail silently: a send
     * that is dropped without a word is indistinguishable from one that was
     * delivered, which is the shape of defect this codebase has produced twice
     * already (a 200 with no row, and a 200 with no email).
     */
    public static function reason(): string
    {
        return 'Outbound email is disabled for this environment (G2G_NOTIFY_EMAIL).';
    }
}
