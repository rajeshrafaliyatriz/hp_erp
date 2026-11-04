<?php

namespace Tests\Feature;

use App\Domain\Facts\FactWriter;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The cross-organisation sweep of the Leads API (slice 6, acceptance 6), modelled on slice 4's FactsTenantSweepTest. It does not name the routes it
 * tests: it READS every route under api/crm/leads from the router, so a route added later is swept without anybody remembering to extend the test, and the
 * list below that names today's routes fails until a person has looked at the new one.
 *
 *   - every route with an id is called as organisation A with organisation B's record and must answer 404, the same body as for an id that never existed,
 *     and change nothing of B's;
 *   - a route without an id is read for traces of B (the list and its new filters, the duplicates list, the export) or given B's ids in its body (merge,
 *     bulk delete, bulk assign) and must change nothing;
 *   - every route carries the token check and exactly the right of the Leads screen it always had (pinned), every id is numeric, and a caller without the
 *     right (none at all, or view only on a write) is answered 403 before anything is read.
 */
class LeadsTenantSweepTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const SECRET = 'Victim Secret Company';

    /** @var array{id: int, h: array<string, string>} organisation A's caller: every right on the Leads screen, so a 404 can only come from the tenant check */
    private array $attacker;

    /** @var array<string, int> kind => organisation B's record */
    private array $victim = [];

    /** the id routes that exist today, named on purpose */
    private const ID_ROUTES = [
        'GET api/crm/leads/{id}', 'PUT api/crm/leads/{id}', 'DELETE api/crm/leads/{id}', 'POST api/crm/leads/{id}/convert',
        'GET api/crm/leads/{id}/comments', 'POST api/crm/leads/{id}/comments', 'GET api/crm/leads/{id}/documents', 'POST api/crm/leads/{id}/documents',
        'GET api/crm/leads/{id}/emails', 'GET api/crm/leads/{id}/history',
        'PUT api/crm/leads/scoring-rules/{id}', 'DELETE api/crm/leads/scoring-rules/{id}', 'DELETE api/crm/leads/assignment-settings/members/{id}',
    ];

    /** @var array<string, string> every route and the right it asks for: today's, pinned */
    private const RIGHTS = [
        'GET api/crm/leads' => 'view', 'POST api/crm/leads' => 'add', 'GET api/crm/leads/{id}' => 'view', 'PUT api/crm/leads/{id}' => 'edit', 'DELETE api/crm/leads/{id}' => 'delete',
        'POST api/crm/leads/{id}/convert' => 'edit', 'POST api/crm/leads/bulk/delete' => 'delete', 'POST api/crm/leads/bulk/assign' => 'edit', 'GET api/crm/leads/duplicates' => 'view',
        'POST api/crm/leads/merge' => 'delete', 'GET api/crm/leads/export' => 'view', 'POST api/crm/leads/import' => 'add',
        'GET api/crm/leads/{id}/comments' => 'view', 'POST api/crm/leads/{id}/comments' => 'edit', 'GET api/crm/leads/{id}/documents' => 'view', 'POST api/crm/leads/{id}/documents' => 'edit',
        'GET api/crm/leads/{id}/emails' => 'view', 'GET api/crm/leads/{id}/history' => 'view',
        'GET api/crm/leads/scoring-rules' => 'view', 'POST api/crm/leads/scoring-rules' => 'edit', 'PUT api/crm/leads/scoring-rules/{id}' => 'edit', 'DELETE api/crm/leads/scoring-rules/{id}' => 'edit',
        'GET api/crm/leads/assignment-settings' => 'view', 'PUT api/crm/leads/assignment-settings' => 'edit', 'POST api/crm/leads/assignment-settings/members' => 'edit',
        'DELETE api/crm/leads/assignment-settings/members/{id}' => 'edit',
        'GET api/crm/leads/capture-settings' => 'view', 'PUT api/crm/leads/capture-settings' => 'edit', 'POST api/crm/leads/capture-settings/regenerate-key' => 'edit',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        $this->attacker = $this->leadsUser(self::T);

        $product = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T2, 'kind' => 'product', 'product_key' => 'theirs', 'name' => 'Their Product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $account = (int) DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => self::T2, 'name' => self::SECRET, 'normalized_name' => 'victim', 'lifecycle' => 'discovered', 'created_at' => now(), 'updated_at' => now()]);
        $signal = $this->makeSignal(self::T2, ['title' => 'Victim private signal']);
        $victimUser = $this->leadsUser(self::T2);
        $lead = $this->makeLead(self::T2, ['last_name' => self::SECRET, 'company' => self::SECRET, 'email' => 'victim@secret.example', 'assigned_to' => $victimUser['id'], 'email_opt_out' => 1,
            'product_master_id' => $product, 'org_account_id' => $account, 'source_company_opportunity_id' => $signal->id]);
        DB::table('crm_lead_comments')->insert(['lead_id' => $lead, 'sub_institute_id' => self::T2, 'comment' => 'Victim secret note', 'created_by' => $victimUser['id'], 'created_at' => now(), 'updated_at' => now()]);
        $rule = (int) DB::table('crm_lead_scoring_rules')->insertGetId(['sub_institute_id' => self::T2, 'field_key' => 'industry', 'operator' => 'equals', 'value' => 'victim', 'points' => 50, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $member = (int) DB::table('crm_lead_assignment_rotation_members')->insertGetId(['sub_institute_id' => self::T2, 'user_id' => $victimUser['id'], 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(FactWriter::class)->propose(self::T2, 'lead', $lead, 'phone', '+91 98765 43210', $this->evidence('site.own-page'), 'm', 'agent:x');
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'lead', 'entity_id' => $lead, 'channel' => 'sms', 'status' => 'opted_in', 'sub_institute_id' => self::T2]);
        $this->victim = ['lead' => $lead, 'rule' => $rule, 'member' => $member, 'product' => $product, 'account' => $account, 'signal' => (int) $signal->id];

        // And some of A's own, so a list is not trivially empty.
        $this->makeLead(self::T, ['last_name' => 'Mine', 'company' => 'My School', 'assigned_to' => $this->attacker['id']]);
    }

    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn (Route $r) => $r->uri() === 'api/crm/leads' || str_starts_with($r->uri(), 'api/crm/leads/')));
    }

    /** @return list<string> the methods a route answers, without the HEAD that Laravel adds to every GET */
    private function methods(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    }

    /** @return array<string, mixed> everything of organisation B's that a request could change or reveal */
    private function victimFootprint(): array
    {
        $rows = fn (string $table) => DB::table($table)->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'leads' => $rows('crm_leads'), 'comments' => $rows('crm_lead_comments'), 'rules' => $rows('crm_lead_scoring_rules'), 'members' => $rows('crm_lead_assignment_rotation_members'),
            'facts' => $rows('g2g_entity_facts'), 'consent' => $rows('crm_consent_preferences'), 'organizations' => $rows('crm_organizations'), 'contacts' => $rows('crm_contacts'),
            'opportunities' => $rows('crm_opportunities'), 'events' => $rows('g2g_event'), 'identities' => $rows('g2g_org_accounts'), 'products' => $rows('g2g_product_master'),
        ];
    }

    private function victimIdFor(Route $route): int
    {
        if (str_contains($route->uri(), 'scoring-rules/')) {
            return $this->victim['rule'];
        }
        if (str_contains($route->uri(), 'rotation-members') || str_contains($route->uri(), 'members/')) {
            return $this->victim['member'];
        }

        return $this->victim['lead'];
    }

    public function test_every_id_route_answers_another_organisations_record_with_a_404_that_reveals_nothing_and_changes_nothing(): void
    {
        $before = $this->victimFootprint();
        $body = [
            'comment' => 'Hijack', 'lastName' => 'Hijacked', 'createOrganization' => true, 'createContact' => true, 'createOpportunity' => true, 'assignedTo' => $this->attacker['id'],
            'fieldKey' => 'industry', 'operator' => 'equals', 'value' => 'x', 'points' => 1, 'sub_institute_id' => self::T2, 'productMasterId' => $this->victim['product'], 'org_account_id' => $this->victim['account'],
        ];

        $swept = [];
        foreach ($this->routes() as $route) {
            if (! str_contains($route->uri(), '{id}')) {
                continue;
            }
            foreach ($this->methods($route) as $method) {
                $url = fn (int $id) => '/' . str_replace('{id}', (string) $id, $route->uri());
                $none = $this->json($method, $url(999999), $body, $this->attacker['h']);
                $this->assertSame(404, $none->getStatusCode(), "{$method} {$route->uri()} with an id that never existed");
                $res = $this->json($method, $url($this->victimIdFor($route)), $body, $this->attacker['h']);
                $this->assertSame(404, $res->getStatusCode(), "{$method} {$route->uri()} with B's own id");
                $this->assertSame($none->json(), $res->json(), "{$method} {$route->uri()}: the answer is the one for an id that never existed");
                $swept[] = "{$method} {$route->uri()}";
            }
        }

        $this->assertEqualsCanonicalizing(self::ID_ROUTES, $swept, 'the sweep found the id routes (a new one is swept automatically, and this list is then updated on purpose)');
        $this->assertSame($before, $this->victimFootprint(), "nothing of organisation B's changed");
    }

    public function test_the_caller_cannot_choose_the_organisation_with_a_parameter_or_the_body(): void
    {
        $before = $this->victimFootprint();
        $h = $this->attacker['h'];
        $in = ['sub_institute_id' => self::T2, 'subInstituteId' => self::T2];

        $this->json('GET', '/api/crm/leads/' . $this->victim['lead'] . '?sub_institute_id=' . self::T2, $in, $h)->assertStatus(404);
        $list = $this->json('GET', '/api/crm/leads?sub_institute_id=' . self::T2, $in, $h)->assertOk();
        $this->assertSame(['Mine'], array_column($list->json('data.items'), 'lastName'), 'asking for organisation B\'s list gives A\'s own');
        $created = $this->json('POST', '/api/crm/leads', $in + ['lastName' => 'Planted', 'assignedTo' => $this->attacker['id']], $h)->assertCreated();
        $this->assertSame(self::T, (int) DB::table('crm_leads')->where('id', $created->json('data.id'))->value('sub_institute_id'), 'a lead is always made in the caller\'s own organisation');
        $this->assertSame($before, $this->victimFootprint());
    }

    public function test_no_listing_read_or_body_id_reaches_another_organisations_leads(): void
    {
        $before = $this->victimFootprint();
        $h = $this->attacker['h'];
        $mine = (int) DB::table('crm_leads')->where('sub_institute_id', self::T)->value('id');

        // The list, with every new filter and with a search for the victim's own name.
        foreach (['', '?product_id=' . $this->victim['product'], '?has_signal=1', '?consent=blocked', '?consent=allowed', '?search=Victim', '?search=victim@secret.example', '?product_id=' . $this->victim['product'] . '&has_signal=1&consent=blocked', '?assigned_to=' . $this->victim['lead']] as $query) {
            $res = $this->getJson("/api/crm/leads{$query}", $h)->assertOk();
            $this->assertStringNotContainsString('Victim', json_encode($res->json()), "list{$query}");
            $this->assertStringNotContainsString('victim@secret.example', json_encode($res->json()), "list{$query}");
        }
        $this->assertSame([], array_column($this->getJson('/api/crm/leads?product_id=' . $this->victim['product'], $h)->json('data.items'), 'id'));
        $this->assertSame(0, $this->getJson('/api/crm/leads?has_signal=1', $h)->json('data.pagination.total'), 'the other organisation\'s signal-born lead is not counted');

        // The reads without an id.
        foreach (['/api/crm/leads/duplicates', '/api/crm/leads/export', '/api/crm/leads/scoring-rules', '/api/crm/leads/assignment-settings'] as $url) {
            $res = $this->get($url, $h)->assertOk();
            $content = $res->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $res->streamedContent() : $res->getContent();
            $this->assertNotSame('', trim((string) $content), "{$url} answered something");
            $this->assertStringNotContainsString('Victim', (string) $content, $url);
            $this->assertStringNotContainsString('victim@secret.example', (string) $content, $url);
        }

        // The writes that take ids in the body.
        $this->postJson('/api/crm/leads/bulk/delete', ['ids' => [$this->victim['lead']]], $h);
        $this->postJson('/api/crm/leads/bulk/assign', ['ids' => [$this->victim['lead']], 'assignedTo' => $this->attacker['id']], $h);
        $this->postJson('/api/crm/leads/merge', ['survivorId' => $mine, 'duplicateIds' => [$this->victim['lead']]], $h);
        $this->postJson('/api/crm/leads/merge', ['survivorId' => $this->victim['lead'], 'duplicateIds' => [$mine]], $h)->assertNotFound();

        $this->assertSame($before, $this->victimFootprint(), 'not one row of organisation B changed');
        $this->assertNull(DB::table('crm_leads')->where('id', $mine)->value('deleted_at'), 'and A\'s own lead was not merged away either');
    }

    public function test_the_detail_never_reads_another_organisations_signal_or_gate_through_a_planted_pointer(): void
    {
        $mine = (int) DB::table('crm_leads')->where('sub_institute_id', self::T)->value('id');
        DB::table('crm_leads')->where('id', $mine)->update(['source_company_opportunity_id' => $this->victim['signal'], 'product_master_id' => $this->victim['product'], 'org_account_id' => $this->victim['account']]);

        $detail = $this->getJson("/api/crm/leads/{$mine}", $this->attacker['h'])->assertOk()->json('data');
        $list = $this->getJson('/api/crm/leads', $this->attacker['h'])->assertOk();

        foreach ([$detail, $list->json()] as $payload) {
            $text = json_encode($payload);
            $this->assertStringNotContainsString('Victim', $text);
            $this->assertStringNotContainsString('Their Product', $text);
            $this->assertStringNotContainsString(self::SECRET, $text);
        }
        $this->assertNull($detail['source_signal']);
        $this->assertNull($detail['product']);
        $this->assertNull($detail['organisation_account']);
    }

    public function test_every_route_carries_the_token_check_the_right_it_always_had_and_a_numeric_id(): void
    {
        $seen = [];
        foreach ($this->routes() as $route) {
            foreach ($this->methods($route) as $method) {
                $label = "{$method} {$route->uri()}";
                $rights = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'menuright:')));
                $this->assertCount(1, $rights, "{$label} needs exactly one menu right");
                $this->assertSame('menuright:201,' . (self::RIGHTS[$label] ?? '?'), $rights[0], "{$label}: the right of the wrong action, or a route nobody listed");
                foreach ($route->parameterNames() as $name) {
                    $this->assertSame('[0-9]+', $route->wheres[$name] ?? null, "{$label}: {{$name}} must be whereNumber");
                }
                $seen[] = $label;
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(self::RIGHTS), $seen, 'a lead route nobody listed, or a listed one that is gone');
    }

    public function test_a_caller_with_no_token_is_refused_by_every_route(): void
    {
        $mine = (int) DB::table('crm_leads')->where('sub_institute_id', self::T)->value('id');
        $before = DB::table('crm_leads')->count();

        foreach ($this->routes() as $route) {
            foreach ($this->methods($route) as $method) {
                $status = $this->json($method, '/' . str_replace('{id}', (string) $mine, $route->uri()), ['lastName' => 'X', 'ids' => [$mine]])->getStatusCode();
                $this->assertContains($status, [401, 403], "{$method} {$route->uri()} answered {$status} to a caller with no token");
            }
        }
        $this->assertSame($before, DB::table('crm_leads')->count());
    }

    public function test_a_caller_without_the_right_is_answered_403_and_nothing_is_read_or_written(): void
    {
        $none = $this->leadsUser(self::T, [], false);
        $viewOnly = $this->leadsUser(self::T, ['view'], false);
        $mine = (int) DB::table('crm_leads')->where('sub_institute_id', self::T)->value('id');
        $before = $this->victimFootprint() + ['mine' => DB::table('crm_leads')->where('sub_institute_id', self::T)->get()->map(fn ($r) => (array) $r)->all()];
        $body = ['lastName' => 'X', 'comment' => 'x', 'assignedTo' => $none['id'], 'ids' => [$mine], 'survivorId' => $mine, 'duplicateIds' => [$mine + 1], 'rows' => [['lastName' => 'Y', 'assignedTo' => 1]],
            'createOrganization' => true, 'createContact' => true, 'fieldKey' => 'industry', 'operator' => 'equals', 'value' => 'x', 'points' => 1];

        foreach ($this->routes() as $route) {
            foreach ($this->methods($route) as $method) {
                $label = "{$method} {$route->uri()}";
                $url = '/' . str_replace('{id}', (string) $mine, $route->uri());
                $this->assertSame(403, $this->json($method, $url, $body, $none['h'])->getStatusCode(), "{$label} with no right at all");
                if (self::RIGHTS[$label] !== 'view') {
                    $this->assertSame(403, $this->json($method, $url, $body, $viewOnly['h'])->getStatusCode(), "{$label} with only the right to view");
                }
            }
        }

        $this->assertSame($before, $this->victimFootprint() + ['mine' => DB::table('crm_leads')->where('sub_institute_id', self::T)->get()->map(fn ($r) => (array) $r)->all()], 'a refused call changed nothing');
    }
}
