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

    /**
     * May this process send mail FOR ONE ORGANISATION?
     *
     * ── WHY A SECOND SWITCH, WHEN THE DOCBLOCK ABOVE WARNS AGAINST ONE ──────
     *
     * The warning is against a constraint that reads as global and holds only
     * locally. This is the opposite: a NARROWER gate that can only ever permit
     * less than the global flag would, never more, and that is visible in the
     * same class rather than hidden in a caller.
     *
     * It exists because turning G2G_NOTIFY_EMAIL on is all-or-nothing, and the
     * environment carries hundreds of real addresses at real companies. Enabling
     * mail for one test organisation should not be the same act as enabling it
     * for every organisation.
     *
     * The relationship is deliberately OR, not AND: the master flag stays off,
     * so `allowed() && inList()` would be permanently false and useless. That
     * means mail CAN leave the building while `allowed()` still reports false —
     * which is exactly the drift this class exists to prevent. So the tripwire in
     * Docs/phase3/_evidence/phase3-smoke.php was amended in the same change to
     * assert this allowlist as well. If you widen the list, widen the tripwire.
     *
     * G2G_NOTIFY_EMAIL_TENANTS is a comma-separated list of sub_institute_id.
     * Empty or unset means nobody, which is the default.
     */
    public static function allowedForTenant(?int $tenantId): bool
    {
        if (self::allowed()) {
            return true;
        }

        return $tenantId !== null && in_array($tenantId, self::allowedTenants(), true);
    }

    /** The organisations mail may currently leave for. Empty by default. */
    public static function allowedTenants(): array
    {
        $raw = (string) env('G2G_NOTIFY_EMAIL_TENANTS', '');

        return array_values(array_filter(
            array_map(static fn ($v) => (int) trim($v), explode(',', $raw)),
            static fn ($v) => $v > 0
        ));
    }

    /** Why a send was refused for this organisation, or null when it was not. */
    public static function reasonForTenant(?int $tenantId): ?string
    {
        if (self::allowedForTenant($tenantId)) {
            return null;
        }

        return 'Outbound email is disabled for this organisation '
            . '(G2G_NOTIFY_EMAIL is off and this tenant is not in G2G_NOTIFY_EMAIL_TENANTS).';
    }
}
