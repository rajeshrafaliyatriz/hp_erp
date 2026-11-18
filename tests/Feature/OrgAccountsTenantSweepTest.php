<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The cross-tenant sweep of the Organisation 360 API (slice 3, acceptance check 3). It does not name the routes it tests: it READS them from the router,
 * so a route added to routes/org_accounts.php later is swept automatically, and one that forgets the tenant filter (or the rights, or whereNumber)
 * fails here without anybody remembering to extend the test.
 *
 *   - every route with an `{id}` is called as organisation A with organisation B's identity and must answer 404, the same body as for an id that never
 *     existed, and must change nothing of B's;
 *   - every route has the token and the right middleware, and every `{id}` is numeric;
 *   - a route WITHOUT an `{id}` cannot be swept this way, so each must be named below: adding one fails the test until it has been looked at;
 *   - the list never shows B's rows, whatever the caller puts in the request.
 */
class OrgAccountsTenantSweepTest extends TestCase
{
    private const A = 1;

    private const B = 2;

    /** The Organizations screen's two menu rows: the rights of Organisation 360 are theirs (routes/org_accounts.php). */
    private const RIGHT = 'platformright:/module/crm/marketing/organizations|/module/crm/support/organizations,';

    private const PREFIX = 'api/org-accounts';

    /** The routes with no `{id}`, each checked by name elsewhere (the list and its filters, meta, suggestions, and the link answers in OrgAccountsApiTest). */
    private const NO_ID_ROUTES = ['GET api/org-accounts', 'GET api/org-accounts/meta', 'GET api/org-accounts/suggestions', 'POST api/org-accounts/links'];

    /** @var array<string, string> */
    private array $attacker;

    private int $attackerId;

    private int $victim;

    private int $victimCrm;

    private int $ownDeleted;

    private int $ownDeletedCrm;

