<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Gtm\DealController;
use App\Models\auth\tbluserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Converting a GTM deal to a CRM opportunity records the opportunity's FIRST stage-history row at the stage and probability the opportunity was
 * actually created with: a won deal as "Closed Won" at 100, but a lost one as "Closed Lost" at 0 and an open one at its own stage and probability.
 * (The row used to say "Closed Won" at 100 for every deal.) The real controller method is called with a real token; in-memory sqlite only.
 */
class GtmDealConversionTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

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
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        foreach ([
            '2026_10_10_100000_create_gtm_foundation_tables', '2026_10_10_400000_create_gtm_deals_tables', '2026_11_20_090100_create_crm_organizations_table', '2026_11_20_090400_create_crm_campaigns_table',
            '2026_11_24_080300_create_crm_opportunities_table', '2026_11_24_080600_create_crm_opportunity_stage_history_table',
            '2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
    }

    private function token(int $tenant = self::T): string
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return tbluserModel::find($id)->createToken('t')->plainTextToken;
    }

    private function deal(string $stage, int $tenant = self::T, ?string $amount = '5000.00'): int
    {
        $account = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => 'Acme ' . uniqid(), 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('gtm_deals')->insertGetId(['sub_institute_id' => $tenant, 'account_id' => $account, 'name' => "Deal at {$stage}", 'stage' => $stage,
            'amount' => $amount, 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function convert(int $dealId, ?string $token = null): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/gtm/deals/' . $dealId . '/convert-to-crm', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . ($token ?? $this->token()));

        return app(DealController::class)->convertToCrm($request, $dealId);
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> deal stage => [CRM stage, ..., probability] */
    public static function stages(): array
    {
        return [
            'won' => ['won', 'Closed Won', 100],
            'lost' => ['lost', 'Closed Lost', 0],
            'negotiation' => ['negotiation', 'Negotiation/Review', 80],
            'proposal' => ['proposal', 'Proposal/Price Quote', 60],
            'qualification' => ['qualification', 'Qualification', 40],
            'discovery (anything else)' => ['discovery', 'Needs Analysis', 20],
        ];
    }

    /** @dataProvider stages */
    public function test_the_first_history_row_records_the_stage_and_probability_the_opportunity_was_created_with(string $dealStage, string $crmStage, int $probability): void
    {
        $response = $this->convert($this->deal($dealStage));

        $this->assertSame(201, $response->getStatusCode());
        $opportunityId = (int) $response->getData(true)['data']['crm_opportunity_id'];
        $opportunity = DB::table('crm_opportunities')->find($opportunityId);
        $this->assertSame([$crmStage, $probability], [$opportunity->sales_stage, (int) $opportunity->probability], 'the opportunity itself');

        $history = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $opportunityId)->get();
        $this->assertCount(1, $history);
        $this->assertNull($history[0]->from_stage, 'it is the first entry');
        $this->assertSame($crmStage, $history[0]->to_stage, 'the history says the stage the opportunity really has');
        $this->assertSame($probability, (int) $history[0]->probability, 'and its real probability');
        $this->assertSame($opportunity->sales_stage, $history[0]->to_stage);
        $this->assertEquals($opportunity->amount, $history[0]->amount);
    }

    public function test_converting_twice_creates_one_opportunity_and_one_history_row(): void
    {
        $deal = $this->deal('proposal');
        $token = $this->token();
        $first = $this->convert($deal, $token);
        $second = $this->convert($deal, $token);

        $this->assertSame([201, 200], [$first->getStatusCode(), $second->getStatusCode()]);
        $this->assertFalse($second->getData(true)['data']['created']);
        $this->assertSame(1, DB::table('crm_opportunities')->where('converted_from_gtm_deal_id', $deal)->count());
        $this->assertSame(1, DB::table('crm_opportunity_stage_history')->count());
    }

    public function test_another_organisations_deal_is_not_found_and_nothing_is_created(): void
    {
        $theirs = $this->deal('won', self::T2);
        $response = $this->convert($theirs, $this->token(self::T));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, DB::table('crm_opportunities')->count());
        $this->assertSame(0, DB::table('crm_opportunity_stage_history')->count());
    }

    public function test_the_controller_file_has_no_leftover_of_the_old_insert(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Api/Gtm/DealController.php'));

        $this->assertStringNotContainsString('on win."', $src, 'the old description line is gone');
        $this->assertSame(1, substr_count($src, "'converted_from_gtm_deal_id' => \$deal->id,"), 'one insert, not two');
        $this->assertStringNotContainsString("'to_stage' => 'Closed Won'", $src, 'the history stage is not hard-coded');
    }
}
