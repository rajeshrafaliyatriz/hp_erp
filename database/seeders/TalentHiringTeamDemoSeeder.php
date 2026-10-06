<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TENANT 6 DEMO DATA FOR THE HIRING TEAM AND RESUME REVIEWS.
 *
 *   php artisan db:seed --class=TalentHiringTeamDemoSeeder --database=mysql --force
 *   php artisan db:seed --class=TalentHiringTeamDemoSeeder --database=live  --force
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WRITTEN THROUGH THE CONTROLLERS, NOT INTO THE TABLES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every write below goes through the SAME CONTROLLER a click would reach, with
 * a real Sanctum token minted for tenant 6's administrator, so the tenant is
 * resolved from the token exactly as it is for a signed-in user. THIS SEEDER
 * NEVER SUPPLIES A TENANT ID.
 *
 * That rule belongs to Tenant6DemoSeeder and its reasoning is worth reading
 * there: raw inserts bypass validation, tenant resolution and every guard, so
 * data that arrives that way PROVES NOTHING ABOUT THE PRODUCT. Seeding this way
 * doubles as proof that the two new endpoints actually work.
 *
 * IF A CONTROLLER REFUSES, THAT IS A REAL FINDING AND IT IS REPORTED rather
 * than worked around.
 *
 * ── WHY THIS IS A SEPARATE CLASS FROM Tenant6DemoSeeder ─────────────────────
 *
 * It would sit naturally inside that seeder, and the token machinery below is
 * the same shape as its. It is separate because Tenant6DemoSeeder also seeds
 * the competency model, and those writes are not safely repeatable - re-running
 * that class to reach two new sections would risk duplicating competency rows.
 * Kept apart, this can be run on both hosts, twice, without touching any of it.
 * The duplication is about twenty lines and it is deliberate.
 *
 * ── IDEMPOTENT ──────────────────────────────────────────────────────────────
 *
 * Both sections skip entirely if tenant 6 already holds rows, so a second run
 * is a no-op and says so.
 */
class TalentHiringTeamDemoSeeder extends Seeder
{
    private const TENANT = 6;

    public function run(): void
    {
        $admin = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.sub_institute_id', self::TENANT)
            ->where('p.role_key', 'administrator')
            ->first(['u.id']);

        if (!$admin) {
            $this->command->error('No administrator in tenant ' . self::TENANT . ' - cannot seed as a real user.');
            return;
        }

        $userModel = \App\Models\auth\tbluserModel::find($admin->id);
        $token = $userModel->createToken('tenant6-hiring-team-seeder');
        $bearer = explode('|', $token->plainTextToken, 2)[1];

        $this->command->info('Acting as administrator #' . $admin->id . ' via a real token.');

        try {
            $this->seedHiringTeam($bearer);
            $this->seedResumeScreenings($bearer);
        } finally {
            $token->accessToken->delete();
            $this->command->info('Token deleted.');
        }
    }

    /** A request carrying the admin's token, exactly as the frontend sends. */
    private function request(string $bearer, string $method, array $payload = []): Request
    {
        $r = Request::create('/seed', $method, $payload);
        $r->headers->set('Authorization', 'Bearer ' . $bearer);

        return $r;
    }

    /**
     * The hiring team.
     *
     * ── WHY THIS DATA EXISTS AT ALL ─────────────────────────────────────────
     *
     * talent_team_members held 0 rows on both hosts. Audit F-59 listed it among
     * five tables with no tenant column and the proposal was to drop it; the
     * decision was to keep it and make it real. An empty table demonstrates
     * nothing, so this is the data that shows the new roster screen working.
     *
     * It also lights up a screen nobody built for it: the table is a registered
     * entry in the department merge/delete engine, so as soon as these rows
     * exist with a department, deleting that department warns "N team members".
     *
     * ── WHY NOTHING HERE IS HARD-CODED BY DEPARTMENT ID ─────────────────────
     *
     * The two databases disagree about tenant 6's departments - the application
     * host numbers them 87…1860 and live numbers them 117…2198, with different
     * names in each. A seeder holding literal department ids would put people in
     * the wrong department on one host and fail on the other. Departments are
     * resolved BY NAME, per host, and a name that is not present is left null
     * and reported rather than guessed at.
     */
    private function seedHiringTeam(string $bearer): void
    {
        $existing = DB::table('talent_team_members')
            ->where('sub_institute_id', self::TENANT)->whereNull('deleted_at')->count();

        if ($existing > 0) {
            $this->command->info("  hiring team: {$existing} member(s) already present, leaving them alone");

            return;
        }

        $team = app(\App\Http\Controllers\talent\HiringTeamController::class);

        // Real tenant-6 people. Ids 28-35 exist on both hosts with the same
        // names, which is what makes this reproducible rather than host-specific.
        $roster = [
            [28, 'HR Manager',  'Information Technology'],
            [29, 'Recruiter',   'Development'],
            [30, 'Recruiter',   'Development'],
            [31, 'Interviewer', 'Development'],
            [32, 'Interviewer', 'Development'],
            [33, 'Interviewer', 'Support'],
        ];

        $added = 0;
        $refused = 0;
        $skipped = 0;

        foreach ($roster as [$userId, $role, $departmentName]) {
            if (!DB::table('tbluser')->where('sub_institute_id', self::TENANT)->where('id', $userId)->exists()) {
                $skipped++;
                $this->command->warn("  hiring team: user {$userId} is not in tenant " . self::TENANT . ' on this host, skipped');
                continue;
            }

            $departmentId = DB::table('hrms_departments')
                ->where('sub_institute_id', self::TENANT)
                ->where('department', $departmentName)
                ->whereNull('deleted_at')
                ->value('id');

            if (!$departmentId) {
                $this->command->warn("  hiring team: no department named '{$departmentName}' on this host, adding without one");
            }

            $payload = ['user_id' => $userId, 'role' => $role];

            if ($departmentId) {
                $payload['department_id'] = $departmentId;
            }

            $res = $team->store($this->request($bearer, 'POST', $payload));

            if ($res->getStatusCode() < 300) {
                $added++;
                continue;
            }

            // A refusal is a finding, not something to work around.
            $refused++;
            $body = json_decode($res->getContent(), true);
            $this->command->warn(sprintf(
                '  hiring team: user %d REFUSED %d - %s',
                $userId,
                $res->getStatusCode(),
                $body['message'] ?? 'no message'
            ));
        }

        $this->command->info(sprintf('  hiring team: %d added, %d refused, %d skipped', $added, $refused, $skipped));
    }

