<?php

namespace Tests\Feature;

use App\Domain\Facts\FactWriter;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The cross-organisation sweep of the facts and consent API (slice 4). It does not name the routes it tests: it READS them from the router, so a route
 * added to routes/facts.php later is swept automatically, and one that forgets the tenant filter, the rights or whereNumber fails here without anybody
 * remembering to extend the test (the same design as AgentGatewayTenantSweepTest and OrgAccountsTenantSweepTest).
 *
 *   - every route with an id is called as organisation A with organisation B's record or fact, and must answer 404, the same body as for an id that never
 *     existed, and change nothing of B's;
 *   - every route has the token check and the right of the screen it belongs to, a write needs more than view, and every id is numeric;
 *   - a route WITHOUT an id cannot be swept this way, so each is named below: adding one fails the test until it has been looked at;
 *   - the queue never shows B's facts, and a stray pointer in the data (a decider, evidence, a consent row of the other organisation) shows nothing of B.
 */
class FactsTenantSweepTest extends TestCase
{
    use BuildsFactsWorld;

    private const PREFIXES = ['api/facts', 'api/consent'];

    private const NO_ID_ROUTES = ['GET api/facts/queue'];

    /** @var array{id: int, h: array<string, string>} organisation A's caller: every right the API asks for, so a 404 can only come from the tenant check */
    private array $attacker;

    /** @var array<string, int> kind => organisation B's record */
    private array $victim = [];

    /** @var array<string, int> kind => the id of a fact of organisation B's record */
    private array $victimFact = [];

    private int $ownDeletedContact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
        $this->attacker = $this->editor(self::T);

        $this->victim = ['contact' => $this->makeContact(self::T2, ['email' => 'victim@school.example']), 'organization' => $this->makeOrganization(self::T2, ['name' => 'Victim School']), 'lead' => $this->makeLead(self::T2)];
        $writer = app(FactWriter::class);
        foreach ([['contact', 'phone', '+91 98765 43210'], ['organization', 'website', 'victim.example'], ['lead', 'title', 'Principal']] as [$type, $field, $value]) {
            $this->victimFact[$type] = (int) $writer->propose(self::T2, $type, $this->victim[$type], $field, $value, $this->evidence('site.own-page'), 'm', 'agent:x')['fact']->id;
            DB::table('crm_consent_preferences')->insert(['entity_type' => $type, 'entity_id' => $this->victim[$type], 'channel' => 'sms', 'status' => 'opted_in', 'sub_institute_id' => self::T2]);
        }

