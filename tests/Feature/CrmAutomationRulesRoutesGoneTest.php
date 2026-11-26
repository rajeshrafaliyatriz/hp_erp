<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Crm\CrmAgentController;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * CRM Automation Rules are retired (26 Nov 2026; Docs/agentic-crm/briefs/WORKFLOWS-MERGE.md). The rows they saved were never executed by anything; automation lives in the GTM workflow
 * engine (Platform Services -> Workflows). `php artisan route:list --json` holds none of their four routes and the controller none of their methods, while the neighbouring agent routes (Agent Run
 * History, the agents, the CRM AI chat) are still routed. The table `crm_automation_rules` and its migration stay: nothing is dropped.
 */
class CrmAutomationRulesRoutesGoneTest extends TestCase
{
    /** @return list<array{method: string, uri: string, action: string}> */
    private function routes(): array
    {
        $this->assertSame(0, Artisan::call('route:list', ['--json' => true]));
        $routes = json_decode(Artisan::output(), true);
        $this->assertIsArray($routes);
        $this->assertGreaterThan(500, count($routes), 'the whole route list, not an empty one');

        return array_map(fn (array $r) => ['method' => (string) $r['method'], 'uri' => (string) $r['uri'], 'action' => (string) $r['action']], $routes);
    }

    public function test_none_of_the_four_automation_rules_routes_is_left(): void
    {
        $left = array_values(array_filter($this->routes(), fn (array $r) => str_contains($r['uri'], 'automation-rules')));

        $this->assertSame([], array_map(fn (array $r) => $r['method'].' '.$r['uri'], $left));
    }

    public function test_no_route_points_at_a_removed_controller_method(): void
    {
        $removed = ['getAutomationRules', 'storeAutomationRule', 'updateAutomationRule', 'toggleRule'];
        $left = array_values(array_filter($this->routes(), fn (array $r) => preg_match('/@('.implode('|', $removed).')$/', $r['action']) === 1));

        $this->assertSame([], array_map(fn (array $r) => $r['method'].' '.$r['uri'], $left));
        foreach ([...$removed, 'ruleResource'] as $method) {
            $this->assertFalse(method_exists(CrmAgentController::class, $method), "CrmAgentController::{$method} is gone");
        }
    }

    public function test_the_neighbouring_agent_routes_are_still_routed(): void
    {
        $present = array_map(fn (array $r) => $r['method'].' '.$r['uri'], $this->routes());

        foreach ([
            'GET|HEAD api/crm/crm-agents/runs', 'GET|HEAD api/crm/crm-agents/runs/{id}', 'POST api/crm/crm-agents/runs/{id}/cancel',
            'POST api/crm/crm-agents/prospecting', 'POST api/crm/crm-agents/outreach', 'POST api/crm/crm-agents/deal-coach', 'POST api/crm/ai-chat',
        ] as $stays) {
            $this->assertContains($stays, $present, "{$stays} stays");
        }
    }

    public function test_the_table_and_its_migration_are_kept(): void
    {
        $this->assertFileExists(database_path('migrations/2026_12_03_120100_create_crm_automation_rules_table.php'), 'no table is dropped in this change');
    }

    public function test_the_workflow_routes_that_replace_them_are_routed(): void
    {
        $present = array_map(fn (array $r) => $r['method'].' '.$r['uri'], $this->routes());

        foreach (['GET|HEAD api/gtm/workflows/recipes', 'POST api/gtm/workflows/recipes/{key}/install', 'GET|HEAD api/gtm/workflows/health', 'GET|HEAD api/gtm/workflows', 'GET|HEAD api/gtm/workflows/catalog'] as $route) {
            $this->assertContains($route, $present);
        }
    }
}
