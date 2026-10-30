<?php

namespace Tests\Unit;

use App\Services\Organization\TenantProvisioner;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * A new organisation's administrator could not sign in: login needs today to fall
 * between academic_year.start_date and end_date, and the provisioner wrote the
 * row with both NULL ("Academic Term Date Expired" on the first login). The term
 * it writes now must always contain the day the organisation is created.
 */
class TenantProvisionerAcademicTermTest extends TestCase
{
    private function covers(array $term, Carbon $now): bool
    {
        // the exact test login runs: "today BETWEEN start_date AND end_date"
        return $term['start_date'] <= $now->toDateString() && $now->toDateString() <= $term['end_date'];
    }

    public function test_the_default_term_is_april_to_march_named_like_every_existing_tenant(): void
    {
        $now = Carbon::parse('2026-10-28 12:09:48');
        $term = TenantProvisioner::academicTerm('2026', $now);

        $this->assertSame('2026-04-01', $term['start_date']);
        $this->assertSame('2027-03-31', $term['end_date']);
        $this->assertSame('Academic Year 2026-2027', $term['title']);
        $this->assertSame('AY 26-27', $term['short_name']);
        $this->assertSame(1, $term['sort_order']);
        $this->assertSame('2026', $term['syear']);
        $this->assertTrue($this->covers($term, $now));
    }

    public function test_it_covers_the_creation_day_on_every_day_of_the_year_with_the_default_syear(): void
    {
        // The default syear is the calendar year, so January-March creations get a term that
        // has not started yet unless the window is stretched back to today.
        for ($day = Carbon::parse('2026-01-01'); $day->lt(Carbon::parse('2028-01-01')); $day = $day->copy()->addDay()) {
            $term = TenantProvisioner::academicTerm((string) $day->year, $day);
            $this->assertTrue($this->covers($term, $day), 'the term must contain ' . $day->toDateString());
        }
    }

    public function test_a_term_that_has_already_ended_is_stretched_to_reach_today(): void
    {
        $now = Carbon::parse('2026-10-28');
        $term = TenantProvisioner::academicTerm('2024', $now);

        $this->assertSame('2024-04-01', $term['start_date']);
        $this->assertSame('2027-10-28', $term['end_date']);
        $this->assertTrue($this->covers($term, $now));
    }

    public function test_a_free_text_syear_with_no_year_falls_back_to_the_year_of_creation(): void
    {
        $now = Carbon::parse('2026-10-28');
        $term = TenantProvisioner::academicTerm('FY-A', $now);

        $this->assertSame('FY-A', $term['syear'], 'the syear itself is stored exactly as given');
        $this->assertSame('Academic Year 2026-2027', $term['title']);
        $this->assertTrue($this->covers($term, $now));
    }

    public function test_provision_writes_the_term_with_its_dates_not_a_bare_syear(): void
    {
        // Static, like CustomProductReadinessTest: the pure function above is worthless if the
        // insert stops using it, and provision() needs a database this suite does not have.
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Organization/TenantProvisioner.php');
        $insert = substr($src, strpos($src, "DB::table('academic_year')->insert("), 400);

        $this->assertStringContainsString('self::academicTerm(', $insert);
        $this->assertStringNotContainsString("'syear' => \$input['syear']", $insert, 'a row with only a syear has NULL dates and fails login');
    }

    public function test_a_leading_year_in_a_longer_label_is_read(): void
    {
        $term = TenantProvisioner::academicTerm('2026-27', Carbon::parse('2026-10-28'));

        $this->assertSame('2026-04-01', $term['start_date']);
        $this->assertSame('AY 26-27', $term['short_name']);
    }
}