    /**
     * The reviews those recruiters left on real applications.
     *
     * Applications 433-448 exist on BOTH hosts under the same ids with the same
     * people and statuses - 21 of tenant 6's applications match exactly - so the
     * same seed produces the same demo on either.
     *
     * The scores deliberately span every band the screen renders (strong / worth
     * a look / partial / weak) and one sits on an application nobody progressed,
     * so the tab shows a real spread rather than a column of green.
     */
    private function seedResumeScreenings(string $bearer): void
    {
        $existing = DB::table('talent_resume_screenings')
            ->where('sub_institute_id', self::TENANT)->whereNull('deleted_at')->count();

        if ($existing > 0) {
            $this->command->info("  resume screenings: {$existing} already present, leaving them alone");

            return;
        }

        $screening = app(\App\Http\Controllers\talent\ResumeScreeningController::class);

        $reviews = [
            [433, 88.5, 'Laravel, MySQL, REST APIs, Redis', 'Strong backend depth. Ships tested code and explains trade-offs well.'],
            [434, 76.0, 'React, TypeScript, Tailwind', 'Solid frontend. Less exposure to accessibility than the role wants.'],
            [436, 64.5, 'PHP, JavaScript, Git', 'Covers the basics. Would need mentoring on architecture for the first year.'],
            [437, 91.0, 'Python, Django, PostgreSQL, Docker, CI/CD', 'Best CV in this round. Deployment experience is exactly what the team lacks.'],
            [439, 58.0, 'HTML, CSS, Bootstrap', 'Partial match - the posting asks for a framework and the CV does not show one.'],
            [440, 34.0, 'MS Office', 'Not a match for an engineering role. No relevant technical experience listed.'],
            [446, 72.5, 'Node.js, Express, MongoDB', 'Good match on stack. Worth an interview to test the depth behind it.'],
            [447, 81.0, 'Java, Spring Boot, Kafka, Kubernetes', 'Strong enterprise background, comfortable at scale.'],
        ];

        $recorded = 0;
        $refused = 0;
        $skipped = 0;

        foreach ($reviews as [$applicationId, $score, $keywords, $comments]) {
            $exists = DB::table('talent_job_applications')
                ->where('sub_institute_id', self::TENANT)
                ->where('id', $applicationId)
                ->exists();

            if (!$exists) {
                $skipped++;
                $this->command->warn("  resume screenings: application {$applicationId} is not in tenant "
                    . self::TENANT . ' on this host, skipped');
                continue;
            }

            $res = $screening->store($this->request($bearer, 'POST', [
                'application_id' => $applicationId,
                'ai_score' => $score,
                'keywords_matched' => $keywords,
                'comments' => $comments,
            ]));

            if ($res->getStatusCode() < 300) {
                $recorded++;
                continue;
            }

            $refused++;
            $body = json_decode($res->getContent(), true);
            $this->command->warn(sprintf(
                '  resume screenings: application %d REFUSED %d - %s',
                $applicationId,
                $res->getStatusCode(),
                $body['message'] ?? 'no message'
            ));
        }

        $this->command->info(sprintf(
            '  resume screenings: %d recorded, %d refused, %d skipped',
            $recorded,
            $refused,
            $skipped
        ));
    }
}
