<?php

namespace App\Services\Intelligence;

use App\Models\tblmenumaster_g2gModel;
use App\Services\Dashboard\DashboardLinkResolver;

/**
 * Where "go and look at the records" points, taken from the menu table - never typed into a component.
 *
 * The menu tree is tenant data that moves, so a path written into a screen becomes a dead link the next time it
 * does. This composes the existing DashboardLinkResolver (for the destinations the Home dashboard already knows)
 * with a lookup, by the same method, for the few additional screens Intelligence points at. A screen the tenant has
 * no menu row for resolves to null, and the client shows the link disabled with a reason.
 */
final class IntelligenceLinks
{
    /** Destinations the Home dashboard resolver already owns. */
    private const FROM_DASHBOARD = ['employee_directory', 'certifications'];

    /** key => final path segment of the menu's access_link, for screens the dashboard resolver does not know. */
    private const EXTRA = [
        'competency_framework' => 'competency-framework',
        'gap_inbox' => 'capability-gap-inbox',
        'assessments' => 'assessments',
        'course_builder' => 'course-builder',
    ];

    public function __construct(private DashboardLinkResolver $dashboard)
    {
    }

    /** @return array<string,string|null> */
    public function resolve(int $sid): array
    {
        $out = [];

        try {
            $known = $this->dashboard->resolve($sid);
            foreach (self::FROM_DASHBOARD as $key) {
                $out[$key] = $known[$key] ?? null;
            }
        } catch (\Throwable) {
            foreach (self::FROM_DASHBOARD as $key) {
                $out[$key] = null;
            }
        }

        try {
            $bySlug = [];
            $rows = tblmenumaster_g2gModel::query()->where('status', 1)->visibleToTenant($sid)->get(['access_link']);
            foreach ($rows as $r) {
                $link = (string) $r->access_link;
                if ($link !== '') {
                    $bySlug[substr($link, strrpos($link, '/') + 1)] ??= $link;
                }
            }
            foreach (self::EXTRA as $key => $slug) {
                $out[$key] = $bySlug[$slug] ?? null;
            }
        } catch (\Throwable) {
            foreach (self::EXTRA as $key => $_) {
                $out[$key] = null;
            }
        }

        return $out;
    }
}