        $this->ownDeletedContact = $this->makeContact(self::T);
        DB::table('crm_contacts')->where('id', $this->ownDeletedContact)->update(['deleted_at' => now()]);
        $this->makeContact(self::T);
    }

    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), function (Route $r) {
            foreach (self::PREFIXES as $prefix) {
                if ($r->uri() === $prefix || str_starts_with($r->uri(), $prefix . '/')) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @return list<string> the methods a route answers, without the HEAD that Laravel adds to every GET */
    private function methods(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    }

    /** @return array<string, mixed> everything of organisation B's that a request could change */
    private function victimFootprint(): array
    {
        $rows = fn (string $table) => DB::table($table)->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'facts' => $rows('g2g_entity_facts'), 'evidence' => $rows('g2g_entity_fact_evidence'), 'events' => $rows('g2g_event'), 'contacts' => $rows('crm_contacts'),
            'organizations' => $rows('crm_organizations'), 'leads' => $rows('crm_leads'), 'consent' => $rows('crm_consent_preferences'),
        ];
    }

    /** What B's id stands for on this route: a record of a kind, or a fact. */
    private function victimIdFor(Route $route): int
    {
        if (preg_match('#/(contact|organization|lead)/\{id\}$#', $route->uri(), $m)) {
            return $this->victim[$m[1]];
        }
        if (preg_match('#/\{id\}/(accept|dismiss)$#', $route->uri())) {
            return $this->victimFact['contact'];
        }
        $this->fail("the sweep does not know what id route {$route->uri()} takes: say so in victimIdFor()");
    }

    public function test_every_id_route_answers_another_organisations_record_with_a_404_that_reveals_nothing_and_changes_nothing(): void
    {
        $before = $this->victimFootprint();
        $body = ['reason' => 'Hijack', 'sub_institute_id' => self::T2, 'user_id' => $this->attacker['id']];

        $swept = [];
        foreach ($this->routes() as $route) {
            if (! str_contains($route->uri(), '{')) {
                continue;
            }
            foreach ($this->methods($route) as $method) {
                $url = fn (int $id) => '/' . str_replace('{id}', (string) $id, $route->uri());
                $none = $this->json($method, $url(999999), $body, $this->attacker['h'])->assertStatus(404);
                $ids = ["B's own id" => $this->victimIdFor($route)];
                if (str_contains($route->uri(), '/contact/')) {
                    $ids["the caller's own removed contact"] = $this->ownDeletedContact;
                }
                foreach ($ids as $what => $id) {
                    $res = $this->json($method, $url($id), $body, $this->attacker['h']);
                    $this->assertSame(404, $res->getStatusCode(), "{$method} {$route->uri()} with {$what}");
                    $this->assertSame($none->json(), $res->json(), "{$method} {$route->uri()}: the answer is the one for an id that never existed");
                }
                $swept[] = "{$method} {$route->uri()}";
            }
        }

        $this->assertEqualsCanonicalizing([
            'GET api/facts/entity/contact/{id}', 'GET api/facts/entity/organization/{id}', 'GET api/facts/entity/lead/{id}', 'POST api/facts/{id}/accept', 'POST api/facts/{id}/dismiss',
            'GET api/consent/contact/{id}', 'GET api/consent/organization/{id}', 'GET api/consent/lead/{id}',
        ], $swept, 'the sweep found the id routes (a new one is swept automatically, and this list is then updated on purpose)');
        $this->assertSame($before, $this->victimFootprint(), "nothing of organisation B's changed");
    }

    public function test_the_caller_cannot_choose_the_organisation_with_a_parameter_or_the_body(): void
    {
        $before = $this->victimFootprint();
        $h = $this->attacker['h'];
        $in = ['sub_institute_id' => self::T2];

        $this->json('GET', "/api/facts/entity/contact/{$this->victim['contact']}?sub_institute_id=" . self::T2, [], $h)->assertStatus(404);
        $this->json('GET', "/api/consent/contact/{$this->victim['contact']}?sub_institute_id=" . self::T2, $in, $h)->assertStatus(404);
        $this->json('POST', "/api/facts/{$this->victimFact['contact']}/accept?sub_institute_id=" . self::T2, $in, $h)->assertStatus(404);
        $this->json('POST', "/api/facts/{$this->victimFact['contact']}/dismiss", $in + ['reason' => 'x'], $h)->assertStatus(404);
        $this->assertSame($before, $this->victimFootprint());

        $queue = $this->json('GET', '/api/facts/queue?sub_institute_id=' . self::T2, $in, $h)->assertOk();
        $this->assertSame(0, $queue->json('data.pagination.total'), "asking for organisation B's queue gives A's own (empty)");
    }

    public function test_the_queue_never_shows_another_organisations_facts_whatever_the_request_says(): void
    {
        $mine = $this->makeContact(self::T);
        $own = (int) app(FactWriter::class)->propose(self::T, 'contact', $mine, 'email', 'mine@school.example', $this->evidence('site.own-page'), 'm', 'agent:x')['fact']->id;

        foreach (['', '?sub_institute_id=' . self::T2, '?type=contact', '?type=organization', '?status=proposed&per_page=50', '?page=1&per_page=1'] as $query) {
            $res = $this->json('GET', "/api/facts/queue{$query}", ['sub_institute_id' => self::T2], $this->attacker['h'])->assertOk();
            $ids = array_column($res->json('data.rows'), 'id');
            foreach ($this->victimFact as $type => $id) {
                $this->assertNotContains($id, $ids, "queue{$query}: B's {$type} fact is not listed");
            }
            $this->assertStringNotContainsString('Victim School', json_encode($res->json()), "queue{$query}: nor B's organisation's name");
        }
        $this->assertSame([$own], array_column($this->getJson('/api/facts/queue', $this->attacker['h'])->json('data.rows'), 'id'));
    }

    /**
     * Ids are unique across organisations, so rows of one organisation never point at another's in normal use. This is the worst case, a pointer that was ever
     * mis-set (a bad import, a hand edit): every lookup the API makes carries the caller's organisation, so nothing of the other organisation shows through it.
     */
    public function test_rows_of_another_organisation_that_point_into_this_ones_ids_show_nothing_of_themselves(): void
    {
        $h = $this->attacker['h'];
        $mine = $this->makeContact(self::T, ['email' => 'mine@school.example']);
        $fact = (int) app(FactWriter::class)->propose(self::T, 'contact', $mine, 'phone', '+91 98765 43210', $this->evidence('site.own-page'), 'm', 'agent:x')['fact']->id;
        $stranger = $this->user([], self::T2, 'Mallory', 'Villain');
        // 1. a decision whose decider is a person of the OTHER organisation
        DB::table('g2g_entity_facts')->where('id', $fact)->update(['status' => 'dismissed', 'decided_by' => $stranger['id'], 'decided_at' => now()]);
        // 2. evidence of the other organisation, pointing at this organisation's fact
        DB::table('g2g_entity_fact_evidence')->insert(['sub_institute_id' => self::T2, 'fact_id' => $fact, 'kind' => 'profile.email-match', 'weight' => 0.95, 'quote' => 'Victim secret quote', 'created_at' => now()]);
        // 3. a consent preference and a suppression of the other organisation that name this organisation's contact and address
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $mine, 'channel' => 'email', 'status' => 'opted_out', 'sub_institute_id' => self::T2]);
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T2, 'kind' => 'email', 'value' => 'mine@school.example', 'reason' => 'complaint', 'created_at' => now()]);
        DB::table('gtm_contacts')->insert(['sub_institute_id' => self::T2, 'account_id' => 1, 'full_name' => 'Elsewhere', 'email' => 'mine@school.example', 'status' => 'unsubscribed', 'created_at' => now()]);

        $facts = $this->getJson("/api/facts/entity/contact/{$mine}", $h)->assertOk();
        $consent = $this->getJson("/api/consent/contact/{$mine}", $h)->assertOk();

        $this->assertNull($facts->json('data.facts.0.decided_by_name'), 'a person of the other organisation is not named, whatever the pointer says');
        $this->assertSame(['site.own-page'], array_column($facts->json('data.facts.0.evidence'), 'kind'), "only this organisation's own evidence is shown on this fact, not the other organisation's row that points at it");
        $this->assertTrue($consent->json('data.channels.email.allowed'), "the other organisation's preference, suppression and outreach contact do not block this organisation's contact");
        $this->assertSame([], $consent->json('data.sources'));
        $everything = json_encode($facts->json()) . json_encode($consent->json());
        foreach (['Mallory', 'Villain', 'Victim secret quote', 'Elsewhere'] as $trace) {
            $this->assertStringNotContainsString($trace, $everything, "no trace of '{$trace}'");
        }
    }

    public function test_every_route_carries_the_token_check_and_the_right_of_its_screen_and_every_id_is_numeric(): void
    {
        $contacts = 'platformright:/module/crm/marketing/contacts|/module/crm/support/contacts,';
        $orgs = 'platformright:/module/crm/marketing/organizations|/module/crm/support/organizations,';
        $leads = 'platformright:/module/crm/marketing/leads,';
        $any = 'platformright:/module/crm/marketing/contacts|/module/crm/support/contacts|/module/crm/marketing/organizations|/module/crm/support/organizations|/module/crm/marketing/leads,';
        $expected = [
            'GET api/facts/entity/contact/{id}' => $contacts . 'view', 'GET api/facts/entity/organization/{id}' => $orgs . 'view', 'GET api/facts/entity/lead/{id}' => $leads . 'view',
            'GET api/consent/contact/{id}' => $contacts . 'view', 'GET api/consent/organization/{id}' => $orgs . 'view', 'GET api/consent/lead/{id}' => $leads . 'view',
            'GET api/facts/queue' => $any . 'view', 'POST api/facts/{id}/accept' => $any . 'edit', 'POST api/facts/{id}/dismiss' => $any . 'edit',
        ];

        $seen = [];
        foreach ($this->routes() as $route) {
            $label = implode('|', $this->methods($route)) . ' ' . $route->uri();
            $middleware = $route->gatherMiddleware();
            $this->assertContains('api.token', $middleware, "{$label} needs a token");
            $rights = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'platformright:')));
            $this->assertCount(1, $rights, "{$label} needs exactly one menu right");
            $this->assertSame($expected[$label] ?? null, $rights[0], "{$label}: the right of the wrong screen, or a route nobody listed");
            $action = substr($rights[0], (int) strrpos($rights[0], ',') + 1);
            $this->assertSame($this->methods($route) === ['GET'] ? 'view' : 'edit', $action, "{$label}: a write needs edit, a read needs view");
            foreach ($route->parameterNames() as $name) {
                $this->assertSame('[0-9]+', $route->wheres[$name] ?? null, "{$label}: {{$name}} must be whereNumber");
            }
            $seen[] = $label;
        }
        $this->assertEqualsCanonicalizing(array_keys($expected), $seen);
        foreach ($seen as $label) {
            $this->assertStringNotContainsString('propose', $label, 'there is no HTTP route that proposes a fact: that is the agent tool, through FactWriter');
        }
    }

    public function test_a_route_without_an_id_is_named_in_this_test_so_a_new_one_is_noticed(): void
    {
        $found = [];
        foreach ($this->routes() as $route) {
            if (! str_contains($route->uri(), '{')) {
                foreach ($this->methods($route) as $method) {
                    $found[] = "{$method} {$route->uri()}";
                }
            }
        }

        $this->assertEqualsCanonicalizing(self::NO_ID_ROUTES, $found, 'a route with no id cannot be swept by id: check its tenant filtering by hand, then name it in NO_ID_ROUTES');
    }
}
