<?php

namespace Tests\Unit;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use Tests\TestCase;

/** A bare "3 days ago" is a posting age only on a SINGLE job post, never on a listing, search or review page. Real URLs from a live run. */
class JobPostDateRuleTest extends TestCase
{
    private function single(string $url, string $title): bool
    {
        $m = new \ReflectionMethod(OpportunityResearcher::class, 'isJobPost');
        $m->setAccessible(true);

        return (bool) $m->invoke($this->app->make(OpportunityResearcher::class), $url, $title);
    }

    public function test_single_posts_count(): void
    {
        $this->assertTrue($this->single('https://www.shine.com/jobs/teachers/tapovan-vidya-vihar/19596987', 'Teachers - Tapovan Vidya Vihar'));
        $this->assertTrue($this->single('https://www.linkedin.com/jobs/view/academic-director-4346009259', 'Academic Director - Some School'));
        $this->assertTrue($this->single('https://www.naukri.com/job-listings-erp-administrator-podar-vadodara-2-to-5-years-170426018789', 'ERP Administrator - Podar'));
    }

    public function test_listing_search_and_review_pages_do_not(): void
    {
        $this->assertFalse($this->single('https://www.shine.com/job-search/academic-coordinator-jobs-in-surat', 'Academic Coordinator Jobs in Surat - 1 Job Vacancies'));
        $this->assertFalse($this->single('https://in.indeed.com/q-female-vacancy-l-sachin,-surat,-gujarat-jobs.html', '25 Female Vacancy Job Vacancies in Sachin, Surat | Indeed'));
        $this->assertFalse($this->single('https://www.shine.com/jobs/international-sales-coordinator/eazy-erp-technologies/19419151', 'Coordinator Jobs in Hyderabad - 333 Coordinator Job Vacancies in Hyderabad'));
        $this->assertFalse($this->single('https://indeed.com/cmp/Wyld/reviews', 'Working at WYLD: 197 Reviews | Indeed.com'));
        $this->assertFalse($this->single('https://news.example.com/story', 'Some story'), 'only job sites');
    }
}
