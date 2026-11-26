<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

/**
 * The GTM Prospecting and Forecast sub-modules are gone (26 Nov 2026), and what the other screens still use was re-gated.
 *
 * Proved here, with GTM_USE_MENU_RIGHTS on (the stricter reading: the middleware names the menu rows):
 *   - no route, and no middleware string on any route, names /gtm/prospecting or /gtm/forecast; `route:list --json` works and says the same
 *   - every route the removal took away is really gone (the GTM forecast, every write on an account or a contact, the contact import, the signal's send-to-prospecting)
 *   - what stays stays: the account list and detail (five screens pick from them), the inbound record-event, the CRM forecast snapshots, a signal's Convert to lead / Convert to opportunity
 *   - the account list and detail are open to a person who holds `view` on ANY ONE of Deals, Outreach, Campaigns, Customers, Agents and to nobody else; record-event needs Outreach `edit`
 */
class GtmProspectingForecastRemovedRoutesTest extends TestCase
{
    use BuildsOutreachWorld;

    private const ACCOUNT_PICKERS = '/gtm/deals|/gtm/outreach|/gtm/campaigns|/gtm/customers|/gtm/agents';

    private const REMOVED = [
        'GET api/gtm/forecast', 'GET api/gtm/forecast/settings', 'PUT api/gtm/forecast/settings',
        'GET api/gtm/accounts/candidates', 'GET api/gtm/accounts/{id}/cross-module-links',
        'POST api/gtm/accounts', 'POST api/gtm/accounts/from-company/{companyId}', 'POST api/gtm/accounts/{accountId}/contacts',
        'POST api/gtm/contacts/import/preview', 'POST api/gtm/contacts/import',
        'PATCH api/gtm/accounts/{id}', 'POST api/gtm/accounts/{id}/score-icp', 'POST api/gtm/accounts/{accountId}/activities', 'PATCH api/gtm/contacts/{id}',
        'POST api/gtm/accounts/{id}/qualify-as-lead', 'DELETE api/gtm/accounts/{id}', 'DELETE api/gtm/contacts/{id}',
        'POST api/signals/opportunities/{id}/send-to-prospecting',
    ];

    private const KEPT = [
        'GET api/gtm/accounts', 'GET api/gtm/accounts/{id}', 'POST api/gtm/contacts/{contactId}/record-event',
        'GET api/crm/opportunities/forecast-snapshots',
        'POST api/signals/opportunities/{id}/promote-to-crm', 'POST api/signals/opportunities/{id}/convert-to-opportunity',
    ];

    protected function setUp(): void
    {
        // The routes read the flag when they are registered, so it is on BEFORE the application boots.
        putenv('GTM_USE_MENU_RIGHTS=true');
        $_ENV['GTM_USE_MENU_RIGHTS'] = $_SERVER['GTM_USE_MENU_RIGHTS'] = 'true';
        parent::setUp();
        RoleKey::flushCache();
        $this->buildOutreachWorld();
        foreach (['g2g_company_opportunities' => fn ($t) => [$t->id(), $t->unsignedBigInteger('sub_institute_id'), $t->unsignedBigInteger('company_id')->nullable(), $t->string('review_status')->nullable(),
            $t->string('product_key')->nullable(), $t->string('customer_model')->nullable(), $t->dateTime('first_discovered_at')->nullable()],
            'gtm_analyses' => fn ($t) => [$t->id(), $t->unsignedBigInteger('sub_institute_id'), $t->string('subject_type')->nullable(), $t->unsignedBigInteger('subject_id')->nullable(), $t->string('kind')->nullable(),
                $t->string('status')->nullable(), $t->boolean('is_estimate')->default(false), $t->string('provider')->nullable(), $t->string('model')->nullable(), $t->string('error_code')->nullable(), $t->timestamp('created_at')->nullable()],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $columns);
            }
        }
        if (! Schema::hasColumn('gtm_accounts', 'product_key')) {
            (require base_path('database/migrations/2026_12_13_100000_add_product_scope_to_gtm_accounts.php'))->up();
        }
        $rows = [['/gtm/deals', 530], ['/gtm/campaigns', 531], ['/gtm/customers', 532], ['/gtm/agents', 533], ['/gtm/playbooks', 534]];
        foreach ($rows as [$link, $id]) {
            DB::table('tblmenumaster_g2g')->insert(['id' => $id, 'menu_name' => $link, 'access_link' => $link]);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('GTM_USE_MENU_RIGHTS');
        unset($_ENV['GTM_USE_MENU_RIGHTS'], $_SERVER['GTM_USE_MENU_RIGHTS']);
    }

