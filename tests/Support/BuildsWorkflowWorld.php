<?php

namespace Tests\Support;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;

/**
 * The world the GTM Workflows API tests (recipes, list, catalogue, health) run in: the facts world (users, profiles, menu rows and rights, the event store, the GTM
 * foundation and gtm_system_state), plus the workflow tables, the scheduler's run ledger and the CRM picklists, all from the REAL migration files on the in-memory
 * sqlite database. So every recipe is validated against the real seeded picklists, exactly as it is on a real organisation.
 *
 * Nothing here can send anything: the workflows it creates are rows, and no test activates one.
 */
trait BuildsWorkflowWorld
{
    use BuildsFactsWorld;

    protected const WORKFLOWS_LINK = '/gtm/workflows';

    /** @var list<string> */
    private array $workflowMigrations = [
        '2026_10_11_130000_gtm_workflows', '2026_10_11_160000_gtm_workflow_activation', '2026_09_25_100100_create_g2g_platform_task_runs_table',
        '2026_11_20_090600_create_crm_picklist_values_table',
    ];

    protected function buildWorkflowWorld(): void
    {
        $this->buildFactsWorld();
        foreach ($this->workflowMigrations as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        // The create migration seeds the lead picklists. The sales ones are seeded by migrations that read information_schema (MariaDB only), so their
        // value lists are copied here from 2026_11_24_081200 and 2026_11_24_083100: the stages the recommended workflows name.
        foreach ([
            ['opportunities', 'sales_stage', ['Prospecting', 'Qualification', 'Needs Analysis', 'Value Proposition', 'Identify Decision Makers', 'Perception Analysis', 'Proposal/Quotation', 'Negotiation/Review', 'Closed Won', 'Closed Lost']],
            ['quotes', 'quote_stage', ['Draft', 'Sent', 'Accepted', 'Rejected', 'Revised', 'Expired']],
            ['sales_orders', 'so_stage', ['Draft', 'Confirmed', 'Processing', 'Shipped', 'Completed', 'Cancelled']],
            ['tickets', 'priority', ['Low', 'Normal', 'High', 'Urgent']],
        ] as [$module, $field, $values]) {
            foreach ($values as $i => $value) {
                DB::table('crm_picklist_values')->insert(['module' => $module, 'field_key' => $field, 'value' => $value, 'label' => $value, 'sort_order' => $i + 1, 'is_default' => false, 'status' => true]);
            }
        }
        DB::table('tblmenumaster_g2g')->insert(['id' => 540, 'menu_name' => 'Workflows', 'access_link' => self::WORKFLOWS_LINK]);
        RoleKey::flushCache();
    }

    /**
     * A user of a tenant on a profile with the given role_key, holding exactly the given rights (access_link => actions).
     *
     * @param  array<string, list<string>>  $rights
     * @return array{id: int, h: array<string, string>}
     */
    protected function person(string $roleKey, array $rights = [], int $tenant = self::T): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P'.uniqid(), 'role_key' => $roleKey, 'sub_institute_id' => $tenant]);
        foreach ($rights as $link => $actions) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => (int) DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id'), 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u'.uniqid(), 'first_name' => 'Asha', 'last_name' => 'Rao', 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer '.tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** a workflow row, as the Workflows screen would store it (a draft unless told otherwise). @param array<string, mixed> $over */
    protected function makeWorkflow(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('gtm_workflows')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'name' => 'Workflow '.uniqid(), 'trigger_event' => 'crm.lead.created', 'conditions' => json_encode([]),
            'actions' => json_encode([['type' => 'add_lead_note', 'params' => ['text' => 'Hello {{lead.company}}']]]), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** a workflow run row. @param array<string, mixed> $over */
    protected function makeRun(int $workflowId, string $status, array $over = []): int
    {
        static $event = 1000;

        return (int) DB::table('gtm_workflow_runs')->insertGetId($over + [
            'sub_institute_id' => self::T, 'workflow_id' => $workflowId, 'event_id' => ++$event, 'event_type' => 'crm.lead.created', 'entity_type' => 'crm_leads', 'entity_id' => 1,
            'status' => $status, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
