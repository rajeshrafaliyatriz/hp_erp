<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\Workflows\ConditionEvaluator;
use App\Domain\Gtm\Workflows\WorkflowCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkflowLogicTest extends TestCase
{
    // ---------------- conditions ----------------

    #[DataProvider('cases')]
    public function test_condition_operators(string $op, mixed $actual, mixed $expected, bool $result): void
    {
        $this->assertSame($result, ConditionEvaluator::test($op, $actual, $expected), "$op | ".json_encode($actual).' vs '.json_encode($expected));
    }

    public static function cases(): array
    {
        return [
            'eq number strings' => ['eq', '10', 10, true], 'eq case-insensitive' => ['eq', 'Won', 'won', true], 'eq different' => ['eq', 'lost', 'won', false],
            'eq against a missing fact is false' => ['eq', null, 'won', false], 'neq against a missing fact is true' => ['neq', null, 'won', true],
            'neq equal' => ['neq', 'won', 'won', false], 'gt' => ['gt', 15, 10, true], 'gt equal' => ['gt', 10, 10, false], 'gte equal' => ['gte', 10, 10, true],
            'lt' => ['lt', 5, 10, true], 'lte' => ['lte', 10, 10, true], 'gt on text is false, not an error' => ['gt', 'abc', 10, false], 'gt on a missing fact is false' => ['gt', null, 1, false],
            'in' => ['in', 'target', ['target', 'engaged'], true], 'in miss' => ['in', 'customer', ['target', 'engaged'], false], 'in missing fact' => ['in', null, ['a'], false],
            'not_in' => ['not_in', 'customer', ['target', 'engaged'], true], 'not_in hit' => ['not_in', 'target', ['target'], false],
            'contains' => ['contains', 'Education Technology', 'tech', true], 'contains miss' => ['contains', 'Software', 'edu', false], 'contains missing fact' => ['contains', null, 'x', false],
            'empty null' => ['empty', null, null, true], 'empty blank' => ['empty', '', null, true], 'empty value' => ['empty', 'x', null, false], 'empty zero is a value' => ['empty', 0, null, false],
            'not_empty' => ['not_empty', 'x', null, true], 'not_empty null' => ['not_empty', null, null, false],
            'bool true' => ['eq', true, 'true', true], 'bool false vs no' => ['eq', false, 'no', true], 'bool mismatch' => ['eq', true, 'false', false],
            'unknown operator never passes' => ['drop_table', 'x', 'x', false],
        ];
    }

    public function test_all_conditions_must_hold_and_the_trace_explains_each(): void
    {
        $r = ConditionEvaluator::evaluate([
            ['field' => 'deal.stage', 'op' => 'eq', 'value' => 'proposal'],
            ['field' => 'deal.amount', 'op' => 'gte', 'value' => 1000],
        ], ['deal.stage' => 'proposal', 'deal.amount' => 500]);

        $this->assertFalse($r['pass']);
        $this->assertTrue($r['trace'][0]['pass']);
        $this->assertFalse($r['trace'][1]['pass']);
        $this->assertSame(500, $r['trace'][1]['actual']);
    }

    public function test_no_conditions_means_the_workflow_always_applies(): void
    {
        $this->assertTrue(ConditionEvaluator::evaluate([], [])['pass']);
    }

    // ---------------- catalogue ----------------

    public function test_every_trigger_is_a_triggerable_gtm_event_and_every_one_has_facts(): void
    {
        $triggers = WorkflowCatalog::triggers();
        $this->assertSame(GtmEvents::triggerable(), array_keys($triggers));
        foreach ($triggers as $type => $t) {
            $this->assertNotEmpty($t['fields'], "$type exposes at least one fact");
            $this->assertNotEmpty($t['provides'], "$type provides at least one entity");
        }
    }

    public function test_no_event_a_workflow_itself_emits_can_be_a_trigger(): void
    {
        foreach (GtmEvents::all() as $type) {
            if (str_starts_with($type, 'gtm.workflow.')) {
                $this->assertFalse(GtmEvents::TYPES[$type]['triggerable'], "$type must not be triggerable: a workflow reacting to its own runs is a loop");
            }
        }
    }

    public function test_contact_email_and_phone_are_never_available_to_workflows(): void
    {
        foreach (WorkflowCatalog::triggers() as $t) {
            foreach (array_keys($t['fields']) as $f) {
                $this->assertDoesNotMatchRegularExpression('/(^|\.)(email|phone|linkedin_url|notes)$/', $f, "$f would expose personal data");
            }
        }
    }

    public function test_consequential_actions_are_identified(): void
    {
        $this->assertTrue(WorkflowCatalog::hasConsequential([['type' => 'log_activity'], ['type' => 'change_account_stage']]));
        $this->assertFalse(WorkflowCatalog::hasConsequential([['type' => 'log_activity'], ['type' => 'run_agent']]));
    }

    public function test_templates_render_real_facts_and_leave_no_placeholder_behind(): void
    {
        $out = WorkflowCatalog::render('Deal {{deal.name}} ({{deal.amount}} {{deal.currency}}) at {{account.name}} {{nonexistent.fact}}!', ['deal.name' => 'Pilot', 'deal.amount' => 500, 'deal.currency' => 'INR', 'account.name' => 'Acme']);
        $this->assertSame('Deal Pilot (500 INR) at Acme !', $out);
        $this->assertStringNotContainsString('{{', $out);
    }

    // ---------------- validation (no database needed for these) ----------------

    public function test_validation_rejects_what_the_catalogue_does_not_offer(): void
    {
        $bad = WorkflowCatalog::validate(6, 'gtm.deal.deleted', [], [['type' => 'log_activity', 'params' => ['subject' => 'x']]]);
        $this->assertNotEmpty($bad, 'a non-triggerable event cannot start a workflow');

        $e = WorkflowCatalog::validate(6, 'gtm.deal.created', [['field' => 'contact.status', 'op' => 'eq', 'value' => 'active']], [['type' => 'log_activity', 'params' => ['subject' => 'x']]]);
        $this->assertNotEmpty(array_filter($e, fn ($m) => str_contains($m, "'contact.status' is not a fact")), 'a deal trigger does not provide contact facts');

        $e = WorkflowCatalog::validate(6, 'gtm.account.created', [], [['type' => 'set_next_step', 'params' => ['text' => 'x']]]);
        $this->assertNotEmpty(array_filter($e, fn ($m) => str_contains($m, 'needs a deal')), 'an account trigger has no deal to set a next step on');

        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [], []), 'at least one action is required');
        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [['field' => 'deal.amount', 'op' => 'gt', 'value' => 'lots']], [['type' => 'log_activity', 'params' => ['subject' => 'x']]]));
        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [], [['type' => 'log_activity', 'params' => ['subject' => '{{deal.secret}}']]]), 'an unknown placeholder is refused at save time');
        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [], [['type' => 'run_agent', 'params' => ['agent' => 'gtm-nonsense']]]));
        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [], [['type' => 'run_agent', 'params' => ['agent' => 'gtm-deal-coach', 'input' => ['sql' => '1']]]]));
        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [], [['type' => 'change_account_stage', 'params' => ['stage' => 'king']]]));
        $this->assertNotEmpty(WorkflowCatalog::validate(6, 'gtm.deal.created', [], [['type' => 'exfiltrate', 'params' => []]]));
    }

    public function test_a_sound_definition_validates(): void
    {
        $this->assertSame([], WorkflowCatalog::validate(6, 'gtm.deal.stage_changed',
            [['field' => 'event.to', 'op' => 'eq', 'value' => 'won'], ['field' => 'deal.amount', 'op' => 'gte', 'value' => 1000]],
            [['type' => 'run_agent', 'params' => ['agent' => 'gtm-deal-coach', 'input' => ['deal_id' => '@deal.id']]], ['type' => 'log_activity', 'params' => ['subject' => 'Won: {{deal.name}}']], ['type' => 'change_account_stage', 'params' => ['stage' => 'customer']]]));
    }
}
