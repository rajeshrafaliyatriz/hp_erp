<?php

namespace Tests\Feature;

use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * Slice 11b: the opportunity screens learn the allowed loss-reason codes from the answer they already read (GET /api/crm/opportunities/pipeline, the kanban's
 * own read), as `data.lossReasons: [{code, label}]`. Additive: `data.stages` keeps its shape. The list is the config's, in the config's order, and it is
 * exactly the set of codes the write side accepts.
 */
class OpportunityOptionsTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    private const CODES = ['price', 'no_budget', 'no_decision', 'competitor', 'timing', 'no_response', 'other'];

    /** @var array{id: int, h: array<string, string>} */
    private array $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
        $this->caller = $this->opportunitiesUser();
    }

    private function pipeline(?array $caller = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/crm/opportunities/pipeline', ($caller ?? $this->caller)['h']);
    }

    public function test_the_pipeline_answer_lists_the_loss_reasons_as_code_and_label_in_the_configs_order(): void
    {
        $reasons = $this->pipeline()->assertOk()->json('data.lossReasons');

        $this->assertSame(self::CODES, array_column($reasons, 'code'));
        $this->assertSame(['Price too high', 'No budget', 'No decision made', 'Chose a competitor', 'Timing was wrong', 'Stopped responding', 'Other'], array_column($reasons, 'label'));
        foreach ($reasons as $reason) {
            $this->assertSame(['code', 'label'], array_keys($reason), 'each entry is exactly {code, label}');
        }
        $this->assertTrue(array_is_list($reasons), 'a list, not a map keyed by code');
    }

    public function test_the_stages_keep_their_shape_and_their_order(): void
    {
        $res = $this->pipeline()->assertOk();

        $this->assertSame(['stages', 'lossReasons'], array_keys($res->json('data')), 'the stages stay first; the reasons are added after them');
        $this->assertSame($this->salesStages, array_column($res->json('data.stages'), 'stage'));
        $this->assertSame(['stage', 'label', 'color', 'count', 'totalAmount'], array_keys($res->json('data.stages.0')));
    }

    public function test_the_counts_still_count_only_the_callers_organisation(): void
    {
        $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting']);
        $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting']);
        $this->makeOpportunity(self::T2, ['sales_stage' => 'Prospecting']);

        $stages = collect($this->pipeline()->assertOk()->json('data.stages'))->keyBy('stage');

        $this->assertSame(2, $stages['Prospecting']['count']);
    }

    public function test_a_reworded_label_and_a_removed_code_follow_the_config(): void
    {
        config(['crm_sales.loss_reasons' => ['price' => 'Too dear', 'timing' => 'Not this year']]);

        $this->assertSame(
            [['code' => 'price', 'label' => 'Too dear'], ['code' => 'timing', 'label' => 'Not this year']],
            $this->pipeline()->assertOk()->json('data.lossReasons'),
        );
    }

    public function test_an_empty_list_in_the_config_is_an_empty_list_in_the_answer_not_an_object(): void
    {
        config(['crm_sales.loss_reasons' => []]);

        $res = $this->pipeline()->assertOk();

        $this->assertSame([], $res->json('data.lossReasons'));
        $this->assertStringContainsString('"lossReasons":[]', $res->getContent());
    }

    public function test_the_listed_codes_are_exactly_the_codes_the_stage_move_accepts(): void
    {
        $listed = array_column($this->pipeline()->assertOk()->json('data.lossReasons'), 'code');

        foreach ($listed as $code) {
            $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);
            $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'lossReasonCode' => $code], $this->caller['h'])
                ->assertOk()->assertJsonPath('data.loss_reason.code', $code);
        }

        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Closed Lost', 'lossReasonCode' => 'not_in_the_list'], $this->caller['h'])->assertStatus(422);
    }

    public function test_the_answer_is_for_people_with_the_opportunities_view_right_only(): void
    {
        $nothing = $this->opportunitiesUser(self::T, []);

        $res = $this->pipeline($nothing);

        $this->assertContains($res->getStatusCode(), [401, 403]);
        $this->assertStringNotContainsString('lossReasons', $res->getContent());
        $this->assertStringNotContainsString('Price too high', $res->getContent());
    }
}
