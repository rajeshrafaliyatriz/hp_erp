<?php

namespace App\Services\Dashboard;

use App\Models\tblmenumaster_g2gModel;

/**
 * DASHBOARD DESTINATIONS, RESOLVED FROM tblmenumaster_g2g.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHY THESE CANNOT BE HARDCODED IN THE FRONTEND
 * ═══════════════════════════════════════════════════════════════════════
 *
 * The menu tree is tenant data, and it moves. In one week this product
 * renamed five submenus and their URLs, moved three menus to another module,
 * and renamed a module root from competency-management to
 * capability-intelligence. Every hardcoded path in a component silently
 * survived those changes as a dead link, because the shell needs a byte-exact
 * access_link and quietly falls back to Home when it does not match.
 *
 * So the component asks for a KEY and the database answers with the PATH.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHY THE KEY IS THE SLUG, NOT THE ID AND NOT THE NAME
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   menu id    - NOT stable across databases. The same screen is 229 locally
 *                and 226 on live; that divergence has caused four incidents,
 *                including one where a rename pointed My Capability at the
 *                Course Competencies URL.
 *   menu_name  - tenant-editable. Renaming a menu in the UI would break the
 *                dashboard.
 *   slug       - the last segment of access_link. It survives module renames
 *                (the prefix changes, the leaf does not) and is not edited by
 *                tenants.
 *
 * A key that resolves to nothing returns NULL, and the control renders
 * disabled with a reason. That is the whole point: a tenant whose plan omits
 * Payroll should see a dead-looking button explained, not a live one that
 * dumps them back on the dashboard.
 *
 * TENANT MATCHING: tblmenumaster_g2g.sub_institute_id is a TEXT comma-list
 * ("1,2,3,6,7"), not an integer, so `= 6` would be a string comparison that
 * matches only a tenant whose entire list is the literal "6". FIND_IN_SET is
 * the correct test - but it is no longer written here.
 *
 * It moved to tblmenumaster_g2gModel::scopeVisibleToTenant(), because this was
 * the FOURTH copy of that predicate and the column turned out to hold one
 * hard-coded literal on every row rather than real per-tenant data. Every
 * organisation with an id of 12 or above resolved ZERO dashboard links, which
 * looked from here like "that tenant's plan omits those modules" - the exact
 * misreading the comment above warns about. See the scope for the full story.
 */
class DashboardLinkResolver
{
    /**
     * key => [slug, query-string to append or null].
     *
     * The query strings are real deep links verified against their screens:
     * leave-requests reads ?apply=1, recruitment reads ?tab= and ?action=.
     */
    private const KEYS = [
        'employee_directory' => ['employee-directory', null],
        'attendance'         => ['attendance-tracking', null],
        'leave_requests'     => ['leave-requests', null],
        'apply_leave'        => ['leave-requests', 'apply=1'],
        'payroll'            => ['monthly-payroll-report', null],
        'recruitment'        => ['recruitment', null],
        'post_job'           => ['recruitment', 'tab=job-openings&action=job'],
        'talent_dashboard'   => ['talent-dashboard', null],
        'performance'        => ['performance-reviews-and-appraisals', null],
        'certifications'     => ['certifications', null],
        'learning_dashboard' => ['learning-dashboard', null],
        'assignments'        => ['assignments', null],
        'sessions_calendar'  => ['sessions-and-calendar', null],
        'competency_dashboard' => ['dashboard', null],
        'task_dashboard'     => ['task-management-dashboard', null],
        'task_reports'       => ['reports-and-analysis', null],
    ];

    /** @return array<string,string|null> key => access_link, or null if absent */
    public function resolve(int $sid): array
    {
        $slugs = array_values(array_unique(array_column(self::KEYS, 0)));

        // deleted_at is handled by the model's SoftDeletes trait, not repeated
        // here - it used to be filtered in this one place and nowhere else,
        // so a soft-deleted menu vanished from dashboard links while staying
        // in the sidebar.
        $rows = tblmenumaster_g2gModel::query()
            ->where('status', 1)
            ->visibleToTenant($sid)
            ->get(['access_link']);

        // slug => the full stored path
        $bySlug = [];
        foreach ($rows as $r) {
            $link = (string) $r->access_link;
            if ($link === '') {
                continue;
            }
            $slug = substr($link, strrpos($link, '/') + 1);
            // First match wins. 'dashboard' is a slug several modules use, so
            // the competency one is disambiguated below rather than here.
            if (!isset($bySlug[$slug])) {
                $bySlug[$slug] = $link;
            }
        }

        // 'dashboard' is ambiguous - Capability Intelligence, LMS and Talent
        // all own one. Pin the competency key to the capability module.
        $competencyDashboard = null;
        foreach ($rows as $r) {
            $link = (string) $r->access_link;
            if (str_contains($link, 'capability-intelligence') && str_ends_with($link, '/dashboard')) {
                $competencyDashboard = $link;
                break;
            }
        }

        $out = [];
        foreach (self::KEYS as $key => [$slug, $query]) {
            $link = $key === 'competency_dashboard' ? $competencyDashboard : ($bySlug[$slug] ?? null);
            $out[$key] = $link === null ? null : ($query ? $link . '?' . $query : $link);
        }

        return $out;
    }

    /**
     * The `menu` value each KPI tile and action row carries -> a link key.
     * Kept here so the backend owns the whole mapping and the component owns
     * none of it.
     */
    public function menuKeyMap(): array
    {
        return [
            'employee-directory'   => 'employee_directory',
            'attendance'           => 'attendance',
            'approvals'            => 'leave_requests',
            'task-management'      => 'task_dashboard',
            'certifications'       => 'certifications',
            'leave-management'     => 'leave_requests',
            'performance'          => 'performance',
            // competency-approvals is deliberately absent: its audit centre has
            // no menu row in any tenant, so the row stays unclickable.
        ];
    }
}
