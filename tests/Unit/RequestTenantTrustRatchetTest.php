<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A ratchet on trusting a client-supplied organisation.
 *
 * The API's rule is "the token decides" (App\Http\Controllers\Api\Concerns\ResolvesApiIdentity::apiTenantId()). A file
 * that reads `sub_institute_id` straight off the request is therefore either (a) one of the legacy controllers still
 * being worked through the security queue (docs/phase3/13-current-state.md), (b) already fixed but still mentioning the
 * field (validation rules, comments), or (c) a new regression.
 *
 * BASELINE below is every file that matched when this test was written. It is a REVIEW LIST, not a list of confirmed
 * vulnerabilities - several entries are fixed and match only on a harmless reference. The test fails on (c): a file
 * NOT on the list that reads the request's tenant. When a file is fixed or deleted it should be removed from the list;
 * the test also fails if the list names a file that no longer matches, so the list can only shrink.
 */
class RequestTenantTrustRatchetTest extends TestCase
{
    private const PATTERN = '/request->(get|input|query|post)\((.)sub_institute_id|request->sub_institute_id|\$request\[.sub_institute_id/';

    private const BASELINE = [
        'app/Http/Controllers/AJAXController.php',
        'app/Http/Controllers/Api/Concerns/ResolvesApiIdentity.php',
        'app/Http/Controllers/Api/ExcelAutomationAgentController.php',
        'app/Http/Controllers/Api/TaskManagement/Concerns/ResolvesTaskContext.php',
        'app/Http/Controllers/Api/TaskManagement/WorkspaceController.php',
        'app/Http/Controllers/Api/jobrolecontroller.php',
        'app/Http/Controllers/HRMS/HrmsLeaveController.php',
        'app/Http/Controllers/HRTemplates/TemplateController.php',
        'app/Http/Controllers/Payroll/PayrollController.php',
        'app/Http/Controllers/Reports/EmployeeSkillCoverageMatrix/EmployeeSkillCoverageMatrixController.php',
        'app/Http/Controllers/build_with_AI/buildwithAIController.php',
        'app/Http/Controllers/courseRecommandation.php',
        'app/Http/Controllers/dashboards/SkillDashboardController.php',
        'app/Http/Controllers/dashboards/orgDashboardContorller.php',
        'app/Http/Controllers/front_desk/syllabus/syllabusController.php',
        'app/Http/Controllers/leave/LeaveAuthorisationController.php',
        'app/Http/Controllers/leave/leaveEncashmentController.php',
        'app/Http/Controllers/leave/leave_report/LeaveReportController.php',
        'app/Http/Controllers/libraries/SkillMatrixController.php',
        'app/Http/Controllers/libraries/jobroleLibrary1Controller.php',
        'app/Http/Controllers/libraries/jobroleLibraryController.php',
        'app/Http/Controllers/libraries/jobroleskillcontroller.php',
        'app/Http/Controllers/libraries/skillLibraryController.php',
        'app/Http/Controllers/lms/assignment/assignmentController.php',
        'app/Http/Controllers/lms/blukCourseandQuetionGeneration.php',
        'app/Http/Controllers/lms/chapterController.php',
        'app/Http/Controllers/lms/contentController.php',
        'app/Http/Controllers/lms/content_library/contentLibraryController.php',
        'app/Http/Controllers/lms/content_library/contentLibraryControllerOld.php',
        'app/Http/Controllers/lms/curriculum/curriculumLessonplanController.php',
        'app/Http/Controllers/lms/library/jobOccupationController.php',
        'app/Http/Controllers/lms/library/jobroleSkillController.php',
        'app/Http/Controllers/lms/library/jobroleTaskController.php',
        'app/Http/Controllers/lms/lmsActivityStreamController.php',
        'app/Http/Controllers/lms/lmsCurriculumController.php',
        'app/Http/Controllers/lms/lmsDashboardController.php',
        'app/Http/Controllers/lms/lmsPortfolioController.php',
        'app/Http/Controllers/lms/lmsSyllabusController.php',
        'app/Http/Controllers/lms/lms_apiController.php',
        'app/Http/Controllers/lms/onlineExamController.php',
        'app/Http/Controllers/lms/questionpaperController.php',
        'app/Http/Controllers/lms/virtualclassroomController.php',
        'app/Http/Controllers/school_setup/masterSetupController.php',
        'app/Http/Controllers/settings/instituteDetailController.php',
        'app/Http/Controllers/user/tblgroupwise_rightsController.php',
        'app/Http/Controllers/user/tbluserController.php',
        'app/Http/Controllers/user/userReportController.php',
        'app/Services/Ai/AiScopeResolver.php',
    ];

    #[Test]
    public function no_new_file_reads_the_organisation_from_the_request(): void
    {
        $unexpected = array_values(array_diff($this->matchingFiles(), self::BASELINE));

        $this->assertSame([], $unexpected, "These files read sub_institute_id from the request. Use apiTenantId() "
            . "(token-derived) instead, or - if the read is genuinely safe - add the file to BASELINE with a reason:\n"
            . implode("\n", $unexpected));
    }

    #[Test]
    public function the_baseline_only_shrinks(): void
    {
        $stale = array_values(array_diff(self::BASELINE, $this->matchingFiles()));

        $this->assertSame([], $stale, "These BASELINE entries no longer match (fixed or deleted) - remove them:\n"
            . implode("\n", $stale));
    }

    /** @return list<string> app-relative, forward-slashed, sorted */
    private function matchingFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $matches = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'app'));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match(self::PATTERN, (string) file_get_contents($file->getPathname())) === 1) {
                $matches[] = str_replace('\\', '/',substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($matches);

        return $matches;
    }
}