    /** @return array<string, Route> "METHOD uri" => route, one entry per method */
    private function routes(): array
    {
        $out = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $out[$method . ' ' . $route->uri()] = $route;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $rights  access_link => actions
     * @return array{id: int, h: array<string, string>}
     */
    private function userWith(array $rights): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => 'employee', 'sub_institute_id' => self::T]);
        foreach ($rights as $link => $actions) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => (int) DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id'), 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => 'Asha', 'last_name' => 'Rao', 'sub_institute_id' => self::T, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    // ---------------------------------------------------------------- the routes

    public function test_no_route_and_no_middleware_names_prospecting_or_forecast(): void
    {
        $offenders = [];
        foreach ($this->routes() as $label => $route) {
            $text = $route->uri() . ' ' . json_encode($route->getAction('controller')) . ' ' . implode(' ', array_filter($route->gatherMiddleware(), 'is_string'));
            if (preg_match('#/gtm/(prospecting|forecast)|send-to-prospecting|ForecastController|ContactImportController#', $text) === 1) {
                $offenders[] = $label;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_route_list_works_and_says_the_same(): void
    {
        $code = Artisan::call('route:list', ['--json' => true]);
        $json = Artisan::output();

        $this->assertSame(0, $code);
        $list = json_decode($json, true);
        $this->assertIsArray($list);
        $this->assertGreaterThan(500, count($list), 'the whole route list, not an empty one');
        $this->assertStringNotContainsString('/gtm/prospecting', $json);
        $this->assertStringNotContainsString('/gtm/forecast', $json);
        $this->assertStringNotContainsString('send-to-prospecting', $json);
        $this->assertNotEmpty(array_filter($list, fn ($r) => $r['uri'] === 'api/gtm/accounts'));
    }

    public function test_every_route_the_removal_took_away_is_gone(): void
    {
        $present = array_keys($this->routes());

        $this->assertSame([], array_values(array_intersect(self::REMOVED, $present)));
    }

    public function test_what_stays_stays(): void
    {
        $present = array_keys($this->routes());

        $this->assertSame([], array_values(array_diff(self::KEPT, $present)));
        // The accounts the GTM screens pick from can be read and nothing else can be done to one.
        $accountRoutes = array_values(array_filter($present, fn ($l) => str_starts_with(explode(' ', $l)[1], 'api/gtm/accounts')));
        sort($accountRoutes);
        $this->assertSame(['GET api/gtm/accounts', 'GET api/gtm/accounts/{id}'], $accountRoutes);
        $contactRoutes = array_values(array_filter($present, fn ($l) => str_starts_with(explode(' ', $l)[1], 'api/gtm/contacts')));
        $this->assertSame(['POST api/gtm/contacts/{contactId}/record-event'], $contactRoutes);
    }

    // ---------------------------------------------------------------- the gates that were moved

    public function test_the_account_list_and_detail_and_record_event_carry_the_screens_that_use_them(): void
    {
        $routes = $this->routes();
        $rights = fn (string $label) => array_values(array_filter($routes[$label]->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'platformright:')));

        $this->assertSame(['platformright:' . self::ACCOUNT_PICKERS . ',view'], $rights('GET api/gtm/accounts'));
        $this->assertSame(['platformright:' . self::ACCOUNT_PICKERS . ',view'], $rights('GET api/gtm/accounts/{id}'));
        $this->assertSame(['platformright:/gtm/outreach,edit'], $rights('POST api/gtm/contacts/{contactId}/record-event'));
    }

    public function test_the_account_list_and_detail_are_open_to_each_screen_that_picks_from_them_and_to_nobody_else(): void
    {
        $account = $this->makeGtmAccount();
        $this->assertNotSame(0, $account);

        foreach (['/gtm/deals', '/gtm/outreach', '/gtm/campaigns', '/gtm/customers', '/gtm/agents'] as $link) {
            $user = $this->userWith([$link => ['view']]);
            $this->getJson('/api/gtm/accounts', $user['h'])->assertOk();
            $this->getJson("/api/gtm/accounts/{$account}", $user['h'])->assertOk();
        }

        $playbooks = $this->userWith(['/gtm/playbooks' => ['view', 'add', 'edit', 'delete']]);
        $nobody = $this->userWith([]);
        foreach ([$playbooks, $nobody] as $user) {
            $this->getJson('/api/gtm/accounts', $user['h'])->assertForbidden();
            $this->getJson("/api/gtm/accounts/{$account}", $user['h'])->assertForbidden();
        }
        $this->getJson('/api/gtm/accounts')->assertUnauthorized();
    }

    public function test_record_event_needs_outreach_edit_not_just_view_and_not_another_screen(): void
    {
        $contact = $this->makeGtmContact();
        $url = "/api/gtm/contacts/{$contact}/record-event";
        $body = ['type' => 'reply', 'subject' => 'Spoke by phone'];

        $viewOnly = $this->userWith(['/gtm/outreach' => ['view']]);
        $editor = $this->userWith(['/gtm/outreach' => ['view', 'edit']]);
        $elsewhere = $this->userWith(['/gtm/deals' => ['view', 'add', 'edit', 'delete'], '/gtm/campaigns' => ['view', 'add', 'edit', 'delete']]);

        $this->postJson($url, $body, $viewOnly['h'])->assertForbidden();
        $this->postJson($url, $body, $elsewhere['h'])->assertForbidden();
        $this->postJson($url, $body)->assertUnauthorized();
        $status = $this->postJson($url, $body, $editor['h'])->getStatusCode();
        $this->assertNotContains($status, [401, 403], 'a person with Outreach edit gets past the gate');
    }
}
