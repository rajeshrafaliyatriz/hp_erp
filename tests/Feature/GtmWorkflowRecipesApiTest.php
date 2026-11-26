<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowWorld;
use Tests\TestCase;

/**
 * GET /api/gtm/workflows/recipes and POST /api/gtm/workflows/recipes/{key}/install (26 Nov 2026; Docs/agentic-crm/briefs/WORKFLOWS-MERGE.md).
 *
 * With the default rights (GTM_USE_MENU_RIGHTS off): an administrator or an executive may look, only an administrator may add. The install is the installer's own
 * code path (LeadAutomationInstaller::installOne): the workflow it makes is ALWAYS a draft, asking twice makes one workflow, and it only ever looks inside the caller's
 * own organisation.
 */
class GtmWorkflowRecipesApiTest extends TestCase
{
    use BuildsWorkflowWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowWorld();
    }

    /** @return array<string, array<string, mixed>> the recipe items by key */
    private function recipes(array $h): array
    {
        $r = $this->getJson('/api/gtm/workflows/recipes', $h)->assertOk()->json('data.items');
        $this->assertIsArray($r);

        return array_column($r, null, 'key');
    }

    private function install(array $h, string $key)
    {
        return $this->postJson("/api/gtm/workflows/recipes/{$key}/install", [], $h);
    }

    // ---------------------------------------------------------------- the list

    public function test_the_list_has_the_shape_the_screen_needs_for_every_recipe(): void
    {
        $admin = $this->person('administrator');
        $items = $this->getJson('/api/gtm/workflows/recipes', $admin['h'])->assertOk()->assertJsonPath('status', 1)->json('data.items');

        $this->assertCount(24, $items, 'one recipe per recommended workflow');
        $this->assertCount(24, array_unique(array_column($items, 'key')), 'keys are unique');
        foreach ($items as $i) {
            $this->assertSame(['key', 'title', 'sentence', 'trigger', 'trigger_label', 'actions', 'consequential', 'requires_approval', 'installed_workflow_id'], array_keys($i));
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $i['key']);
            $this->assertNotSame('', trim($i['title']));
            $this->assertStringStartsWith('When ', $i['sentence']);
            $this->assertIsBool($i['consequential']);
            $this->assertIsBool($i['requires_approval']);
            $this->assertNull($i['installed_workflow_id']);
            $this->assertNotEmpty($i['actions']);
            foreach ($i['actions'] as $a) {
                $this->assertSame(['type', 'label'], array_keys($a));
            }
            $this->assertSame($i['consequential'], str_contains($i['sentence'], '; needs your approval'), 'the sentence says it needs approval exactly when an action is consequential');
        }
        $consequential = array_keys(array_filter($items, fn ($i) => $i['consequential']));
        $this->assertCount(6, $consequential, 'meeting hold, conversion, quote, email quote, sales order, invoice');
    }

    public function test_titles_and_sentences_are_plain_words(): void
    {
        $admin = $this->person('administrator');
        $items = $this->recipes($admin['h']);

        $this->assertSame('Intake - complete lead', $items['1a-intake-complete-lead']['title']);
        $this->assertSame('Meeting proposed - hold the first time', $items['3f-meeting-proposed-hold-the-first-time-needs-approval']['title']);
        foreach ($items as $i) {
            $this->assertDoesNotMatchRegularExpression('/^\d/', $i['title'], 'no step number');
            $this->assertStringNotContainsString('needs approval', $i['title']);
            $this->assertStringNotContainsString('_', $i['sentence'], 'no snake_case id in a sentence: '.$i['sentence']);
            foreach (['flow_key', 'payload', 'trigger_event', 'enforced', 'governed', 'instant'] as $banned) {
                $this->assertStringNotContainsStringIgnoringCase($banned, $i['sentence']);
            }
        }
        $this->assertSame('When a lead is created and "Total possible duplicates" is more than 0, set the lead status, add a note to the lead and create a follow-up task for the lead',
            $items['1c-intake-possible-duplicate']['sentence']);
    }

    // ---------------------------------------------------------------- the install

    public function test_an_install_makes_a_draft_owned_by_the_caller_and_audits_it(): void
    {
        $admin = $this->person('administrator');
        $key = '1a-intake-complete-lead';

        $r = $this->install($admin['h'], $key)->assertStatus(201)->assertJsonPath('status', 1)->assertJsonPath('data.created', true);
        $id = $r->json('data.id');

        $row = DB::table('gtm_workflows')->find($id);
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->activated_at, 'a draft has never been switched on');
        $this->assertSame(self::T, (int) $row->sub_institute_id);
        $this->assertSame($admin['id'], (int) $row->created_by);
        $this->assertSame('Lead Automation 1a Intake - complete lead', $row->name, 'the console command names it the same way, so both recognise it');
        $this->assertSame('crm.lead.created', $row->trigger_event);
        $r->assertJsonPath('data.workflow.id', $id)->assertJsonPath('data.workflow.status', 'draft');
        $this->assertStringStartsWith('When a lead is created', (string) $r->json('data.workflow.sentence'));

        $event = DB::table('g2g_event')->where('type', 'gtm.workflow.created')->where('entity_id', $id)->first();
        $this->assertNotNull($event, 'audited like every other workflow create');
        $this->assertSame('recipe', json_decode((string) $event->payload, true)['via']);
        $this->assertSame(1, DB::table('gtm_workflows')->count());
    }

    public function test_an_install_is_always_a_draft_whatever_the_request_says(): void
    {
        $admin = $this->person('administrator');

        $this->postJson('/api/gtm/workflows/recipes/1a-intake-complete-lead/install', ['status' => 'active', 'activate' => true, 'activated_at' => now()->toDateTimeString()], $admin['h'])->assertStatus(201);

        $this->assertSame(['draft'], DB::table('gtm_workflows')->pluck('status')->all());
        $this->assertSame([null], DB::table('gtm_workflows')->pluck('activated_at')->all());
    }

    public function test_every_recipe_can_be_added_as_a_draft_in_a_normal_organisation(): void
    {
        $admin = $this->person('administrator');
        foreach (array_keys($this->recipes($admin['h'])) as $key) {
            $this->install($admin['h'], $key)->assertStatus(201);
        }

        $this->assertSame(24, DB::table('gtm_workflows')->count());
        $this->assertSame(['draft'], DB::table('gtm_workflows')->distinct()->pluck('status')->all());
        $this->assertSame(0, DB::table('gtm_workflow_runs')->count(), 'adding a recipe starts nothing');
    }

    public function test_asking_twice_makes_one_workflow_and_says_so(): void
    {
        $admin = $this->person('administrator');
        $key = '5d-new-opportunity-enter-the-pipeline';

        $first = $this->install($admin['h'], $key)->assertStatus(201);
        $second = $this->install($admin['h'], $key)->assertOk()->assertJsonPath('data.created', false);

        $this->assertSame($first->json('data.id'), $second->json('data.id'), 'the same workflow');
        $this->assertSame(1, DB::table('gtm_workflows')->count());
        $this->assertSame($first->json('data.id'), $this->recipes($admin['h'])[$key]['installed_workflow_id'], 'the gallery now shows it as added');
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'gtm.workflow.created')->count(), 'and it was audited once');
    }

    public function test_a_recipe_added_by_the_console_command_counts_as_added(): void
    {
        $admin = $this->person('administrator');
        $id = $this->makeWorkflow(self::T, ['name' => 'Lead Automation 5e Opportunity inactive', 'trigger_event' => 'crm.opportunity.inactive']);

        $this->assertSame($id, $this->recipes($admin['h'])['5e-opportunity-inactive']['installed_workflow_id']);
        $this->install($admin['h'], '5e-opportunity-inactive')->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('gtm_workflows')->count());
    }

    public function test_an_archived_workflow_does_not_count_so_the_recipe_can_be_added_again(): void
    {
        $admin = $this->person('administrator');
        $old = $this->makeWorkflow(self::T, ['name' => 'Lead Automation 5e Opportunity inactive', 'trigger_event' => 'crm.opportunity.inactive', 'status' => 'archived']);

        $this->assertNull($this->recipes($admin['h'])['5e-opportunity-inactive']['installed_workflow_id'], 'an archived workflow is not on the list, so it must not read as added');
        $new = $this->install($admin['h'], '5e-opportunity-inactive')->assertStatus(201)->json('data.id');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $this->recipes($admin['h'])['5e-opportunity-inactive']['installed_workflow_id']);
    }

    public function test_an_unknown_key_is_a_404_and_makes_nothing(): void
    {
        $admin = $this->person('administrator');

        $this->install($admin['h'], 'no-such-recipe')->assertStatus(404)->assertJsonPath('status', 0);
        $this->postJson('/api/gtm/workflows/recipes/NOT_A_KEY!/install', [], $admin['h'])->assertStatus(404);
        $this->assertSame(0, DB::table('gtm_workflows')->count());
    }

    public function test_a_recipe_this_organisation_cannot_hold_yet_is_refused_in_plain_words_and_makes_nothing(): void
    {
        $admin = $this->person('administrator');
        DB::table('crm_picklist_values')->where('module', 'leads')->where('field_key', 'lead_status')->where('value', 'Not Contacted')->delete();

        $r = $this->install($admin['h'], '1a-intake-complete-lead')->assertStatus(422)->assertJsonPath('code', 'invalid_definition');

        $this->assertStringStartsWith('This recipe cannot be added yet: ', $r->json('message'));
        $this->assertSame(0, DB::table('gtm_workflows')->count());
    }

    // ---------------------------------------------------------------- tenants

    public function test_another_organisations_workflow_is_never_reported_as_added_here(): void
    {
        $other = $this->person('administrator', [], self::T2);
        $mine = $this->person('administrator');
        $key = '5d-new-opportunity-enter-the-pipeline';
        $theirs = $this->install($other['h'], $key)->assertStatus(201)->json('data.id');

        $this->assertNull($this->recipes($mine['h'])[$key]['installed_workflow_id'], 'the other organisation has it, this one does not');
        $this->assertSame($theirs, $this->recipes($other['h'])[$key]['installed_workflow_id']);

        $mineId = $this->install($mine['h'], $key)->assertStatus(201)->assertJsonPath('data.created', true)->json('data.id');

        $this->assertNotSame($theirs, $mineId, 'a second workflow, in this organisation');
        $this->assertSame(self::T, (int) DB::table('gtm_workflows')->where('id', $mineId)->value('sub_institute_id'));
        $this->assertSame(self::T2, (int) DB::table('gtm_workflows')->where('id', $theirs)->value('sub_institute_id'));
        $this->assertSame($theirs, $this->recipes($other['h'])[$key]['installed_workflow_id']);
        $this->assertSame($mineId, $this->recipes($mine['h'])[$key]['installed_workflow_id']);
    }

    // ---------------------------------------------------------------- rights (default: admin writes, admin or executive reads)

    public function test_an_executive_may_look_but_not_add_and_an_employee_may_do_neither(): void
    {
        $executive = $this->person('executive');
        $employee = $this->person('employee');

        $this->getJson('/api/gtm/workflows/recipes', $executive['h'])->assertOk();
        $this->install($executive['h'], '1a-intake-complete-lead')->assertForbidden();
        $this->getJson('/api/gtm/workflows/recipes', $employee['h'])->assertForbidden();
        $this->install($employee['h'], '1a-intake-complete-lead')->assertForbidden();
        $this->getJson('/api/gtm/workflows/recipes')->assertUnauthorized();
        $this->postJson('/api/gtm/workflows/recipes/1a-intake-complete-lead/install')->assertUnauthorized();
        $this->assertSame(0, DB::table('gtm_workflows')->count());
    }
}
