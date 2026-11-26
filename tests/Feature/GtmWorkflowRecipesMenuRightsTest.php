<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowWorld;
use Tests\TestCase;

/**
 * The recipe routes with GTM_USE_MENU_RIGHTS on (the stricter reading: the middleware names the Workflows menu row, /gtm/workflows):
 * a person with `view` can look at the gallery, adding a recipe needs `add`, and `add` alone is not enough to be the owner of a workflow - the person must also be an
 * administrator of the organisation, because every workflow runs as an administrator.
 */
class GtmWorkflowRecipesMenuRightsTest extends TestCase
{
    use BuildsWorkflowWorld;

    protected function setUp(): void
    {
        // The routes read the flag when they are registered, so it is on BEFORE the application boots.
        putenv('GTM_USE_MENU_RIGHTS=true');
        $_ENV['GTM_USE_MENU_RIGHTS'] = $_SERVER['GTM_USE_MENU_RIGHTS'] = 'true';
        parent::setUp();
        $this->buildWorkflowWorld();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('GTM_USE_MENU_RIGHTS');
        unset($_ENV['GTM_USE_MENU_RIGHTS'], $_SERVER['GTM_USE_MENU_RIGHTS']);
    }

    private function install(array $h, string $key = '1a-intake-complete-lead')
    {
        return $this->postJson("/api/gtm/workflows/recipes/{$key}/install", [], $h);
    }

    public function test_the_routes_name_the_workflows_menu_row(): void
    {
        $uses = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/gtm/workflows/recipes') || $route->uri() === 'api/gtm/workflows/health') {
                $uses[implode('|', $route->methods()).' '.$route->uri()] = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'platformright:')));
            }
        }
        ksort($uses);

        $this->assertSame([
            'GET|HEAD api/gtm/workflows/health' => ['platformright:/gtm/workflows,view'],
            'GET|HEAD api/gtm/workflows/recipes' => ['platformright:/gtm/workflows,view'],
            'POST api/gtm/workflows/recipes/{key}/install' => ['platformright:/gtm/workflows,add'],
        ], $uses);
    }

    public function test_view_is_enough_to_list_and_to_read_the_health_but_not_to_add(): void
    {
        $viewer = $this->person('employee', [self::WORKFLOWS_LINK => ['view']]);

        $this->getJson('/api/gtm/workflows/recipes', $viewer['h'])->assertOk();
        $this->getJson('/api/gtm/workflows/health', $viewer['h'])->assertOk();
        $this->install($viewer['h'])->assertForbidden();
        $this->assertSame(0, DB::table('gtm_workflows')->count());
    }

    public function test_add_is_needed_to_add_and_an_administrator_with_add_gets_a_draft(): void
    {
        $adder = $this->person('administrator', [self::WORKFLOWS_LINK => ['view', 'add']]);
        $nobody = $this->person('administrator');

        $this->install($nobody['h'])->assertForbidden();
        $this->getJson('/api/gtm/workflows/recipes', $nobody['h'])->assertForbidden();
        $this->install($adder['h'])->assertStatus(201)->assertJsonPath('data.workflow.status', 'draft');
    }

    public function test_add_without_being_an_administrator_is_refused_in_plain_words_and_makes_nothing(): void
    {
        $manager = $this->person('hr_manager', [self::WORKFLOWS_LINK => ['view', 'add', 'edit', 'delete']]);

        $r = $this->install($manager['h'])->assertForbidden()->assertJsonPath('code', 'administrator_required');

        $this->assertStringContainsString('administrator', $r->json('message'));
        $this->assertSame(0, DB::table('gtm_workflows')->count());
    }
}
