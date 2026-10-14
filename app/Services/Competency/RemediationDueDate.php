<?php

namespace App\Services\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ManagesCompetencySettings;
use Illuminate\Support\Facades\DB;

/**
 * How long a gap-driven course assignment has to be completed, and WHICH of
 * three tiers decided that - shared by CapabilityGapResponder (the write), the
 * rating-write API's gap preview, and the due-date reminder cron, so the three
 * never disagree about what a due date means.
 *
 * ── THE THREE TIERS, FIRST MATCH WINS ───────────────────────────────────────
 *
 *   1. lms_course_settings.completion_days   the COURSE's own authoring policy
 *   2. jobrole_competency_map.remediation_days  the REQUIREMENT's urgency
 *   3. s_competency_settings (scope 'remediation')  the TENANT's default,
 *      split mandatory vs optional - a role that requires something at level 5
 *      is not the same urgency as an optional nice-to-have
 *
 * All three are nullable; a course or requirement that has never set one
 * simply falls through to the next tier.
 *
 * ── WHY THE BASIS IS RECORDED, NOT JUST THE DATE ────────────────────────────
 *
 * lms_assignments.due_date_basis carries which tier won, the same
 * transparency principle as g2g_notification.recipient_reason: "why is this
 * due Friday" should be answerable from the data, not from re-deriving the
 * resolution order by hand against three tables.
 */
class RemediationDueDate
{
    use ManagesCompetencySettings;

    public const BASIS_COURSE_SLA = 'course_sla';
    public const BASIS_REQUIREMENT_URGENCY = 'requirement_urgency';
    public const BASIS_TENANT_DEFAULT = 'tenant_default';

    /**
     * Public so the Governance screen's settings controller can read/write the
     * same defaults without a second, driftable copy of this list.
     */
    public const SETTINGS_DEFAULTS = [
        'due_days_mandatory' => 30,
        'due_days_optional' => 90,
        'auto_assign_enabled' => true,
        'suggest_course_creation_enabled' => true,
        'reflag_cooldown_days' => 30,
    ];

    /** The tenant's remediation settings, merged over their defaults. */
    public function settings(int $tenant): array
    {
        return $this->competencySettings($tenant, 'remediation', self::SETTINGS_DEFAULTS);
    }

    /**
     * @return array{due_date: string, basis: string}
     */
    public function resolveFor(int $tenant, int $courseId, ?int $jobroleId, int $competencyId, bool $isMandatory): array
    {
        $courseDays = DB::table('lms_course_settings')
            ->where('course_id', $courseId)
            ->value('completion_days');

        if ($courseDays !== null && (int) $courseDays > 0) {
            return [
                'due_date' => now()->addDays((int) $courseDays)->toDateString(),
                'basis' => self::BASIS_COURSE_SLA,
            ];
        }

        if ($jobroleId) {
            $requirementDays = DB::table('jobrole_competency_map')
                ->where('sub_institute_id', $tenant)
                ->where('jobrole_id', $jobroleId)
                ->where('competency_id', $competencyId)
                ->value('remediation_days');

            if ($requirementDays !== null && (int) $requirementDays > 0) {
                return [
                    'due_date' => now()->addDays((int) $requirementDays)->toDateString(),
                    'basis' => self::BASIS_REQUIREMENT_URGENCY,
                ];
            }
        }

        $settings = $this->settings($tenant);
        $days = $isMandatory ? $settings['due_days_mandatory'] : $settings['due_days_optional'];

        return [
            'due_date' => now()->addDays((int) $days)->toDateString(),
            'basis' => self::BASIS_TENANT_DEFAULT,
        ];
    }
}
