<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowWorld;
use Tests\TestCase;

/**
 * GET /api/gtm/workflows/catalog: every action carries `param_fields` (its parameters as typed fields) next to the `params` map it always had, the parameters that must be a value
 * of one of the application's own picklists arrive as a choice (type enum + options, in the picklist's order), and every trigger carries the `phrase` a sentence uses.
 */
class GtmWorkflowCatalogApiTest extends TestCase
{
    use BuildsWorkflowWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowWorld();
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        $admin = $this->person('administrator');

        return $this->getJson('/api/gtm/workflows/catalog', $admin['h'])->assertOk()->assertJsonPath('status', 1)->json('data');
    }

    private function field(array $catalog, string $action, string $key): array
    {
        return array_values(array_filter($catalog['actions'][$action]['param_fields'], fn ($f) => $f['key'] === $key))[0];
    }

    public function test_the_catalogue_keeps_every_key_it_had_and_adds_the_typed_fields(): void
    {
        $c = $this->catalog();

        $this->assertSame(['triggers', 'operators', 'actions', 'notes'], array_keys($c));
        $this->assertCount(24, $c['actions']);
        foreach ($c['actions'] as $type => $a) {
            foreach (['label', 'params', 'param_fields', 'needs', 'consequential', 'description'] as $key) {
                $this->assertArrayHasKey($key, $a, "{$type}.{$key}");
            }
            $this->assertSame(array_keys($a['params'] === [] ? [] : $a['params']), array_column($a['param_fields'], 'key'), $type);
        }
        $this->assertSame('whole number 1-10, required', $c['actions']['start_lead_followup']['params']['max_attempts'], 'the old sentence map is untouched');
        $this->assertSame('number', $this->field($c, 'start_lead_followup', 'max_attempts')['type']);
        foreach ($c['triggers'] as $type => $t) {
            foreach (['label', 'phrase', 'entity', 'provides', 'fields'] as $key) {
                $this->assertArrayHasKey($key, $t, "{$type}.{$key}");
            }
        }
        $this->assertSame('a lead is created', $c['triggers']['crm.lead.created']['phrase']);
    }

    public function test_a_parameter_that_must_be_a_picklist_value_arrives_as_a_choice_in_the_picklists_order(): void
    {
        $c = $this->catalog();

        $status = $this->field($c, 'set_lead_status', 'status');
        $this->assertSame('enum', $status['type']);
        $this->assertTrue($status['required']);
        $this->assertSame(['Attempted to Contact', 'Contacted', 'Junk Lead', 'Lost Lead', 'Not Contacted', 'Pre Qualified', 'Qualified'], $status['options']);
        $this->assertSame('enum', $this->field($c, 'convert_lead', 'required_status')['type']);
        $this->assertSame(['Prospecting', 'Qualification', 'Needs Analysis', 'Value Proposition', 'Identify Decision Makers', 'Perception Analysis', 'Proposal/Quotation', 'Negotiation/Review', 'Closed Won', 'Closed Lost'],
            $this->field($c, 'set_opportunity_stage', 'stage')['options']);
        $this->assertSame(['Draft', 'Sent', 'Accepted', 'Rejected', 'Revised', 'Expired'], $this->field($c, 'create_sales_order', 'required_stage')['options']);
        $this->assertSame(['Low', 'Normal', 'High', 'Urgent'], $this->field($c, 'create_support_onboarding', 'priority')['options']);
        $this->assertSame('string', $this->field($c, 'create_support_onboarding', 'faq_category')['type'], 'no FAQ picklist here: it stays plain text, it is not an empty choice');
        $this->assertArrayNotHasKey('options', $this->field($c, 'create_support_onboarding', 'faq_category'));
    }

    public function test_a_picklist_that_is_empty_leaves_the_parameter_as_plain_text(): void
    {
        DB::table('crm_picklist_values')->where('module', 'leads')->where('field_key', 'lead_status')->delete();

        $status = $this->field($this->catalog(), 'set_lead_status', 'status');

        $this->assertSame('string', $status['type']);
        $this->assertArrayNotHasKey('options', $status);
    }

    public function test_an_inactive_picklist_value_is_not_offered(): void
    {
        DB::table('crm_picklist_values')->where('module', 'leads')->where('field_key', 'lead_status')->where('value', 'Junk Lead')->update(['status' => false]);

        $this->assertNotContains('Junk Lead', $this->field($this->catalog(), 'set_lead_status', 'status')['options']);
    }
}
