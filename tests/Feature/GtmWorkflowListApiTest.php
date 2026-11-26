<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowWorld;
use Tests\TestCase;

/**
 * GET /api/gtm/workflows grew three fields per item (26 Nov 2026; Docs/agentic-crm/briefs/WORKFLOWS-MERGE.md): `sentence` (the workflow as one plain sentence), `needs_approval`
 * and `last_run` ({status, at, done_7d, failed_7d, waiting}, or null for a workflow that never ran). Every key the list already had keeps its name and shape, and the last_run
 * numbers come from ONE aggregate query over the runs, so the number of queries does not grow with the number of workflows.
 */
class GtmWorkflowListApiTest extends TestCase
{
    use BuildsWorkflowWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowWorld();
    }

    /** @return array<int, array<string, mixed>> the list items by id */
    private function listed(array $h): array
    {
        $items = $this->getJson('/api/gtm/workflows', $h)->assertOk()->json('data.items');

        return array_column($items, null, 'id');
    }

    public function test_the_keys_the_list_already_had_keep_their_names_and_shapes(): void
    {
        $admin = $this->person('administrator');
        $id = $this->makeWorkflow(self::T, ['name' => 'Notes', 'description' => 'why', 'conditions' => json_encode([['field' => 'lead.score', 'op' => 'gte', 'value' => 50]])]);
        $this->makeRun($id, 'succeeded');

        $item = $this->listed($admin['h'])[$id];

        foreach (['id', 'sub_institute_id', 'name', 'description', 'trigger_event', 'conditions', 'actions', 'status', 'max_runs_per_day', 'created_at', 'trigger_label', 'runs_7d'] as $key) {
            $this->assertArrayHasKey($key, $item, $key);
        }
        $this->assertSame([['field' => 'lead.score', 'op' => 'gte', 'value' => 50]], $item['conditions'], 'conditions are decoded, as before');
        $this->assertSame('add_lead_note', $item['actions'][0]['type']);
        $this->assertSame('CRM lead created (signal promotion, manual, import or web form)', $item['trigger_label']);
        $this->assertSame(['succeeded' => 1], $item['runs_7d']);
    }

    public function test_a_workflow_that_never_ran_has_an_empty_runs_7d_and_no_last_run(): void
    {
        $admin = $this->person('administrator');
        $id = $this->makeWorkflow();

        $raw = $this->getJson('/api/gtm/workflows', $admin['h'])->assertOk()->getContent();
        $item = $this->listed($admin['h'])[$id];

        $this->assertSame([], $item['runs_7d'], 'PHP sends [] when there are none, as before');
        $this->assertNull($item['last_run']);
        $this->assertStringContainsString('"runs_7d":[]', $raw);
        $this->assertStringContainsString('"last_run":null', $raw);
    }

    public function test_the_sentence_and_needs_approval(): void
    {
        $admin = $this->person('administrator');
        $plain = $this->makeWorkflow(self::T, ['conditions' => json_encode([['field' => 'lead.score', 'op' => 'gte', 'value' => 50]])]);
        $required = $this->makeWorkflow(self::T, ['requires_approval' => 1]);
        $consequential = $this->makeWorkflow(self::T, ['trigger_event' => 'crm.lead.qualified', 'actions' => json_encode([['type' => 'convert_lead', 'params' => ['create_opportunity' => true]]])]);

        $items = $this->listed($admin['h']);

        $this->assertFalse($items[$plain]['needs_approval']);
        $this->assertSame('When a lead is created and "Lead score" is at least 50, add a note to the lead', $items[$plain]['sentence']);
        $this->assertTrue($items[$required]['needs_approval'], 'the workflow says its runs wait');
        $this->assertStringEndsWith('; needs your approval', $items[$required]['sentence']);
        $this->assertTrue($items[$consequential]['needs_approval'], 'an action that is consequential forces approval');
        $this->assertSame('When a lead becomes Qualified, convert the lead (Organization + Contact + Opportunity); needs your approval', $items[$consequential]['sentence']);
    }

    public function test_last_run_is_the_newest_run_with_the_counts_of_the_last_seven_days(): void
    {
        $admin = $this->person('administrator');
        $id = $this->makeWorkflow();
        $other = $this->makeWorkflow();
        $old = now()->subDays(10);
        $this->makeRun($id, 'succeeded', ['created_at' => $old, 'updated_at' => $old]);          // older than a week: not in done_7d
        $this->makeRun($id, 'succeeded', ['created_at' => now()->subDays(2)]);
        $this->makeRun($id, 'succeeded', ['created_at' => now()->subDays(3)]);
        $this->makeRun($id, 'failed', ['created_at' => now()->subDays(1)]);
        $this->makeRun($id, 'pending_approval', ['created_at' => $old, 'updated_at' => $old]);   // waiting counts however old it is
        $this->makeRun($id, 'pending_approval', ['created_at' => now()->subHours(5)]);
        $last = $this->makeRun($id, 'skipped', ['created_at' => now()->subMinutes(30)]);          // the newest by id
        $this->makeRun($other, 'cancelled', ['created_at' => now()->subHours(2)]);

        $items = $this->listed($admin['h']);
        $lastRun = $items[$id]['last_run'];

        $this->assertSame(['status', 'at', 'done_7d', 'failed_7d', 'waiting'], array_keys($lastRun));
        $this->assertSame('skipped', $lastRun['status']);
        $this->assertSame(2, $lastRun['done_7d']);
        $this->assertSame(1, $lastRun['failed_7d']);
        $this->assertSame(2, $lastRun['waiting']);
        $this->assertEqualsWithDelta(now()->subMinutes(30)->getTimestamp(), strtotime($lastRun['at']), 5, 'the time of that run, as an unambiguous timestamp');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $lastRun['at']);
        $this->assertEqualsCanonicalizing(['succeeded' => 2, 'failed' => 1, 'pending_approval' => 1, 'skipped' => 1], $items[$id]['runs_7d'], 'runs_7d is unchanged: the last seven days by status');
        $this->assertSame(['status' => 'cancelled', 'done_7d' => 0, 'failed_7d' => 0, 'waiting' => 0], array_intersect_key($items[$other]['last_run'], array_flip(['status', 'done_7d', 'failed_7d', 'waiting'])));
        $this->assertGreaterThan(0, $last);
    }

    public function test_another_organisations_runs_are_never_counted(): void
    {
        $admin = $this->person('administrator');
        $id = $this->makeWorkflow();
        $this->makeRun($id, 'failed', ['sub_institute_id' => self::T2]);
        $this->makeRun($id, 'pending_approval', ['sub_institute_id' => self::T2]);

        $this->assertNull($this->listed($admin['h'])[$id]['last_run']);
        $mine = $this->makeWorkflow(self::T2);
        $theirs = $this->person('administrator', [], self::T2);
        $this->assertArrayNotHasKey($id, $this->listed($theirs['h']), "the other organisation does not see this organisation's workflow");
        $this->assertArrayHasKey($mine, $this->listed($theirs['h']));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_workflows(): void
    {
        $admin = $this->person('administrator');
        $count = function () use ($admin): array {
            $queries = [];
            DB::listen(function ($q) use (&$queries) {
                $queries[] = $q->sql;
            });
            $this->getJson('/api/gtm/workflows', $admin['h'])->assertOk();

            return $queries;
        };

        foreach (range(1, 3) as $i) {
            $w = $this->makeWorkflow();
            $this->makeRun($w, 'succeeded');
        }
        $count(); // the first request of a token also stamps its last_used_at: not what is measured
        $few = $count();
        foreach (range(1, 40) as $i) {
            $w = $this->makeWorkflow();
            $this->makeRun($w, $i % 2 ? 'failed' : 'pending_approval');
            $this->makeRun($w, 'succeeded');
        }
        $many = $count();

        $this->assertCount(count($few), $many, 'the same number of queries for 3 workflows and for 43');
        $this->assertCount(1, array_filter($many, fn ($sql) => str_contains($sql, 'gtm_workflow_runs')), 'one aggregate query over the runs');
    }

    public function test_the_detail_and_the_answers_that_return_a_workflow_carry_the_sentence_too(): void
    {
        $admin = $this->person('administrator');
        $id = $this->makeWorkflow(self::T, ['trigger_event' => 'crm.lead.qualified', 'actions' => json_encode([['type' => 'convert_lead', 'params' => (object) []]])]);

        $show = $this->getJson("/api/gtm/workflows/{$id}", $admin['h'])->assertOk();
        $this->assertSame('When a lead becomes Qualified, convert the lead (Organization + Contact + Opportunity); needs your approval', $show->json('data.workflow.sentence'));
        $this->assertTrue($show->json('data.workflow.needs_approval'));

        $created = $this->postJson('/api/gtm/workflows', ['name' => 'Mine', 'trigger_event' => 'crm.lead.created', 'actions' => [['type' => 'add_lead_note', 'params' => ['text' => 'hi']]]], $admin['h'])->assertStatus(201);
        $this->assertSame('When a lead is created, add a note to the lead', $created->json('data.workflow.sentence'));
        $this->assertFalse($created->json('data.workflow.needs_approval'));

        $paused = $this->postJson('/api/gtm/workflows/'.$created->json('data.workflow.id').'/status', ['status' => 'active'], $admin['h'])->assertOk();
        $this->assertSame('When a lead is created, add a note to the lead', $paused->json('data.workflow.sentence'));
    }
}
