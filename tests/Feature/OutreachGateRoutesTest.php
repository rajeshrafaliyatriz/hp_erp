<?php

namespace Tests\Feature;

use App\Domain\OutreachGate\OutreachGateAccess;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

/**
 * The HTTP surface of slice 10: the gate preview (GET /api/outreach/gate) and the approved claims (GET, POST, PUT /api/outreach/claims). It reads the routes from the
 * router, so a route added to routes/outreach_gate.php later fails here until it has the token check, a rights middleware and a tenant-proof id; then the rights
 * per action, the cross-organisation cases, and what each endpoint does.
 *
 * Nothing here sends, and no route exists that could: the sweep also fails on any route whose name or path suggests it.
 */
class OutreachGateRoutesTest extends TestCase
{
    use BuildsOutreachWorld;

    private const PREFIX = 'api/outreach';

    /** The routes with no id in the path: each is looked at below. Adding one fails the test until someone has. */
    private const NO_ID_ROUTES = ['GET api/outreach/gate', 'GET api/outreach/claims', 'POST api/outreach/claims'];

    /** @var array{id: int, h: array<string, string>} */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        RoleKey::flushCache();
        $this->buildOutreachWorld();
        config(['app.url' => 'https://crm.example.org']);   // a public address, so the unsubscribe-link check passes for a clean draft
        // An administrator who holds the Products rights and the Outreach Approvals view: nothing else, so a 404 can only come from the organisation check.
        $this->admin = $this->userAs('administrator', [self::PRODUCTS_LINK => ['view', 'add', 'edit'], self::APPROVALS_LINK => ['view']]);
    }

    /**
     * @param  array<string, list<string>>  $rights  access_link => actions
     * @return array{id: int, h: array<string, string>}
     */
    private function userAs(string $role, array $rights = [], int $tenant = self::T): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => $role, 'sub_institute_id' => $tenant]);
        foreach ($rights as $link => $actions) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => (int) DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id'), 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => 'Asha', 'last_name' => 'Rao', 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn (Route $r) => $r->uri() === self::PREFIX || str_starts_with($r->uri(), self::PREFIX . '/')));
    }

    /** @return list<string> */
    private function methods(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    }

    // ---------------------------------------------------------------- the routes themselves

    public function test_every_route_has_the_token_check_a_rights_middleware_and_numeric_ids_and_none_could_send(): void
    {
        $routes = $this->routes();
        $this->assertNotEmpty($routes, 'the outreach routes are registered');

        $noId = [];
        foreach ($routes as $route) {
            $label = implode('|', $this->methods($route)) . ' ' . $route->uri();
            $middleware = $route->gatherMiddleware();
            $this->assertContains('api.token', $middleware, "{$label} has no token check");
            $rights = array_filter($middleware, fn ($m) => is_string($m) && (str_starts_with($m, 'platformright:') || str_starts_with($m, 'anyaccess:')));
            $this->assertNotEmpty($rights, "{$label} has no rights middleware");
            if (str_contains($route->uri(), '{')) {
                $this->assertSame('[0-9]+', $route->wheres['id'] ?? null, "{$label}: the id is not numeric");
            } else {
                $noId[] = implode('|', $this->methods($route)) . ' ' . $route->uri();
            }
            $this->assertNotContains('DELETE', $this->methods($route), "{$label}: nothing is deleted here");
            if ($route->uri() === self::PREFIX . '/gate') {
                $this->assertContains('throttle:60,1', $middleware, "{$label}: every preview runs the checks and records a set, so it is rate limited");
            }
            $this->assertDoesNotMatchRegularExpression('~send|schedule|enable|dispatch|queue~i', $route->uri() . ' ' . ($route->getActionName()), "{$label} looks like it could send");
        }
        sort($noId);
        $expected = self::NO_ID_ROUTES;
        sort($expected);
        $this->assertSame($expected, $noId, 'a route without an id was added or removed: look at it, then say so here');
    }

    public function test_the_rights_are_those_of_the_screens_and_a_write_needs_more_than_view(): void
    {
        $byLabel = [];
        foreach ($this->routes() as $route) {
            $byLabel[implode('|', $this->methods($route)) . ' ' . $route->uri()] = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && (str_starts_with($m, 'platformright:') || str_starts_with($m, 'anyaccess:'))));
        }

        $this->assertSame(['platformright:' . self::PRODUCTS_LINK . ',view'], $byLabel['GET api/outreach/claims']);
        $this->assertSame(['platformright:' . self::PRODUCTS_LINK . ',add'], $byLabel['POST api/outreach/claims']);
        $this->assertSame(['platformright:' . self::PRODUCTS_LINK . ',edit'], $byLabel['PUT api/outreach/claims/{id}']);
        $this->assertSame([OutreachGateAccess::previewMiddleware()], $byLabel['GET api/outreach/gate']);
        $this->assertSame('anyaccess:/module/crm/marketing/outreach-approvals@view,admin+executive', OutreachGateAccess::previewMiddleware());
        config(['gtm.permissions.use_menu_rights' => true]);
        $this->assertSame('anyaccess:/module/crm/marketing/outreach-approvals@view,/gtm/outreach@view', OutreachGateAccess::previewMiddleware());
    }

    // ---------------------------------------------------------------- rights: no token, no right, the wrong right

    public function test_without_a_token_every_route_answers_401(): void
    {
        $this->getJson('/api/outreach/gate?table=crm_emails&id=1')->assertStatus(401);
        $this->getJson('/api/outreach/claims')->assertStatus(401);
        $this->postJson('/api/outreach/claims', [])->assertStatus(401);
        $this->putJson('/api/outreach/claims/1', [])->assertStatus(401);
    }

    public function test_a_user_with_no_right_gets_403_everywhere(): void
    {
        $nobody = $this->userAs('employee');

        $this->getJson('/api/outreach/gate?table=crm_emails&id=1', $nobody['h'])->assertStatus(403);
        $this->getJson('/api/outreach/gate?table=gtm_outreach_messages&id=1', $nobody['h'])->assertStatus(403);
        $this->getJson('/api/outreach/claims', $nobody['h'])->assertStatus(403);
        $this->postJson('/api/outreach/claims', ['product_master_id' => 1, 'claim' => 'a b c d'], $nobody['h'])->assertStatus(403);
        $this->putJson('/api/outreach/claims/1', ['status' => 'approved'], $nobody['h'])->assertStatus(403);
    }

    public function test_the_claims_actions_are_separate_rights(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $viewer = $this->userAs('employee', [self::PRODUCTS_LINK => ['view']]);
        $adder = $this->userAs('employee', [self::PRODUCTS_LINK => ['add']]);
        $editor = $this->userAs('employee', [self::PRODUCTS_LINK => ['edit']]);
        $body = ['product_master_id' => $product, 'claim' => 'ScholarClone cuts paperwork by 60% for schools.'];

        $this->getJson('/api/outreach/claims', $viewer['h'])->assertOk();
        $this->postJson('/api/outreach/claims', $body, $viewer['h'])->assertStatus(403);
        $this->getJson('/api/outreach/claims', $adder['h'])->assertStatus(403);
        $claimId = $this->postJson('/api/outreach/claims', $body, $adder['h'])->assertStatus(201)->json('data.claim.id');
        $this->putJson("/api/outreach/claims/{$claimId}", ['status' => 'approved'], $adder['h'])->assertStatus(403);
        $this->putJson("/api/outreach/claims/{$claimId}", ['status' => 'approved'], $viewer['h'])->assertStatus(403);
        $this->putJson("/api/outreach/claims/{$claimId}", ['status' => 'approved'], $editor['h'])->assertOk();
        $this->assertSame('approved', DB::table('g2g_product_claims')->where('id', $claimId)->value('status'));
    }

    public function test_the_preview_asks_for_the_right_of_the_screen_the_message_is_on(): void
    {
        [$gtm, $crm] = $this->twoMessages();
        $approver = $this->userAs('employee', [self::APPROVALS_LINK => ['view']]);
        $gtmOnly = $this->userAs('administrator');
        $executive = $this->userAs('executive');
        $employeeWithGtmRow = $this->userAs('employee', [self::GTM_OUTREACH_LINK => ['view']]);   // the GTM routes ignore this row while GTM_USE_MENU_RIGHTS is off

        $this->getJson("/api/outreach/gate?table=crm_emails&id={$crm}", $approver['h'])->assertOk();
        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $approver['h'])->assertStatus(403);

        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $gtmOnly['h'])->assertOk();
        $this->getJson("/api/outreach/gate?table=crm_emails&id={$crm}", $gtmOnly['h'])->assertStatus(403);

        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $executive['h'])->assertOk();
        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $employeeWithGtmRow['h'])->assertStatus(403);
    }

    public function test_with_menu_rights_on_the_gtm_row_decides_the_gtm_preview(): void
    {
        [$gtm] = $this->twoMessages();
        $withRow = $this->userAs('employee', [self::GTM_OUTREACH_LINK => ['view']]);
        $without = $this->userAs('administrator');
        $identity = fn (array $u) => ['user' => tbluserModel::find($u['id']), 'user_id' => $u['id'], 'sub_institute_id' => self::T];

        config(['gtm.permissions.use_menu_rights' => true]);
        $this->assertTrue(OutreachGateAccess::canPreview($identity($withRow), 'gtm_outreach_messages'));
        $this->assertFalse(OutreachGateAccess::canPreview($identity($without), 'gtm_outreach_messages'), 'the matrix decides, not the role');
        $this->assertFalse(OutreachGateAccess::canPreview($identity($withRow), 'crm_emails'));
        $this->assertNotNull($gtm);
    }

    // ---------------------------------------------------------------- another organisation: 404 and nothing changes

    /** @return array{0: int, 1: int} [a GTM message, a CRM email] of organisation 1 that pass the gate */
    private function twoMessages(int $tenant = self::T): array
    {
        $email = 'person.' . uniqid() . '@example.test';
        $this->makeContact($tenant, ['email' => $email]);
        $gtm = $this->makeGtmMessage($tenant, $this->makeGtmContact($tenant, ['email' => $email]));
        $lead = $this->makeLead($tenant, ['email' => 'lead.' . uniqid() . '@example.test']);
        $crm = $this->makeCrmEmail($tenant, 'LEAD', $lead, ['to_address' => DB::table('crm_leads')->where('id', $lead)->value('email')]);

        return [(int) $gtm->id, (int) $crm->id];
    }

    private function footprintOfB(): array
    {
        $rows = fn (string $t) => DB::table($t)->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['claims' => $rows('g2g_product_claims'), 'checks' => $rows('g2g_outreach_gate_checks'), 'events' => $rows('g2g_event'), 'gtm' => $rows('gtm_outreach_messages'), 'crm' => $rows('crm_emails')];
    }

    public function test_every_id_route_answers_another_organisations_record_with_the_404_of_an_id_that_never_existed_and_changes_nothing(): void
    {
        $theirs = $this->makeProduct(self::T2, 'TheirProduct');
        $theirClaim = $this->makeClaim(self::T2, $theirs, 'TheirProduct cuts waiting by 40% for schools.');
        [$theirGtm, $theirCrm] = $this->twoMessages(self::T2);
        $before = $this->footprintOfB();

        // PUT /claims/{id}
        $none = $this->putJson('/api/outreach/claims/999999', ['status' => 'approved'], $this->admin['h'])->assertStatus(404);
        $mine = $this->putJson("/api/outreach/claims/{$theirClaim}", ['status' => 'retired', 'sub_institute_id' => self::T2], $this->admin['h'])->assertStatus(404);
        $this->assertSame($none->json(), $mine->json(), 'the same body: nothing reveals that the claim exists');
        // a bad body must not change that answer either, and neither may a body that names the other organisation (found by mutation: the existence check alone
        // trusted a tenant id from the request in one variant, and only a bad status told it from the real thing)
        $this->putJson("/api/outreach/claims/{$theirClaim}", ['status' => 'nonsense'], $this->admin['h'])->assertStatus(404);
        $this->putJson("/api/outreach/claims/{$theirClaim}", ['status' => 'nonsense', 'sub_institute_id' => self::T2], $this->admin['h'])->assertStatus(404);
        $this->putJson("/api/outreach/claims/{$theirClaim}?sub_institute_id=" . self::T2, ['status' => 'retired'], $this->admin['h'])->assertStatus(404);

        // GET /gate?table=&id= : B's messages, in both tables
        foreach (['gtm_outreach_messages' => $theirGtm, 'crm_emails' => $theirCrm] as $table => $id) {
            $admin = $this->userAs('administrator', [self::APPROVALS_LINK => ['view']]);
            $none = $this->getJson("/api/outreach/gate?table={$table}&id=999999", $admin['h'])->assertStatus(404);
            $theirs404 = $this->getJson("/api/outreach/gate?table={$table}&id={$id}", $admin['h'])->assertStatus(404);
            $this->assertSame($none->json(), $theirs404->json(), $table);
            $this->getJson("/api/outreach/gate?table={$table}&id={$id}&latest=1", $admin['h'])->assertStatus(404);
            // naming the other organisation in the query changes nothing
            $this->getJson("/api/outreach/gate?table={$table}&id={$id}&sub_institute_id=" . self::T2, $admin['h'])->assertStatus(404);
            $this->getJson("/api/outreach/gate?table={$table}&id={$id}&latest=1&sub_institute_id=" . self::T2, $admin['h'])->assertStatus(404);
        }

        $this->assertSame($before, $this->footprintOfB(), 'nothing of organisation B changed or was recorded');
        $this->assertSame('approved', DB::table('g2g_product_claims')->where('id', $theirClaim)->value('status'));
    }

    public function test_the_list_shows_only_the_callers_organisation_and_a_foreign_product_cannot_be_claimed(): void
    {
        $mine = $this->makeProduct(self::T, 'ScholarClone');
        $theirs = $this->makeProduct(self::T2, 'TheirProduct');
        $shared = $this->makeProduct(0, 'SharedProduct', ['kind' => 'product']);
        $myClaim = $this->makeClaim(self::T, $mine, 'ScholarClone cuts paperwork by 60% for schools.');
        $this->makeClaim(self::T2, $theirs, 'TheirProduct cuts waiting by 40% for schools.');
        $before = DB::table('g2g_product_claims')->count();

        $list = $this->getJson('/api/outreach/claims', $this->admin['h'])->assertOk()->json('data');
        $this->assertSame([$myClaim], array_column($list['claims'], 'id'));
        $this->assertSame(1, $list['total']);

        $this->postJson('/api/outreach/claims', ['product_master_id' => $theirs, 'claim' => 'TheirProduct cuts waiting by 40% for schools.'], $this->admin['h'])
            ->assertStatus(422)->assertJsonPath('message', 'That product was not found for this organisation.');
        $this->postJson('/api/outreach/claims', ['product_master_id' => 999999, 'claim' => 'Nothing cuts anything by 10% here.'], $this->admin['h'])->assertStatus(422);
        $this->assertSame($before, DB::table('g2g_product_claims')->count(), 'nothing was written for a product the organisation cannot see');

        // a shared product (tenant 0) may be claimed, and the claim is the organisation's own
        $claim = $this->postJson('/api/outreach/claims', ['product_master_id' => $shared, 'claim' => 'SharedProduct cuts paperwork by 30% for schools.'], $this->admin['h'])->assertStatus(201)->json('data.claim');
        $this->assertSame(self::T, (int) DB::table('g2g_product_claims')->where('id', $claim['id'])->value('sub_institute_id'));
        $this->assertSame('SharedProduct', $claim['product_name']);
    }

    public function test_a_tenant_id_in_the_request_is_ignored(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');

        $claim = $this->postJson('/api/outreach/claims?sub_institute_id=' . self::T2, ['product_master_id' => $product, 'claim' => 'ScholarClone cuts paperwork by 60% for schools.', 'sub_institute_id' => self::T2], $this->admin['h'])
            ->assertStatus(201)->json('data.claim');

        $this->assertSame(self::T, (int) DB::table('g2g_product_claims')->where('id', $claim['id'])->value('sub_institute_id'));

        // the same on every other route (found by mutation): a decision, the list, the preview and its "latest" read all use the token's organisation
        $other = $this->makeClaim(self::T2, $this->makeProduct(self::T2, 'TheirProduct'), 'TheirProduct cuts waiting by 40% for schools.', 'proposed');
        $this->putJson("/api/outreach/claims/{$claim['id']}", ['status' => 'approved', 'sub_institute_id' => self::T2], $this->admin['h'])->assertOk();
        $this->assertSame('approved', DB::table('g2g_product_claims')->where('id', $claim['id'])->value('status'));
        $this->assertSame([$claim['id']], array_column($this->getJson('/api/outreach/claims?sub_institute_id=' . self::T2, $this->admin['h'])->assertOk()->json('data.claims'), 'id'), "the list never shows the other organisation's claim");
        $this->assertNotContains($other, array_column($this->getJson('/api/outreach/claims?sub_institute_id=' . self::T2, $this->admin['h'])->json('data.claims'), 'id'));
        [$gtm] = $this->twoMessages();
        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}&sub_institute_id=" . self::T2, $this->admin['h'])->assertOk();
        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}&latest=1&sub_institute_id=" . self::T2, $this->admin['h'])->assertOk()->assertJsonPath('data.passed', true);
    }

    public function test_the_claim_events_are_registered_in_the_catalogue_as_audit_only_projections(): void
    {
        foreach (['outreach.claim.proposed', 'outreach.claim.decided'] as $type) {
            $this->assertSame(['AuditLogProjector' => \App\Services\Events\EventCatalogue::PROJECTOR], \App\Services\Events\EventCatalogue::SHIPPED[$type] ?? null, $type);
        }
    }

    public function test_the_domain_list_never_returns_more_than_200_whatever_it_is_asked_for(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        for ($i = 1; $i <= 205; $i++) {
            $this->makeClaim(self::T, $product, "ScholarClone cuts paperwork by {$i}% for schools.", 'proposed');
        }

        $claims = app(\App\Domain\OutreachGate\ProductClaims::class);

        $this->assertCount(200, $claims->list(self::T, null, null, 1000)['claims']);
        $this->assertSame(205, $claims->list(self::T, null, null, 1000)['total']);
        $this->assertCount(1, $claims->list(self::T, null, null, 0)['claims'], 'a limit below 1 is read as 1');
    }

    public function test_another_organisations_claim_on_a_shared_product_does_not_block_this_organisation_from_filing_the_same_sentence(): void
    {
        $shared = $this->makeProduct(0, 'SharedProduct', ['kind' => 'product']);
        $this->makeClaim(self::T2, $shared, 'SharedProduct cuts paperwork by 30% for schools.');

        $this->postJson('/api/outreach/claims', ['product_master_id' => $shared, 'claim' => 'SharedProduct cuts paperwork by 30% for schools.'], $this->admin['h'])->assertStatus(201);
        $this->assertSame(2, DB::table('g2g_product_claims')->count());
        $this->assertSame(1, DB::table('g2g_product_claims')->where('sub_institute_id', self::T)->count());
    }

    // ---------------------------------------------------------------- the claims: what they do

    public function test_a_claim_is_proposed_then_approved_then_retired_and_every_step_is_audited(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $text = 'ScholarClone cuts paperwork by 60% for schools.';

        $created = $this->postJson('/api/outreach/claims', ['product_master_id' => $product, 'claim' => $text, 'source_url' => 'https://example.org/case-study'], $this->admin['h'])->assertStatus(201)->json('data.claim');
        $this->assertSame(['proposed', null, $this->admin['id'], 'ScholarClone', 'https://example.org/case-study'], [$created['status'], $created['approved_by'], $created['created_by'], $created['product_name'], $created['source_url']]);

        $approved = $this->putJson("/api/outreach/claims/{$created['id']}", ['status' => 'approved'], $this->admin['h'])->assertOk()->json('data.claim');
        $this->assertSame(['approved', $this->admin['id']], [$approved['status'], $approved['approved_by']]);
        $this->assertNotNull($approved['approved_at']);

        $retired = $this->putJson("/api/outreach/claims/{$created['id']}", ['status' => 'retired'], $this->admin['h'])->assertOk()->json('data.claim');
        $this->assertSame('retired', $retired['status']);

        $this->putJson("/api/outreach/claims/{$created['id']}", ['status' => 'approved'], $this->admin['h'])->assertOk();   // a retired claim can be reinstated
        $events = DB::table('g2g_event')->where('sub_institute_id', self::T)->whereIn('type', ['outreach.claim.proposed', 'outreach.claim.decided'])->orderBy('id')->get();
        $this->assertSame(['outreach.claim.proposed', 'outreach.claim.decided', 'outreach.claim.decided', 'outreach.claim.decided'], $events->pluck('type')->all());
        $this->assertSame([$this->admin['id']], $events->pluck('actor_id')->unique()->map(fn ($v) => (int) $v)->values()->all());
        $this->assertSame(['approved', 'retired', 'approved'], $events->skip(1)->map(fn ($e) => json_decode($e->payload, true)['to'])->values()->all());
        $this->assertCount(4, $events->pluck('idempotency_key')->unique(), 'every decision has its own key: none is lost');
    }

    public function test_asking_for_the_status_a_claim_already_has_changes_nothing_and_writes_no_event(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $id = $this->makeClaim(self::T, $product, 'ScholarClone cuts paperwork by 60% for schools.');
        $stamp = DB::table('g2g_product_claims')->where('id', $id)->first();

        $this->putJson("/api/outreach/claims/{$id}", ['status' => 'approved'], $this->admin['h'])->assertOk();

        $this->assertEquals($stamp, DB::table('g2g_product_claims')->where('id', $id)->first());
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'outreach.claim.decided')->count());
    }

    public function test_the_same_sentence_cannot_be_filed_twice_for_a_product_however_it_is_written(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $other = $this->makeProduct(self::T, 'PayrollCloud');
        $first = $this->postJson('/api/outreach/claims', ['product_master_id' => $product, 'claim' => 'ScholarClone cuts paperwork by 60% for schools.'], $this->admin['h'])->assertStatus(201)->json('data.claim');

        $dup = $this->postJson('/api/outreach/claims', ['product_master_id' => $product, 'claim' => '  SCHOLARCLONE cuts   paperwork by 60 % for schools!! '], $this->admin['h'])->assertStatus(422);
        $this->assertStringContainsString("claim #{$first['id']}", $dup->json('message'));
        $this->assertStringContainsString('proposed', $dup->json('message'));
        $this->assertSame(1, DB::table('g2g_product_claims')->count());

        // the same sentence for another product, or for another organisation's product list, is a different claim
        $this->postJson('/api/outreach/claims', ['product_master_id' => $other, 'claim' => 'ScholarClone cuts paperwork by 60% for schools.'], $this->admin['h'])->assertStatus(201);
    }

    public function test_a_bad_request_is_a_422_with_a_reason_and_writes_nothing(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');

        foreach ([
            'no product' => ['claim' => 'ScholarClone cuts paperwork by 60% for schools.'],
            'a product that is not a number' => ['product_master_id' => 'abc', 'claim' => 'ScholarClone cuts paperwork by 60% for schools.'],
            'no claim' => ['product_master_id' => $product],
            'a claim over 500 characters' => ['product_master_id' => $product, 'claim' => str_repeat('word ', 101)],
            'two words only' => ['product_master_id' => $product, 'claim' => 'Best ever'],
            'only punctuation' => ['product_master_id' => $product, 'claim' => '!!! ??? ...'],
            'a source that is not a web address' => ['product_master_id' => $product, 'claim' => 'ScholarClone cuts paperwork by 60% for schools.', 'source_url' => 'not a url'],
        ] as $label => $body) {
            $this->postJson('/api/outreach/claims', $body, $this->admin['h'])->assertStatus(422);
            $this->assertSame(0, DB::table('g2g_product_claims')->count(), $label);
        }
        $id = $this->makeClaim(self::T, $product, 'ScholarClone cuts paperwork by 60% for schools.', 'proposed');
        $this->putJson("/api/outreach/claims/{$id}", ['status' => 'proposed'], $this->admin['h'])->assertStatus(422);
        $this->putJson("/api/outreach/claims/{$id}", [], $this->admin['h'])->assertStatus(422);
        $this->assertSame('proposed', DB::table('g2g_product_claims')->where('id', $id)->value('status'));
    }

    public function test_the_list_can_be_filtered_by_product_and_status_and_pages(): void
    {
        $a = $this->makeProduct(self::T, 'ScholarClone');
        $b = $this->makeProduct(self::T, 'PayrollCloud');
        $c1 = $this->makeClaim(self::T, $a, 'ScholarClone cuts paperwork by 60% for schools.', 'approved');
        $c2 = $this->makeClaim(self::T, $a, 'ScholarClone is the best admissions tool.', 'proposed');
        $c3 = $this->makeClaim(self::T, $b, 'PayrollCloud runs payroll 10x faster than before.', 'approved');

        $ids = fn (string $query) => array_column($this->getJson('/api/outreach/claims' . $query, $this->admin['h'])->assertOk()->json('data.claims'), 'id');
        $this->assertSame([$c3, $c2, $c1], $ids(''), 'newest first');
        $this->assertSame([$c2, $c1], $ids("?product_master_id={$a}"));
        $this->assertSame([$c3, $c1], $ids('?status=approved'));
        $this->assertSame([$c1], $ids("?product_master_id={$a}&status=approved"));
        $this->assertSame([$c2], $ids('?limit=1&offset=1'));
        $this->getJson('/api/outreach/claims?status=nonsense', $this->admin['h'])->assertStatus(422);
        $this->getJson('/api/outreach/claims?limit=0', $this->admin['h'])->assertStatus(422);
    }

    public function test_an_approved_claim_changes_what_the_gate_lets_through_and_a_retired_one_takes_it_back(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        [$gtm] = $this->twoMessages();
        DB::table('gtm_outreach_messages')->where('id', $gtm)->update(['body' => 'ScholarClone cuts paperwork by 60% for schools. Would a call suit you?']);
        $claim = $this->postJson('/api/outreach/claims', ['product_master_id' => $product, 'claim' => 'ScholarClone cuts paperwork by 60% for schools.'], $this->admin['h'])->assertStatus(201)->json('data.claim.id');
        $check = fn () => collect($this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $this->admin['h'])->assertOk()->json('data.checks'))->firstWhere('key', 'unapproved_claim')['passed'];

        $this->assertFalse($check(), 'proposed is not approved');
        $this->putJson("/api/outreach/claims/{$claim}", ['status' => 'approved'], $this->admin['h'])->assertOk();
        $this->assertTrue($check());
        $this->putJson("/api/outreach/claims/{$claim}", ['status' => 'retired'], $this->admin['h'])->assertOk();
        $this->assertFalse($check());
    }

    // ---------------------------------------------------------------- the preview: what it does

    public function test_the_preview_answers_in_the_contract_shape_and_records_the_set(): void
    {
        [$gtm, $crm] = $this->twoMessages();

        foreach (['gtm_outreach_messages' => $gtm, 'crm_emails' => $crm] as $table => $id) {
            $admin = $this->userAs('administrator', [self::APPROVALS_LINK => ['view']]);
            $res = $this->getJson("/api/outreach/gate?table={$table}&id={$id}", $admin['h'])->assertOk()->assertJsonPath('status', 1);
            $data = $res->json('data');

            $this->assertSame(['table' => $table, 'id' => $id], $data['message']);
            $this->assertIsBool($data['passed']);
            $this->assertNotNull($data['checked_at']);
            $this->assertSame(['key', 'label', 'passed', 'detail'], array_keys($data['checks'][0]));
            $this->assertSame(['banned_phrase', 'length', 'link_count', 'placeholder', 'unapproved_claim', 'recipient', 'consent', 'suppression', 'contact_status', 'unsubscribe_link'], array_column($data['checks'], 'key'));
            $this->assertSame(10, DB::table('g2g_outreach_gate_checks')->where('message_table', $table)->where('message_id', $id)->count());
        }
    }

    public function test_the_preview_reports_why_a_draft_is_blocked_in_words(): void
    {
        [$gtm] = $this->twoMessages();
        DB::table('gtm_outreach_messages')->where('id', $gtm)->update(['body' => 'Act now, {{first_name}}, to see our pricing.']);

        $data = $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $this->admin['h'])->assertOk()->json('data');

        $this->assertFalse($data['passed']);
        $failed = collect($data['checks'])->where('passed', false)->keyBy('key');
        $this->assertStringContainsString('act now', $failed['banned_phrase']['detail']);
        $this->assertStringContainsString('{{first_name}}', $failed['placeholder']['detail']);
        $this->assertSame('No unfilled merge fields', $failed['placeholder']['label']);
    }

    public function test_a_second_preview_adds_a_set_and_latest_reads_the_newest_without_running_anything(): void
    {
        [$gtm] = $this->twoMessages();
        $url = "/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}";

        $this->assertSame(['passed' => null, 'checks' => [], 'checked_at' => null], array_intersect_key($this->getJson($url . '&latest=1', $this->admin['h'])->assertOk()->json('data'), array_flip(['passed', 'checks', 'checked_at'])), 'never evaluated');
        $this->assertSame(0, DB::table('g2g_outreach_gate_checks')->count(), 'reading the latest set runs and records nothing');

        $first = $this->getJson($url, $this->admin['h'])->assertOk()->json('data');
        DB::table('gtm_outreach_messages')->where('id', $gtm)->update(['body' => 'Act now to book a slot, it takes a minute.']);
        $second = $this->getJson($url, $this->admin['h'])->assertOk()->json('data');
        $this->assertTrue($first['passed']);
        $this->assertFalse($second['passed']);
        $this->assertSame(20, DB::table('g2g_outreach_gate_checks')->count(), 'both sets are kept');

        DB::table('gtm_outreach_messages')->where('id', $gtm)->update(['body' => 'Hello, would a short call next week suit you?']);   // changed again, not re-checked
        $latest = $this->getJson($url . '&latest=1', $this->admin['h'])->assertOk()->json('data');
        $this->assertFalse($latest['passed'], 'the newest RECORDED set, not a new run');
        $this->assertSame($second['checked_at'], $latest['checked_at']);
        $this->assertSame(20, DB::table('g2g_outreach_gate_checks')->count());
    }

    public function test_the_preview_refuses_a_request_that_does_not_say_which_message(): void
    {
        foreach (['', '?table=crm_emails', '?id=1', '?table=tbluser&id=1', '?table=crm_emails&id=0', '?table=crm_emails&id=abc', '?table=crm_emails&id=-3', '?table[]=crm_emails&id=1', '?table=crm_emails&id[]=1'] as $query) {
            $this->getJson('/api/outreach/gate' . $query, $this->admin['h'])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('g2g_outreach_gate_checks')->count());
    }

    public function test_the_preview_never_sends_anything(): void
    {
        [$gtm, $crm] = $this->twoMessages();
        $this->sendingOn();
        $admin = $this->userAs('administrator', [self::APPROVALS_LINK => ['view']]);

        $this->getJson("/api/outreach/gate?table=gtm_outreach_messages&id={$gtm}", $admin['h'])->assertOk();
        $this->getJson("/api/outreach/gate?table=crm_emails&id={$crm}", $admin['h'])->assertOk();

        $this->assertSame(0, $this->captured(), 'with every switch on, a preview of a clean draft still hands nothing to the transport');
        $this->assertSame('approved', DB::table('gtm_outreach_messages')->where('id', $gtm)->value('status'));
        $this->assertSame('Queued', DB::table('crm_emails')->where('id', $crm)->value('status'));
    }
}
