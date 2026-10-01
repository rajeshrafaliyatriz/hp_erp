<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The `tblgroupwise_rights_g2g` precedence rule, extracted from
 * `RequireMenuRight` so a second caller (`RequirePlatformRight`) can reuse it
 * without a private copy. `RequireMenuRight` delegates to `can()` for its own
 * existing (fixed numeric id) call sites — this class changes nothing about
 * how those already behave.
 *
 * `idForAccessLink()` exists because Platform Services' menu rows are
 * addressed by `access_link` everywhere else in this codebase (migrations,
 * the frontend registry) rather than by a numeric id that can legitimately
 * differ between the `default` and `live` connections.
 */
final class MenuRight
{
    /**
     * ── F-152. WHY THE TENANT CLAUSE IS A PREFERENCE, NOT A FILTER ──────────
     *
     * This was `->where('sub_institute_id', $tenant)`, and that single line is
     * why menu-right enforcement could never be switched on. Measured on live
     * before changing it: of twelve organisations, seven hold just 4 tenant-
     * stamped rows against ~149 menus (445 rows each, the rest NULL-stamped by
     * the old tenant-blind seeding command). With a strict filter those
     * tenants match no row on almost every screen, `permits(null)` returns
     * false, and every one of their users is refused everywhere.
     *
     * The clause is not protecting anything either. `$profileId` comes from
     * the CALLER'S OWN `tbluser` row, and `tbluserprofilemaster.id` is a
     * global auto-increment primary key, so a row naming that profile belongs
     * to that caller's organisation whatever its own stamp says. The stamp is
     * data rot, not ownership — the same conclusion reached for
     * `displaySidebarMenu`.
     *
     * So: the correctly stamped row wins, and a legacy row applies only when
     * there is no stamped one.
     */
    public static function can(int $profileId, int $menuId, ?int $tenant, string $action = 'view'): bool
    {
        $rows = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->where('profile_id', $profileId)
            ->get();

        $row = $rows->first(fn ($candidate) => (string) $candidate->sub_institute_id === (string) $tenant)
            ?? $rows->first();

        return self::permits($row, $action);
    }

    /**
     * THE PRECEDENCE, IN ONE PLACE, AS DECIDED:
     *
     *   individual DENY > group DENY > individual ALLOW > group ALLOW
     *                   > role default > DENY
     *
     * ⚠ IMPLEMENTED AS STATED, AND ONE LEVEL IS UNREACHABLE. Recorded rather
     * than quietly re-ordered.
     *
     * `can_*` is `tinyint(1) NOT NULL DEFAULT 0`, so it cannot distinguish
     * "explicitly denied by the group" from "not granted". Reading GROUP DENY
     * as `can_x = 0` — the only reading the column supports — makes
     * INDIVIDUAL ALLOW unreachable:
     *
     *   can_x = 1  ->  the group already allows; individual ALLOW changes nothing
     *   can_x = 0  ->  group DENY, which outranks individual ALLOW
     *
     * **So an individual grant can never widen access beyond the group.**
     * That may be intended — it is the safer direction, and it means the
     * group matrix is always an upper bound. NOT CHANGED HERE: altering the
     * shape of a column dozens of live rows depend on is not a side effect of
     * a refactor.
     */

    private static function permits(?object $row, string $action): bool
    {
        if (!$row) {
            return false;                                    // role default > DENY
        }

        $individual = $row->{'right_' . $action} ?? null;     // 'allow'|'deny'|null
        $group      = (int) ($row->{'can_' . $action} ?? 0) === 1;

        if ($individual === 'deny') return false;             // 1. individual DENY
        if (!$group)                return false;             // 2. group DENY
        if ($individual === 'allow') return true;             // 3. individual ALLOW
        return $group;                                        // 4. group ALLOW
    }

    /** The tblmenumaster_g2g id for a real access_link, or null. Cached — this table changes rarely. */
    public static function idForAccessLink(string $accessLink): ?int
    {
        return Cache::remember(
            'menu_id_for_link:' . $accessLink,
            300,
            fn () => DB::table('tblmenumaster_g2g')->where('access_link', $accessLink)->value('id')
        );
    }
}
