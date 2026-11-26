<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsContactWorld;
use Tests\TestCase;

/**
 * "One company page that holds its people" on the backend (S18, contract section 2): `include=people` on the organisation's e-mails, documents, comments, history, schedule and quotes returns the
 * rows of the live people of that company as well, each tagged personId / personName (null for a company row); the Organizations list carries peopleCount, primaryContactName and finds a company
 * through its people (matchedPerson). Pinned here: the extra rows, the default answer unchanged (also with include=company), a person of another company / a deleted person / another
 * organisation's rows never appear, a row linked to both company and person is listed once as the company's, and the number of queries does not depend on how many people the company has.
 */
class CrmOrganizationPeopleIncludeTest extends TestCase
{
    use BuildsContactWorld;

    private int $acme;

    private int $beta;

    private int $priya;

    private int $ravi;

    private int $outsider;

    private int $gone;

    /** @var array<string, string> */
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildContactWorld();
        $this->acme = $this->makeOrganization(self::T, ['name' => 'Acme School']);
        $this->beta = $this->makeOrganization(self::T, ['name' => 'Beta College']);
        $this->priya = $this->makeContact(self::T, ['organization_id' => $this->acme, 'first_name' => 'Priya', 'last_name' => 'Shah', 'email' => 'priya@acme.example', 'is_primary' => 1]);
        $this->ravi = $this->makeContact(self::T, ['organization_id' => $this->acme, 'first_name' => 'Ravi', 'last_name' => 'Iyer']);
        $this->outsider = $this->makeContact(self::T, ['organization_id' => $this->beta, 'first_name' => 'Olga', 'last_name' => 'Beta']);
        $this->gone = $this->makeContact(self::T, ['organization_id' => $this->acme, 'first_name' => 'Gone', 'last_name' => 'Person', 'deleted_at' => now()]);
        $this->h = $this->crmUser()['h'];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function email(string $subject, array $links, int $tenant = self::T): int
    {
        $id = DB::table('crm_emails')->insertGetId(['subject' => $subject, 'body' => 'b', 'to_address' => 'x@y.example', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($links as [$type, $linked]) {
            DB::table('crm_email_links')->insert(['email_id' => $id, 'linked_type' => $type, 'linked_id' => $linked, 'sub_institute_id' => $tenant]);
        }

        return $id;
    }

    private function document(string $title, array $links, int $tenant = self::T): int
    {
        $id = DB::table('document_library')->insertGetId(['sub_institute_id' => $tenant, 'title' => $title, 'original_file_name' => $title . '.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'created_at' => now()]);
        foreach ($links as [$type, $linked]) {
            DB::table('document_library_links')->insert(['document_id' => $id, 'linked_type' => $type, 'linked_id' => $linked, 'sub_institute_id' => $tenant, 'created_at' => now()]);
        }

        return $id;
    }

    private function event(string $title, string $type, int $linked, int $tenant = self::T): int
    {
        return DB::table('task_management_calendar_events')->insertGetId(['sub_institute_id' => $tenant, 'syear' => '2026', 'title' => $title, 'start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 11:00:00',
            'owner_id' => 1, 'created_by' => 1, 'linked_type' => $type, 'linked_id' => $linked, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function audit(string $type, string $entityType, int $entity, int $tenant = self::T): int
    {
        $event = DB::table('g2g_event')->insertGetId(['event_uuid' => uniqid('', true), 'type' => $type, 'sub_institute_id' => $tenant, 'entity_type' => $entityType, 'entity_id' => $entity, 'payload' => '{}',
            'occurred_at' => now()->format('Y-m-d H:i:s.v'), 'recorded_at' => now()->format('Y-m-d H:i:s.v')]);

        return DB::table('g2g_audit_log')->insertGetId(['event_id' => $event, 'sub_institute_id' => $tenant, 'type' => $type, 'entity_type' => $entityType, 'entity_id' => $entity, 'detail' => '{"changes":[]}',
            'occurred_at' => now()->format('Y-m-d H:i:s.v')]);
    }

    /** @param list<array<string, mixed>> $rows @return list<array{0: ?string, 1: mixed}> personId and a label per row */
    private function tags(array $rows, string $label = 'subject'): array
    {
        $out = array_map(fn ($r) => [array_key_exists('personId', $r) ? $r['personId'] : 'MISSING', $r[$label] ?? $r['title'] ?? $r['comment'] ?? $r['type'] ?? null], $rows);
        usort($out, fn ($a, $b) => strcmp((string) $a[1], (string) $b[1]));

        return $out;
    }

    // ── e-mails, documents, comments, history ────────────────────────────────

    public function test_the_emails_of_the_people_come_with_the_companys_each_tagged_and_a_shared_one_is_listed_once(): void
    {
        $this->email('company mail', [['ORGANIZATION', $this->acme]]);
        $this->email('priya mail', [['CONTACT', $this->priya]]);
        $this->email('shared mail', [['ORGANIZATION', $this->acme], ['CONTACT', $this->ravi]]);
        $this->email('olga mail', [['CONTACT', $this->outsider]]);
        $this->email('gone mail', [['CONTACT', $this->gone]]);
        $this->email('beta mail', [['ORGANIZATION', $this->beta]]);
        $this->email('their mail', [['CONTACT', $this->priya]], self::T2);     // another organisation's link that carries the same contact id

        $default = $this->getJson("/api/crm/organizations/{$this->acme}/emails", $this->h)->assertOk()->json('data');
        $this->assertSame(['company mail', 'shared mail'], collect($default)->pluck('subject')->sort()->values()->all(), 'the default answer is the company\'s own rows');
        $this->assertArrayNotHasKey('personId', $default[0], 'and carries no tag');
        $this->assertSame($default, $this->getJson("/api/crm/organizations/{$this->acme}/emails?include=company", $this->h)->json('data'), 'include=company is the default');

        $rows = $this->getJson("/api/crm/organizations/{$this->acme}/emails?include=people", $this->h)->assertOk()->json('data');
        $this->assertSame([[null, 'company mail'], [(string) $this->priya, 'priya mail'], [null, 'shared mail']], $this->tags($rows));
        $priyaRow = collect($rows)->firstWhere('subject', 'priya mail');
        $this->assertSame('Priya Shah', $priyaRow['personName']);
    }

    public function test_the_documents_of_the_people_come_with_the_companys(): void
    {
        $this->document('company doc', [['ORGANIZATION', $this->acme]]);
        $this->document('ravi doc', [['CONTACT', $this->ravi]]);
        $this->document('shared doc', [['CONTACT', $this->priya], ['ORGANIZATION', $this->acme]]);
        $this->document('olga doc', [['CONTACT', $this->outsider]]);
        $this->document('their doc', [['CONTACT', $this->ravi]], self::T2);

        $this->assertSame(['company doc', 'shared doc'], collect($this->getJson("/api/crm/organizations/{$this->acme}/documents", $this->h)->json('data'))->pluck('title')->sort()->values()->all());
        $rows = $this->getJson("/api/crm/organizations/{$this->acme}/documents?include=people", $this->h)->assertOk()->json('data');
        $this->assertSame([[null, 'company doc'], [(string) $this->ravi, 'ravi doc'], [null, 'shared doc']], $this->tags($rows, 'title'));
        $this->assertSame('Ravi Iyer', collect($rows)->firstWhere('title', 'ravi doc')['personName']);
    }

    public function test_the_comments_of_the_people_come_with_the_companys_oldest_first(): void
    {
        DB::table('crm_organization_comments')->insert(['organization_id' => $this->acme, 'sub_institute_id' => self::T, 'comment' => 'about the company', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        DB::table('crm_contact_comments')->insert([
            ['contact_id' => $this->priya, 'sub_institute_id' => self::T, 'comment' => 'about Priya', 'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:00:00'],
            ['contact_id' => $this->outsider, 'sub_institute_id' => self::T, 'comment' => 'about Olga', 'created_at' => '2026-11-03 10:00:00', 'updated_at' => '2026-11-03 10:00:00'],
            ['contact_id' => $this->gone, 'sub_institute_id' => self::T, 'comment' => 'about the deleted one', 'created_at' => '2026-11-04 10:00:00', 'updated_at' => '2026-11-04 10:00:00'],
            ['contact_id' => $this->priya, 'sub_institute_id' => self::T2, 'comment' => 'their note on the same id', 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00'],
        ]);

        $this->assertCount(1, $this->getJson("/api/crm/organizations/{$this->acme}/comments", $this->h)->json('data'));
        $rows = $this->getJson("/api/crm/organizations/{$this->acme}/comments?include=people", $this->h)->assertOk()->json('data');
        $this->assertSame([['about the company', null], ['about Priya', (string) $this->priya]], array_map(fn ($r) => [$r['comment'], $r['personId']], $rows));
        $this->assertSame('Priya Shah', $rows[1]['personName']);
    }

    public function test_the_history_of_the_people_comes_with_the_companys(): void
    {
        $this->audit('crm.organization.updated', 'crm_organizations', $this->acme);
        $this->audit('crm.contact.updated', 'crm_contacts', $this->priya);
        $this->audit('crm.contact.updated', 'crm_contacts', $this->outsider);
        $this->audit('crm.contact.updated', 'crm_contacts', $this->gone);
        $this->audit('crm.contact.updated', 'crm_contacts', $this->priya, self::T2);

        $this->assertCount(1, $this->getJson("/api/crm/organizations/{$this->acme}/history", $this->h)->json('data'));
        $rows = $this->getJson("/api/crm/organizations/{$this->acme}/history?include=people", $this->h)->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([[null, 'crm.organization.updated'], [(string) $this->priya, 'crm.contact.updated']], array_map(fn ($r) => [$r['personId'], $r['type']], $rows));
    }

    // ── schedule and quotes ──────────────────────────────────────────────────

    public function test_the_schedule_of_the_people_comes_with_the_companys(): void
    {
        $this->event('company meeting', 'ORGANIZATION', $this->acme);
        $this->event('call with Priya', 'CONTACT', $this->priya);
        $this->event('call with Olga', 'CONTACT', $this->outsider);
        $this->event('call with the deleted one', 'CONTACT', $this->gone);
        $this->event('their call', 'CONTACT', $this->priya, self::T2);
        $url = "/api/task-management/calendar/events/linked/ORGANIZATION/{$this->acme}?syear=2026";

        $default = $this->getJson($url, $this->h)->assertOk()->json('data.entries');
        $this->assertSame(['company meeting'], array_column($default, 'title'));
        $this->assertArrayNotHasKey('personId', $default[0]);

        $rows = $this->getJson($url . '&include=people', $this->h)->assertOk()->json('data.entries');
        $this->assertSame([[(string) $this->priya, 'call with Priya'], [null, 'company meeting']], $this->tags($rows, 'title'));
        $this->assertSame('Priya Shah', collect($rows)->firstWhere('title', 'call with Priya')['personName']);

        // a person's own schedule is not widened by the parameter
        $own = $this->getJson("/api/task-management/calendar/events/linked/CONTACT/{$this->priya}?syear=2026&include=people", $this->h)->assertOk()->json('data.entries');
        $this->assertSame(['call with Priya'], array_column($own, 'title'));
        $this->assertArrayNotHasKey('personId', $own[0]);
    }

    public function test_the_quotes_of_the_people_come_with_the_companys_and_a_quote_of_the_company_keeps_no_tag(): void
    {
        $this->makeQuote(self::T, ['subject' => 'company quote', 'organization_id' => $this->acme]);
        $this->makeQuote(self::T, ['subject' => 'company quote for Ravi', 'organization_id' => $this->acme, 'contact_id' => $this->ravi]);
        $this->makeQuote(self::T, ['subject' => 'Priya only quote', 'contact_id' => $this->priya]);
        $this->makeQuote(self::T, ['subject' => 'Olga quote', 'organization_id' => $this->beta, 'contact_id' => $this->outsider]);
        $this->makeQuote(self::T, ['subject' => 'deleted person quote', 'contact_id' => $this->gone]);
        $this->makeQuote(self::T2, ['subject' => 'their quote', 'contact_id' => $this->priya]);

        $default = $this->getJson("/api/crm/quotes?organization_id={$this->acme}", $this->h)->assertOk()->json('data.items');
        $this->assertSame(['company quote', 'company quote for Ravi'], collect($default)->pluck('subject')->sort()->values()->all());
        $this->assertArrayNotHasKey('personId', $default[0]);

        $rows = $this->getJson("/api/crm/quotes?organization_id={$this->acme}&include=people", $this->h)->assertOk()->json('data.items');
        $this->assertSame([[(string) $this->priya, 'Priya only quote'], [null, 'company quote'], [null, 'company quote for Ravi']], $this->tags($rows));
        $this->assertSame(3, $this->getJson("/api/crm/quotes?organization_id={$this->acme}&include=people", $this->h)->json('data.pagination.total'), 'the count is of the widened list');
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    public function test_another_organisation_asking_for_this_companys_people_gets_nothing_of_it(): void
    {
        $this->email('company mail', [['ORGANIZATION', $this->acme]]);
        $this->email('priya mail', [['CONTACT', $this->priya]]);
        $this->event('call with Priya', 'CONTACT', $this->priya);
        $this->makeQuote(self::T, ['subject' => 'Priya only quote', 'contact_id' => $this->priya]);
        $this->audit('crm.contact.updated', 'crm_contacts', $this->priya);
        // the stranger's OWN rows that carry this contact's id: they must not be read as "the people of Acme" (nor named after Acme's Priya)
        $this->event('their call', 'CONTACT', $this->priya, self::T2);
        $this->makeQuote(self::T2, ['subject' => 'their quote', 'contact_id' => $this->priya]);
        $stranger = $this->crmUser(self::T2)['h'];

        foreach (['emails', 'documents', 'comments', 'history'] as $what) {
            $this->getJson("/api/crm/organizations/{$this->acme}/{$what}?include=people", $stranger)->assertStatus(404);
        }
        $this->assertSame([], $this->getJson("/api/task-management/calendar/events/linked/ORGANIZATION/{$this->acme}?syear=2026&include=people", $stranger)->assertOk()->json('data.entries'));
        $this->assertSame([], $this->getJson("/api/crm/quotes?organization_id={$this->acme}&include=people", $stranger)->assertOk()->json('data.items'));
    }

    // ── the number of queries ────────────────────────────────────────────────

    public function test_the_number_of_queries_does_not_depend_on_how_many_people_the_company_has(): void
    {
        $company = $this->makeOrganization(self::T, ['name' => 'Big Co']);
        $people = [];
        $addPerson = function (int $n) use ($company, &$people): void {
            $id = $this->makeContact(self::T, ['organization_id' => $company, 'first_name' => "P{$n}", 'last_name' => 'Big']);
            $people[] = $id;
            $this->email("mail {$n}", [['CONTACT', $id]]);
            $this->document("doc {$n}", [['CONTACT', $id]]);
            DB::table('crm_contact_comments')->insert(['contact_id' => $id, 'sub_institute_id' => self::T, 'comment' => "note {$n}", 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('crm.contact.updated', 'crm_contacts', $id);
            $this->event("event {$n}", 'CONTACT', $id);
            $this->makeQuote(self::T, ['subject' => "quote {$n}", 'contact_id' => $id]);
        };
        $urls = [
            'emails' => "/api/crm/organizations/{$company}/emails?include=people", 'documents' => "/api/crm/organizations/{$company}/documents?include=people",
            'comments' => "/api/crm/organizations/{$company}/comments?include=people", 'history' => "/api/crm/organizations/{$company}/history?include=people",
            'schedule' => "/api/task-management/calendar/events/linked/ORGANIZATION/{$company}?syear=2026&include=people", 'quotes' => "/api/crm/quotes?organization_id={$company}&include=people",
        ];
        $measure = function () use ($urls): array {
            $out = [];
            foreach ($urls as $what => $url) {
                $this->getJson($url, $this->h)->assertOk();   // warm-up: the first request of a kind reads what the next ones find cached (rights, schema)
                $n = 0;
                DB::flushQueryLog();
                DB::enableQueryLog();
                $json = $this->getJson($url, $this->h)->assertOk()->json('data');
                $n = count(DB::getQueryLog());
                DB::disableQueryLog();
                $out[$what] = [$n, count($json['entries'] ?? $json['items'] ?? $json)];
            }

            return $out;
        };

        foreach ([1, 2, 3] as $n) {
            $addPerson($n);
        }
        $three = $measure();
        foreach (range(4, 12) as $n) {
            $addPerson($n);
        }
        $twelve = $measure();

        foreach ($urls as $what => $url) {
            $this->assertSame(3, $three[$what][1], "{$what}: three people, three rows");
            $this->assertSame(12, $twelve[$what][1], "{$what}: twelve people, twelve rows");
            $this->assertSame($three[$what][0], $twelve[$what][0], "{$what}: the same number of queries for 3 and for 12 people ({$three[$what][0]} vs {$twelve[$what][0]})");
        }
    }

    // ── the Organizations list: people count, primary, search through a person ─

    public function test_the_list_carries_the_people_count_and_the_primary_contacts_name(): void
    {
        $empty = $this->makeOrganization(self::T, ['name' => 'Empty Ltd']);
        $items = collect($this->getJson('/api/crm/organizations?per_page=100', $this->h)->assertOk()->json('data.items'))->keyBy('id');

        $this->assertSame([2, 'Priya Shah'], [$items[(string) $this->acme]['peopleCount'], $items[(string) $this->acme]['primaryContactName']], 'the deleted person is not counted');
        $this->assertSame([1, null], [$items[(string) $this->beta]['peopleCount'], $items[(string) $this->beta]['primaryContactName']]);
        $this->assertSame([0, null], [$items[(string) $empty]['peopleCount'], $items[(string) $empty]['primaryContactName']]);
        $this->assertNull($items[(string) $this->acme]['matchedPerson']);

        // another organisation's contact that points at this company is not counted
        $this->makeContact(self::T2, ['organization_id' => $this->acme]);
        $this->assertSame(2, collect($this->getJson('/api/crm/organizations?per_page=100', $this->h)->json('data.items'))->firstWhere('id', (string) $this->acme)['peopleCount']);
    }

    public function test_the_search_also_finds_a_company_through_the_name_email_phone_or_mobile_of_one_of_its_people(): void
    {
        DB::table('crm_contacts')->where('id', $this->ravi)->update(['phone' => '020-5550123', 'mobile' => '98200-77777']);
        $this->makeContact(self::T, ['organization_id' => $this->beta, 'first_name' => 'Hidden', 'last_name' => 'Gem', 'email' => 'gem@deleted.example', 'deleted_at' => now()]);
        $this->makeContact(self::T2, ['organization_id' => $this->beta, 'first_name' => 'Stranger', 'last_name' => 'Danger']);
        $names = fn (string $q): array => collect($this->getJson('/api/crm/organizations?search=' . urlencode($q), $this->h)->assertOk()->json('data.items'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Acme School'], $names('Ravi'), 'first name of a person');
        $this->assertSame(['Acme School'], $names('Iyer'), 'last name');
        $this->assertSame(['Acme School'], $names('Ravi Iyer'), 'both names');
        $this->assertSame(['Acme School'], $names('priya@acme'), 'e-mail');
        $this->assertSame(['Acme School'], $names('5550123'), 'phone');
        $this->assertSame(['Acme School'], $names('77777'), 'mobile');
        $this->assertSame(['Beta College'], $names('Beta'), 'the company\'s own name still matches');
        $this->assertSame([], $names('gem@deleted'), 'a deleted person does not make a company match');
        $this->assertSame([], $names('Danger'), 'nor does another organisation\'s person');

        $viaPerson = collect($this->getJson('/api/crm/organizations?search=Ravi', $this->h)->json('data.items'))->first();
        $this->assertSame(['id' => (string) $this->ravi, 'name' => 'Ravi Iyer'], $viaPerson['matchedPerson']);

        // a company that matches on its own does not say "found through a person", even when one of its people matches as well
        $own = collect($this->getJson('/api/crm/organizations?search=acme', $this->h)->json('data.items'))->first();
        $this->assertSame('Acme School', $own['name']);
        $this->assertNull($own['matchedPerson']);
    }

    public function test_the_number_of_queries_of_the_list_does_not_depend_on_the_number_of_companies(): void
    {
        $measure = function (string $query): array {
            $this->getJson('/api/crm/organizations?per_page=100&' . $query, $this->h)->assertOk();   // warm-up, as above
            DB::flushQueryLog();
            DB::enableQueryLog();
            $items = $this->getJson('/api/crm/organizations?per_page=100&' . $query, $this->h)->assertOk()->json('data.items');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$n, count($items)];
        };
        $make = function (int $from, int $to): void {
            for ($i = $from; $i <= $to; $i++) {
                $org = $this->makeOrganization(self::T, ['name' => "Zed {$i}"]);
                $this->makeContact(self::T, ['organization_id' => $org, 'first_name' => 'Zed', 'last_name' => "Person {$i}", 'is_primary' => $i % 2]);
            }
        };

        DB::table('crm_contacts')->delete();
        DB::table('crm_organizations')->delete();
        $make(1, 5);
        [$five, $rows5] = $measure('search=Person');
        [$fiveAll] = $measure('');
        $make(6, 25);
        [$twentyFive, $rows25] = $measure('search=Person');
        [$twentyFiveAll] = $measure('');

        $this->assertSame([5, 25], [$rows5, $rows25]);
        $this->assertSame($five, $twentyFive, "the searching list: the same number of queries for 5 and for 25 rows ({$five} vs {$twentyFive})");
        $this->assertSame($fiveAll, $twentyFiveAll, "the plain list: the same number of queries for 5 and for 25 rows ({$fiveAll} vs {$twentyFiveAll})");
    }
}
