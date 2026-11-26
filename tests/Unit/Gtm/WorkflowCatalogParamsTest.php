<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\Workflows\WorkflowCatalog;
use Tests\TestCase;

/**
 * The typed parameters of every catalogue action (WorkflowCatalog::paramFields -> `param_fields`) and the plain wording of every trigger (`phrase`), kept in step with the
 * `params` sentences the catalogue always had and with the real validation, so a screen can build its form from them instead of guessing a type from words.
 */
class WorkflowCatalogParamsTest extends TestCase
{
    private const TYPES = ['string', 'text', 'number', 'bool', 'enum', 'user'];

    public function test_every_action_lists_its_parameters_as_typed_fields(): void
    {
        $actions = WorkflowCatalog::actions();

        $this->assertCount(24, $actions);
        foreach ($actions as $type => $def) {
            $this->assertIsArray($def['param_fields'], $type);
            foreach ($def['param_fields'] as $f) {
                $this->assertContains(array_keys($f), [['key', 'label', 'type', 'required'], ['key', 'label', 'type', 'required', 'options'], ['key', 'label', 'type', 'required', 'format'], ['key', 'label', 'type', 'required', 'options', 'format']], "{$type}.{$f['key']}");
                $this->assertContains($f['type'], self::TYPES, "{$type}.{$f['key']}");
                $this->assertIsBool($f['required']);
                $this->assertNotSame('', trim($f['label']));
                $this->assertSame($f['type'] === 'enum', isset($f['options']), "{$type}.{$f['key']}: exactly an enum has options");
                if ($f['type'] === 'enum') {
                    $this->assertNotEmpty($f['options']);
                }
            }
        }
    }

    public function test_the_params_map_every_older_screen_reads_is_untouched_and_agrees_with_the_typed_fields(): void
    {
        foreach (WorkflowCatalog::actions() as $type => $def) {
            $this->assertSame(array_keys($def['params']), array_column($def['param_fields'], 'key'), "{$type}: the same parameters, in the same order, in the map and in the typed list");
            $this->assertIsArray($def['params'], 'still the map of name => sentence');
            foreach ($def['param_fields'] as $f) {
                $sentence = $def['params'][$f['key']];
                $this->assertIsString($sentence);
                if (str_contains($sentence, 'required') && ! str_contains($sentence, 'optional')) {
                    $this->assertTrue($f['required'], "{$type}.{$f['key']} is 'required' in its sentence");
                }
                if (str_contains($sentence, 'optional')) {
                    $this->assertFalse($f['required'], "{$type}.{$f['key']} is 'optional' in its sentence");
                }
                if (str_starts_with($sentence, 'whole number')) {
                    $this->assertContains($f['type'], ['number', 'user'], "{$type}.{$f['key']}: a whole number, or the id of a user");
                }
                if ($sentence === 'true | false' || str_starts_with($sentence, 'true | false')) {
                    $this->assertSame('bool', $f['type'], "{$type}.{$f['key']}");
                }
            }
        }
        // older callers read these exact entries
        $this->assertArrayHasKey('intervals', WorkflowCatalog::actions()['start_lead_followup']['params']);
        $this->assertArrayHasKey('timezone', WorkflowCatalog::actions()['start_lead_followup']['params']);
        $this->assertArrayHasKey('assign_to_user_id', WorkflowCatalog::actions()['create_support_onboarding']['params']);
    }

    public function test_the_types_are_the_ones_the_server_will_accept(): void
    {
        $by = fn (string $action, string $key) => array_values(array_filter(WorkflowCatalog::actions()[$action]['param_fields'], fn ($f) => $f['key'] === $key))[0];

        $this->assertSame('user', $by('create_support_onboarding', 'assign_to_user_id')['type']);
        $this->assertSame('number', $by('create_lead_task', 'due_in_days')['type']);
        $this->assertSame('number', $by('start_lead_followup', 'max_attempts')['type']);
        $this->assertSame('bool', $by('start_lead_followup', 'weekdays_only')['type']);
        $this->assertSame('text', $by('add_lead_note', 'text')['type']);
        $this->assertSame(['note', 'task'], $by('log_activity', 'type')['options']);
        $this->assertSame(['lead', 'organization', 'opportunity'], $by('create_lead_task', 'link_to')['options']);
        $this->assertSame(array_keys(WorkflowCatalog::AGENT_INPUTS), $by('run_agent', 'agent')['options']);
        $this->assertSame(\App\Domain\Gtm\Marketing\SegmentCriteria::ACCOUNT_STAGES, $by('change_account_stage', 'stage')['options']);
        $this->assertSame('json', $by('run_agent', 'input')['format'], 'the one text field that holds an object says so');
    }

