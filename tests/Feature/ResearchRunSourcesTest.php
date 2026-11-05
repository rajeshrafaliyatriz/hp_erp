<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\ResearchSource;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResearchRunSourcesTest extends TestCase
{
    private const T = 1;
    private const T_OTHER = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
        });

        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_profile_id');
        });

        Schema::create('g2g_research_runs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->date('report_date')->nullable();
            $t->string('product_key', 64)->nullable();
            $t->string('trigger', 20)->default('manual');
            $t->string('status', 20)->default('success');
            $t->string('stage', 50)->nullable();
            $t->text('stage_message')->nullable();
            $t->unsignedInteger('queries_run')->default(0);
            $t->unsignedInteger('queries_failed')->default(0);
            $t->unsignedInteger('sources_found')->default(0);
            $t->unsignedInteger('companies_researched')->default(0);
            $t->unsignedInteger('opportunities_qualified')->default(0);
            $t->unsignedInteger('opportunities_new')->default(0);
            $t->unsignedInteger('opportunities_rejected')->default(0);
            $t->longText('stage_stats')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('g2g_research_sources', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('research_run_id');
            $t->string('url', 2000);
            $t->string('title', 500);
            $t->string('domain', 191)->nullable();
            $t->text('snippet')->nullable();
            $t->date('published_at')->nullable();
            $t->timestamp('retrieved_at')->useCurrent();
            $t->boolean('page_fetched')->default(false);
            $t->boolean('cited')->default(false);
            $t->timestamps();
        });

        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
    }

    private function h(int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    public function test_run_sources_returns_404_for_unknown_or_cross_tenant_run(): void
    {
        $run = ResearchRun::create([
            'sub_institute_id' => self::T,
            'report_date' => '2026-11-04',
            'product_key' => 'scholarclone',
            'status' => 'success',
        ]);

        $this->getJson('/api/signals/research/runs/9999/sources', $this->h())->assertStatus(404);
        $this->getJson("/api/signals/research/runs/{$run->id}/sources", $this->h(self::T_OTHER))->assertStatus(404);
    }

    public function test_run_sources_returns_enriched_exploration_details_and_metrics(): void
    {
        $stageStats = [
            'queries' => [
                'attempted' => 14,
                'succeeded' => 14,
                'with_results' => 13,
                'empty' => 1,
                'failed' => 0,
            ],
            'results' => [
                'returned' => 77,
                'dropped_too_old' => 16,
                'screened_out' => 0,
                'kept' => 50,
            ],
            'pages' => [
                'fetched' => 14,
                'failed' => 10,
            ],
            'registry' => [
                'selected' => 2,
                'searched' => 2,
            ],
            'zero_reasons' => [
                ['code' => 'no_candidate_proposed', 'label' => 'The pages were read, but none showed a school with a clear reason to buy.', 'count' => 44],
                ['code' => 'results_too_old', 'label' => 'Everything found was older than the freshness window.', 'count' => 16],
                ['code' => 'pages_unreadable', 'label' => 'The pages could not be read (blocked, login wall or error).', 'count' => 10],
            ],
            'next_action' => 'Add direct sources or school websites.',
        ];

        $run = ResearchRun::create([
            'sub_institute_id' => self::T,
            'report_date' => '2026-11-04',
            'product_key' => 'scholarclone',
            'status' => 'success',
            'queries_run' => 14,
            'queries_failed' => 0,
            'sources_found' => 3,
            'opportunities_qualified' => 1,
            'opportunities_new' => 1,
            'opportunities_rejected' => 2,
            'stage_stats' => $stageStats,
        ]);

        // 1. Qualified source
        ResearchSource::create([
            'sub_institute_id' => self::T,
            'research_run_id' => $run->id,
            'url' => 'https://cbse.gov.in/tenders/tender-erp-2026.pdf',
            'title' => 'CBSE Affiliation ERP Procurement Notice',
            'domain' => 'cbse.gov.in',
            'snippet' => 'CBSE invites RFP for institutional automation software system for affiliated schools.',
            'page_fetched' => true,
            'cited' => true,
        ]);

        // 2. Read in full, but not qualified
        ResearchSource::create([
            'sub_institute_id' => self::T,
            'research_run_id' => $run->id,
            'url' => 'https://podareducation.org/admissions/ahmedabad',
            'title' => 'Podar International School Ahmedabad Admissions',
            'domain' => 'podareducation.org',
            'snippet' => 'Admissions open for academic year 2027. State-of-the-art campus facilities.',
            'page_fetched' => true,
            'cited' => false,
        ]);

        // 3. Discovered only (search excerpt)
        ResearchSource::create([
            'sub_institute_id' => self::T,
            'research_run_id' => $run->id,
            'url' => 'https://timesofindia.indiatimes.com/education/gujarat-schools-upgrade',
            'title' => 'Gujarat Schools Upgrade Infrastructure',
            'domain' => 'timesofindia.indiatimes.com',
            'snippet' => 'Private schools across Gujarat announce modern tech upgrades for upcoming session.',
            'page_fetched' => false,
            'cited' => false,
        ]);

        $res = $this->getJson("/api/signals/research/runs/{$run->id}/sources", $this->h());
        $res->assertOk();

        $res->assertJsonPath('status', 1);
        $res->assertJsonCount(3, 'data');

        // Verify metrics
        $res->assertJsonPath('metrics.sources_found', 3);
        $res->assertJsonPath('metrics.queries_run', 14);
        $res->assertJsonPath('metrics.queries_attempted', 14);
        $res->assertJsonPath('metrics.queries_with_results', 13);
        $res->assertJsonPath('metrics.queries_empty', 1);
        $res->assertJsonPath('metrics.results_returned', 77);
        $res->assertJsonPath('metrics.pages_read_in_full', 14);
        $res->assertJsonPath('metrics.registry_searched', 2);
        $res->assertJsonPath('metrics.registry_selected', 2);
        $res->assertJsonPath('metrics.opportunities_qualified', 1);

        // Verify zero_reasons
        $res->assertJsonCount(3, 'zero_reasons');
        $res->assertJsonPath('zero_reasons.0.code', 'no_candidate_proposed');
        $res->assertJsonPath('zero_reasons.0.count', 44);

        // Verify source 1 (Qualified)
        $data = $res->json('data');
        $s1 = collect($data)->firstWhere('url', 'https://cbse.gov.in/tenders/tender-erp-2026.pdf');
        $this->assertNotNull($s1);
        $this->assertSame('Government Registry', $s1['source_type']);
        $this->assertSame('Qualified', $s1['exploration_status']);
        $this->assertSame('Qualified', $s1['qualification_result']);
        $this->assertNull($s1['rejection_reason']);
        $this->assertSame(1, $s1['pages_searched']);

        // Verify source 2 (Read in full, not qualified)
        $s2 = collect($data)->firstWhere('url', 'https://podareducation.org/admissions/ahmedabad');
        $this->assertNotNull($s2);
        $this->assertSame('School Website', $s2['source_type']);
        $this->assertSame('Read', $s2['exploration_status']);
        $this->assertSame('Not qualified', $s2['qualification_result']);
        $this->assertStringContainsString('Page read in full', $s2['rejection_reason']);
        $this->assertSame(1, $s2['pages_searched']);

        // Verify source 3 (Discovered snippet only)
        $s3 = collect($data)->firstWhere('url', 'https://timesofindia.indiatimes.com/education/gujarat-schools-upgrade');
        $this->assertNotNull($s3);
        $this->assertSame('News Media', $s3['source_type']);
        $this->assertSame('Discovered', $s3['exploration_status']);
        $this->assertSame('Not qualified', $s3['qualification_result']);
        $this->assertStringContainsString('Discovered as search result', $s3['rejection_reason']);
        $this->assertSame(0, $s3['pages_searched']);
    }
}
