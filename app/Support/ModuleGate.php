<?php

namespace App\Support;

/**
 * Sales & Marketing unification, Phase 2: the config-flagged switch between
 * GTM's/Signals' original blunt `profile:` role gate and the fine-grained,
 * admin-configurable `platformright:` matrix CRM already uses (see
 * Docs/sales-marketing-merge/DECISIONS.md).
 *
 * Both flags default OFF, so wrapping a route in gtm()/signals() changes
 * nothing until config('gtm.permissions.use_menu_rights') /
 * config('signals.permissions.use_menu_rights') is explicitly flipped true -
 * no code deploy needed either way, since route registration re-reads
 * config on every boot.
 *
 * `$action` must be one of 'view'|'add'|'edit'|'delete' - the same
 * vocabulary RequirePlatformRight itself enforces.
 *
 * ── WHY ONE ACTION PER PRE-EXISTING `profile:` GROUP, NOT PER HTTP VERB ────
 *
 * GTM's two tiers (admin,executive / admin) map cleanly onto 'view' vs any
 * mutating action, because executive's grant is uniformly view-only across
 * every GTM menu row (see 2026_10_10_100100_add_gtm_menu_and_rights.php and
 * every GTM page migration after it: administrator [1,1,1,1], executive
 * [1,0,0,0]) - so 'view'/'add'/'edit'/'delete' all resolve to the same two
 * role sets GTM already has, and the action passed matters only for which
 * grant column is read, never for who passes.
 *
 * Signals is less uniform. Its `profile:admin,hr,manager` tier (read +
 * review/dismiss) and `profile:admin,hr` tier (generate/run/ingest/import)
 * were deliberately reproduced as 'view'≡'edit' and 'add' respectively on
 * every Signals menu row (2026_11_29_080200_add_signals_menu_branch.php's
 * GRANTS map was built to match exactly) - EXCEPT ingestion's one delete
 * route, corrected separately in
 * 2026_11_29_080400_fix_ingestion_delete_rights.php so 'delete' can be used
 * there without narrowing hr_manager/hr_executive's existing access. Pass
 * the action that matches the ORIGINAL profile: group a route sat in, not
 * the HTTP verb, when the two disagree - see the call sites in
 * routes/gtm.php and routes/api.php for the worked mapping.
 */
final class ModuleGate
{
    public static function gtm(string $accessLink, string $action = 'view'): string
    {
        if (! config('gtm.permissions.use_menu_rights')) {
            return $action === 'view' ? 'profile:admin,executive' : 'profile:admin';
        }

        return "platformright:{$accessLink},{$action}";
    }

    /**
     * $tier identifies which of Signals' two original profile: groups this
     * route came from - 'read' (profile:admin,hr,manager) or 'write'
     * (profile:admin,hr) - so the fallback string is exact when the flag is
     * off, regardless of which platformright $action is requested for the
     * flag-on path.
     */
    public static function signals(string $accessLink, string $action = 'view', string $tier = 'read'): string
    {
        if (! config('signals.permissions.use_menu_rights')) {
            return $tier === 'write' ? 'profile:admin,hr' : 'profile:admin,hr,manager';
        }

        return "platformright:{$accessLink},{$action}";
    }
}