    private int $victimBareCrm;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('access_link')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
        });
        // Only the tables the sweep writes through. The 360 of the identities below points at none of the others, and it must cope with their absence anyway.
        foreach (['2026_10_10_100000_create_gtm_foundation_tables', '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_11_20_090100_create_crm_organizations_table',
            '2026_12_09_090000_create_g2g_org_accounts_tables'] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        Schema::table('crm_organizations', function ($t) {
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
        });
        Cache::flush();

        // Organisation A's caller holds EVERY right the API asks for, the CRM add right included, so a 404 can only come from the tenant check.
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 302, 'menu_name' => 'Organizations', 'access_link' => '/module/crm/marketing/organizations'],
        ]);
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Sales', 'role_key' => 'employee', 'sub_institute_id' => self::A]);
        foreach ([302] as $menu) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::A, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
        }
        $this->attackerId = DB::table('tbluser')->insertGetId(['user_name' => 'attacker', 'first_name' => 'Asha', 'sub_institute_id' => self::A, 'user_profile_id' => $profile]);
        $this->attacker = ['Authorization' => 'Bearer ' . tbluserModel::find($this->attackerId)->createToken('t')->plainTextToken];

        // Organisation B: an identity with a CRM organization, one without, and organisation A's own deleted identity.
        $this->victimCrm = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-VICTIM01', 'name' => 'Victim School', 'sub_institute_id' => self::B, 'created_at' => now(), 'updated_at' => now()]);
        // Organisation B's CRM organization with NO identity yet: if the ensure route leaked across organisations it would be the one that gets one.
        $this->victimBareCrm = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-VICTIM02', 'name' => 'Victim Bare School', 'sub_institute_id' => self::B, 'created_at' => now(), 'updated_at' => now()]);
        $this->victim = $this->identity(self::B, 'Victim School', ['crm_organization_id' => $this->victimCrm, 'lifecycle' => 'engaged']);
        $this->identity(self::B, 'Victim Without CRM', ['lifecycle' => 'target']);
        $this->ownDeleted = $this->identity(self::A, 'Own Deleted School', ['deleted_at' => now()]);
        // Organisation A's own CRM organization that was deleted: the ensure route answers it like one that never existed.
        $this->ownDeletedCrm = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-DELETED1', 'name' => 'Own Deleted CRM', 'sub_institute_id' => self::A, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->identity(self::A, 'Own School');
    }

    /** @param array<string, mixed> $o */
    private function identity(int $tenant, string $name, array $o = []): int
    {
        return DB::table('g2g_org_accounts')->insertGetId($o + ['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'lifecycle' => 'discovered',
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<Route> */
    private function orgRoutes(): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn (Route $r) => $r->uri() === self::PREFIX || str_starts_with($r->uri(), self::PREFIX . '/')));
    }

    /** @return list<string> the methods a route answers, without the HEAD that Laravel adds to every GET */
    private function methods(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    }

    /** @return array<string, mixed> everything of organisation B's that a write could change */
    private function victimFootprint(): array
    {
        return [
            'identities' => DB::table('g2g_org_accounts')->where('sub_institute_id', self::B)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'crm' => DB::table('crm_organizations')->where('sub_institute_id', self::B)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_every_id_route_answers_another_organisations_identity_with_a_404_that_reveals_nothing_and_changes_nothing(): void
    {
        $before = $this->victimFootprint();
        $crmBefore = DB::table('crm_organizations')->count();
        $identitiesBefore = DB::table('g2g_org_accounts')->count();
        $body = ['lifecycle' => 'customer', 'name' => 'Hijacked', 'owner_user_id' => $this->attackerId, 'restore_deleted' => true];

        $swept = [];
        foreach ($this->orgRoutes() as $route) {
            if (! str_contains($route->uri(), '{id}')) {
                continue;
            }
            foreach ($this->methods($route) as $method) {
                $url = fn (int $id) => '/' . str_replace('{id}', (string) $id, $route->uri());
                $none = $this->json($method, $url(999999), $body, $this->attacker)->assertStatus(404);
                // In for-crm/{id} the id is a CRM organization's, not an identity's.
                $ids = str_contains($route->uri(), 'for-crm') ? [$this->victimCrm, $this->victimBareCrm, $this->ownDeletedCrm] : [$this->victim, $this->ownDeleted];
                foreach ($ids as $id) {
                    $res = $this->json($method, $url($id), $body, $this->attacker);
                    $this->assertSame(404, $res->getStatusCode(), "{$method} {$route->uri()} with organisation B's identity {$id} (or a deleted one of the caller's)");
                    $this->assertSame($none->json(), $res->json(), "{$method} {$route->uri()}: the answer is the one for an id that never existed");
                }
                $swept[] = "{$method} {$route->uri()}";
            }
        }

        $this->assertEqualsCanonicalizing(['GET api/org-accounts/{id}', 'PATCH api/org-accounts/{id}', 'POST api/org-accounts/{id}/create-crm-organisation', 'POST api/org-accounts/for-crm/{id}'], $swept,
            'the sweep found the id routes (a new one is swept automatically, and this list is then updated on purpose)');
        $this->assertSame($before, $this->victimFootprint(), "nothing of organisation B's changed");
        $this->assertSame($crmBefore, DB::table('crm_organizations')->count(), 'no CRM organization was created or restored');
        $this->assertSame($identitiesBefore, DB::table('g2g_org_accounts')->count(), 'no identity was made for anybody, in either organisation');
    }

    public function test_the_suggestions_of_another_organisations_identity_are_a_404_too(): void
    {
        $none = $this->getJson('/' . self::PREFIX . '/suggestions?identity_id=999999', $this->attacker)->assertStatus(404);
        foreach ([$this->victim, $this->ownDeleted] as $id) {
            $res = $this->getJson('/' . self::PREFIX . "/suggestions?identity_id={$id}", $this->attacker);
            $this->assertSame(404, $res->getStatusCode(), "identity {$id}");
            $this->assertSame($none->json(), $res->json());
        }
        $this->getJson('/' . self::PREFIX . '/suggestions?identity_id=0', $this->attacker)->assertStatus(422);
    }

    public function test_the_caller_cannot_choose_the_organisation_with_a_parameter_a_header_or_the_body(): void
    {
        $before = $this->victimFootprint();

        $base = '/' . self::PREFIX;
        $headers = $this->attacker + ['sub_institute_id' => (string) self::B];
        $inBody = ['sub_institute_id' => self::B];

        $this->json('GET', "{$base}/{$this->victim}?sub_institute_id=" . self::B, [], $headers)->assertStatus(404);
        $this->json('GET', "{$base}/{$this->victim}", $inBody, $headers)->assertStatus(404);
        $this->json('PATCH', "{$base}/{$this->victim}", $inBody + ['name' => 'X'], $headers)->assertStatus(404);
        foreach (["{$base}?per_page=50&sub_institute_id=" . self::B, "{$base}?per_page=50"] as $url) {
            $this->assertSame(['Own School'], array_column($this->json('GET', $url, $inBody, $headers)->assertOk()->json('data'), 'name'), $url);
        }
        $this->assertSame($before, $this->victimFootprint());
    }

    public function test_the_list_never_shows_another_organisations_rows(): void
    {
        $res = $this->getJson('/' . self::PREFIX . '?per_page=50', $this->attacker)->assertOk();
        $this->assertSame(['Own School'], array_column($res->json('data'), 'name'), 'only the caller\'s own, not deleted, identity');
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame(1, $res->json('summary.total'));
        $this->assertSame(0, $res->json('summary.engaged'), "organisation B's engaged identity is not counted");

        foreach (['?q=Victim', '?q=victim.example', '?has_crm=1', '?lifecycle=engaged', '?lifecycle=target', '?sort=updated_at'] as $query) {
            $data = $this->getJson('/' . self::PREFIX . $query, $this->attacker)->assertOk()->json('data');
            $this->assertNotContains('Victim School', array_column($data, 'name'), $query);
            $this->assertNotContains('Victim Without CRM', array_column($data, 'name'), $query);
        }
    }

    public function test_a_link_answer_naming_another_organisations_rows_is_a_404_without_any_fake_in_the_way(): void
    {
        $theirCompany = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => self::B, 'name' => 'Their Co', 'normalized_name' => 'their co', 'created_at' => now(), 'updated_at' => now()]);
        $myCrm = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-MINE0001', 'name' => 'Mine', 'sub_institute_id' => self::A, 'created_at' => now(), 'updated_at' => now()]);
        $before = [DB::table('g2g_org_accounts')->count(), $this->victimFootprint()];

        $pairs = [
            ['crm_organization', $this->victimCrm, 'g2g_company', $theirCompany],
            ['crm_organization', $myCrm, 'g2g_company', $theirCompany],
            ['crm_organization', $this->victimCrm, 'crm_organization', $myCrm],
        ];
        foreach (['confirm', 'reject'] as $action) {
            foreach ($pairs as [$leftType, $leftId, $rightType, $rightId]) {
                $this->postJson('/' . self::PREFIX . '/links', ['left_type' => $leftType, 'left_id' => $leftId, 'right_type' => $rightType, 'right_id' => $rightId, 'action' => $action], $this->attacker)
                    ->assertStatus(404);
            }
        }
        $this->assertSame($before, [DB::table('g2g_org_accounts')->count(), $this->victimFootprint()]);
    }

    public function test_every_route_carries_the_token_check_and_a_screen_right_and_every_id_is_numeric(): void
    {
        $routes = $this->orgRoutes();
        $this->assertGreaterThanOrEqual(7, count($routes));

        foreach ($routes as $route) {
            $label = implode('|', $this->methods($route)) . ' ' . $route->uri();
            $middleware = $route->gatherMiddleware();
            $this->assertContains('api.token', $middleware, "{$label} needs a token");
            $right = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, self::RIGHT));
            $this->assertNotNull($right, "{$label} needs a right on the Organizations screen (the Marketing OR the Support row)");
            $action = substr((string) $right, strlen(self::RIGHT));
            $this->assertContains($action, ['view', 'add', 'edit', 'delete'], $label);
            // The one write behind the view right: it only records the identity of a CRM organization the caller can already open (derived data).
            if ($this->methods($route) !== ['GET'] && $route->uri() !== self::PREFIX . '/for-crm/{id}') {
                $this->assertNotSame('view', $action, "{$label} writes, so the view right is not enough");
            }
            if (str_contains($route->uri(), '{id}')) {
                $this->assertSame('[0-9]+', $route->wheres['id'] ?? null, "{$label}: {id} must be whereNumber");
            }
        }
    }

    public function test_a_route_without_an_id_is_named_in_this_test_so_a_new_one_is_noticed(): void
    {
        $found = [];
        foreach ($this->orgRoutes() as $route) {
            if (! str_contains($route->uri(), '{')) {
                foreach ($this->methods($route) as $method) {
                    $found[] = "{$method} {$route->uri()}";
                }
            }
        }

        $this->assertEqualsCanonicalizing(self::NO_ID_ROUTES, $found, 'a route with no {id} cannot be swept by id: check its tenant filtering by hand, then name it in NO_ID_ROUTES');
    }
}