    /**
     * The required flag is not a claim in a table: leave a required parameter out and the real validation refuses the action; give every required one and it accepts.
     * (Only the actions whose validation needs no database lookup, so the check runs on its own.)
     */
    public function test_a_parameter_marked_required_really_is_required_by_validate(): void
    {
        $trigger = [
            'log_activity' => 'gtm.account.updated', 'set_next_step' => 'gtm.deal.created', 'add_lead_note' => 'crm.lead.created', 'create_lead_task' => 'crm.lead.created',
            'start_lead_followup' => 'crm.lead.created', 'add_opportunity_note' => 'crm.opportunity.created', 'create_opportunity_task' => 'crm.opportunity.created',
            'email_quote' => 'crm.quote.created', 'create_invoice_task' => 'crm.invoice.created', 'change_account_stage' => 'gtm.account.updated', 'run_agent' => 'gtm.deal.created',
        ];
        $good = [
            'string' => 'A text', 'text' => 'Some text', 'number' => 3, 'bool' => true, 'enum' => null, 'user' => 1,
        ];
        $overrides = ['intervals' => '2,4,7', 'max_attempts' => 4, 'stage' => 'customer', 'agent' => 'gtm-deal-coach'];
        $checked = 0;
        foreach ($trigger as $action => $event) {
            $params = [];
            foreach (WorkflowCatalog::actions()[$action]['param_fields'] as $f) {
                if ($f['required']) {
                    $params[$f['key']] = $overrides[$f['key']] ?? ($f['type'] === 'enum' ? $f['options'][0] : $good[$f['type']]);
                }
            }
            $this->assertSame([], WorkflowCatalog::validate(1, $event, [], [['type' => $action, 'params' => $params]]), "{$action} is valid with exactly its required parameters");
            foreach (array_keys($params) as $leaveOut) {
                $less = array_diff_key($params, [$leaveOut => 1]);
                $this->assertNotSame([], WorkflowCatalog::validate(1, $event, [], [['type' => $action, 'params' => $less]]), "{$action} must be refused without {$leaveOut}");
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(15, $checked, 'the check really ran over the required parameters');
    }

    public function test_a_whole_number_parameter_refuses_a_decimal(): void
    {
        $this->assertNotSame([], WorkflowCatalog::validate(1, 'crm.lead.created', [], [['type' => 'create_lead_task', 'params' => ['title' => 'x', 'due_in_days' => 1.5]]]));
        $this->assertSame([], WorkflowCatalog::validate(1, 'crm.lead.created', [], [['type' => 'create_lead_task', 'params' => ['title' => 'x', 'due_in_days' => 2]]]));
    }

    public function test_every_trigger_a_workflow_can_use_has_a_plain_phrase(): void
    {
        $missing = array_values(array_diff(GtmEvents::triggerable(), array_keys(WorkflowCatalog::TRIGGER_PHRASES)));
        $extra = array_values(array_diff(array_keys(WorkflowCatalog::TRIGGER_PHRASES), GtmEvents::triggerable()));

        $this->assertSame([], $missing, 'a trigger without a phrase reads badly in a sentence: add its wording to WorkflowCatalog::TRIGGER_PHRASES');
        $this->assertSame([], $extra, 'a phrase for an event that is not a trigger');
        foreach (WorkflowCatalog::triggers() as $type => $t) {
            $this->assertSame(WorkflowCatalog::TRIGGER_PHRASES[$type], $t['phrase']);
            $this->assertDoesNotMatchRegularExpression('/[_.]/', $t['phrase'], $type);
        }
    }
}
