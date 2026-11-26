<?php

namespace Tests\Feature;

use App\Domain\Crm\Automation\LeadAutomationInstaller;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowWorld;
use Tests\TestCase;

/**
 * The installer behind `php artisan crm:lead-automation-install` keeps doing what it always did now that the recipe gallery shares its code (26 Nov 2026): every recommended
 * workflow as a draft, never twice, validated against the catalogue, owned by an active administrator - and, for the console command only, a workflow of that name in ANY state
 * (archived too) counts as already there.
 */
class LeadAutomationInstallerTest extends TestCase
{
    use BuildsWorkflowWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowWorld();
    }

    public function test_install_makes_all_twenty_four_as_drafts_and_a_second_run_changes_nothing(): void
    {
        $admin = $this->person('administrator');
        $options = ['support_user_id' => $admin['id']];

        $first = LeadAutomationInstaller::install(self::T, $admin['id'], $options);
        $second = LeadAutomationInstaller::install(self::T, $admin['id'], $options);

        $this->assertCount(24, $first['created']);
        $this->assertSame([], $first['existing']);
        $this->assertSame([], $first['invalid']);
        $this->assertSame([], $second['created']);
        $this->assertCount(24, $second['existing']);
        $this->assertSame(24, DB::table('gtm_workflows')->count());
        $this->assertSame(['draft'], DB::table('gtm_workflows')->distinct()->pluck('status')->all());
        $this->assertSame('Lead Automation 1a Intake - complete lead', $first['created'][0]);
        $this->assertSame(24, DB::table('g2g_event')->where('type', 'gtm.workflow.created')->count());
        $this->assertSame(['lead-automation-install'], array_unique(array_map(fn ($e) => json_decode((string) $e->payload, true)['via'], DB::table('g2g_event')->where('type', 'gtm.workflow.created')->get()->all())));
    }

    public function test_install_with_activate_still_stamps_the_activation_time(): void
    {
        $admin = $this->person('administrator');

        LeadAutomationInstaller::install(self::T, $admin['id'], ['support_user_id' => $admin['id'], 'prefix' => 'Verify'], true);

        $this->assertSame(['active'], DB::table('gtm_workflows')->distinct()->pluck('status')->all());
        $this->assertSame(0, DB::table('gtm_workflows')->whereNull('activated_at')->count(), 'the engine only reacts to events after activated_at');
    }

    public function test_a_workflow_of_that_name_in_any_state_counts_as_already_there_for_the_console_command(): void
    {
        $admin = $this->person('administrator');
        $this->makeWorkflow(self::T, ['name' => 'Lead Automation 5e Opportunity inactive', 'status' => 'archived']);

        $r = LeadAutomationInstaller::install(self::T, $admin['id'], ['support_user_id' => $admin['id']]);

        $this->assertContains('Lead Automation 5e Opportunity inactive', $r['existing']);
        $this->assertCount(23, $r['created']);
    }

    public function test_a_recipe_the_catalogue_would_refuse_is_reported_and_not_stored(): void
    {
        $admin = $this->person('administrator');
        DB::table('crm_picklist_values')->where('module', 'leads')->where('field_key', 'lead_status')->delete();

        $r = LeadAutomationInstaller::install(self::T, $admin['id'], ['support_user_id' => $admin['id']]);

        $this->assertNotSame([], $r['invalid']);
        $this->assertArrayHasKey('Lead Automation 1a Intake - complete lead', $r['invalid']);
        $this->assertSame(count($r['created']), DB::table('gtm_workflows')->count());
    }

    public function test_only_an_active_administrator_of_the_organisation_may_own_them(): void
    {
        $employee = $this->person('employee');
        $other = $this->person('administrator', [], self::T2);
        $admin = $this->person('administrator');

        foreach ([$employee['id'], $other['id']] as $notAdmin) {
            try {
                LeadAutomationInstaller::install(self::T, $notAdmin, ['support_user_id' => $admin['id']]);
                $this->fail('a user who is not an administrator of the organisation must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not an active administrator', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('support_user_id must be an active user');
        LeadAutomationInstaller::install(self::T, $admin['id'], ['support_user_id' => 999999]);
    }

    public function test_install_one_has_no_way_to_activate_and_refuses_an_unknown_key(): void
    {
        $admin = $this->person('administrator');
        $options = ['support_user_id' => $admin['id']];

        $unknown = LeadAutomationInstaller::installOne(self::T, $admin['id'], $options, 'nope');
        $made = LeadAutomationInstaller::installOne(self::T, $admin['id'], $options, '5e-opportunity-inactive');
        $again = LeadAutomationInstaller::installOne(self::T, $admin['id'], $options, '5e-opportunity-inactive');

        $this->assertSame('unknown', $unknown['outcome']);
        $this->assertSame('created', $made['outcome']);
        $this->assertSame('existing', $again['outcome']);
        $this->assertSame($made['id'], $again['id']);
        $this->assertSame('draft', DB::table('gtm_workflows')->where('id', $made['id'])->value('status'));
        $this->assertSame(1, DB::table('gtm_workflows')->count());
    }
}
