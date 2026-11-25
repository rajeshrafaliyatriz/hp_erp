<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsContactWorld;
use Tests\TestCase;

/**
 * The contact API as the people of a company use it (S18, contract Docs/agentic-crm/slices/S18-CONTACTS-INTO-ORGANIZATIONS-CONTRACT.md sections 2 and 3), through the real routes, rights and
 * controller on in-memory sqlite, every table from its real migration. No test of the contact CRUD, import, export or bulk actions existed before this one.
 *
 * What is pinned: every field of the answer (and the old names still there), the lengths / date / company / person / role checks, the Primary star (one per company, cleared on delete, move and
 * removal), the list's phone search and new filters, the Deals endpoint (one query per table), tenant isolation, the rights, the history entries of create / bulk / transfer / import / primary,
 * the company that is in the Recycle Bin, the CSV import (role, star) and export (Primary, Role, Company), and the two rights fixes (the Recycle Bin's second Contacts link, WhatsApp).
 */
class CrmContactApiTest extends TestCase
{
    use BuildsContactWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildContactWorld();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $over */
    private function person(int $org, array $over = [], int $tenant = self::T): int
    {
        return $this->makeContact($tenant, $over + ['organization_id' => $org]);
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return $over + ['firstName' => 'Meera', 'lastName' => 'Nair', 'assignedTo' => 1, 'email' => 'meera@acme.example'];
    }

    private function primaryIds(int $org, int $tenant = self::T): array
    {
        return DB::table('crm_contacts')->where('sub_institute_id', $tenant)->where('organization_id', $org)->where('is_primary', 1)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function events(int $contact): array
    {
        return DB::table('g2g_event')->where('entity_type', 'crm_contacts')->where('entity_id', $contact)->orderBy('id')->pluck('type')->all();
    }

    // ── create / read / update: the fields of the contract ───────────────────

    public function test_a_person_is_created_with_every_field_the_screen_has_and_the_answer_carries_them_all(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $boss = $this->person($acme, ['first_name' => 'Ravi', 'last_name' => 'Iyer']);

        $r = $this->postJson('/api/crm/contacts', $this->payload([
            'organizationId' => $acme, 'contactRole' => 'Decision maker', 'isPrimary' => true, 'birthday' => '1988-04-09', 'assistant' => 'Kiran', 'assistantPhone' => '98200 11111',
            'reportsToId' => $boss, 'emailOptOut' => true, 'linkedinUrl' => 'https://linkedin.example/in/meera', 'fax' => '022 555', 'homePhone' => '022 666',
            'mailingStreet' => '1 Main Rd', 'mailingCity' => 'Pune', 'mailingState' => 'MH', 'mailingCode' => '411001', 'mailingCountry' => 'India', 'mailingPoBox' => 'PO 7',
            'otherStreet' => '2 Side Rd', 'otherCity' => 'Mumbai', 'otherState' => 'MH', 'otherCode' => '400001', 'otherCountry' => 'India', 'otherPoBox' => 'PO 8',
            'phone' => 9820022222, 'mobile' => '98200 22222', 'title' => 'Head of Purchase', 'department' => 'Procurement', 'description' => 'Met at the fair',
        ]), $u['h'])->assertCreated()->assertJsonPath('status', 1);

        $d = $r->json('data');
        foreach (['isPrimary', 'contactRole', 'birthday', 'assistant', 'assistantPhone', 'reportsToId', 'reportsToName', 'emailOptOut', 'linkedinUrl', 'fax', 'homePhone',
            'mailingStreet', 'mailingCity', 'mailingState', 'mailingPostalCode', 'mailingCountry', 'mailingPoBox', 'otherStreet', 'otherCity', 'otherState', 'otherCode', 'otherCountry', 'otherPoBox',
            'organizationId', 'organizationName', 'organizationDeleted', 'description'] as $key) {
            $this->assertArrayHasKey($key, $d, "the answer must carry {$key}");
        }
        $this->assertSame(true, $d['isPrimary']);
        $this->assertSame('Decision maker', $d['contactRole']);
        $this->assertSame('1988-04-09', $d['birthday']);
        $this->assertSame((string) $boss, $d['reportsToId']);
        $this->assertSame('Ravi Iyer', $d['reportsToName']);
        $this->assertSame(true, $d['emailOptOut']);
        $this->assertSame('https://linkedin.example/in/meera', $d['linkedinUrl']);
        $this->assertSame('411001', $d['mailingPostalCode'], 'the screen name of the postal code');
        $this->assertSame('411001', $d['mailingCode'], 'the name every screen has used until now stays');
        $this->assertSame('400001', $d['otherCode']);
        $this->assertSame('9820022222', $d['phone'], 'a number sent as a JSON number is still a phone number');
        $this->assertSame('Acme', $d['organizationName']);
        $this->assertFalse($d['organizationDeleted']);
        $this->assertSame('Met at the fair', $d['description'], 'the description stays in the answer and is writable');

        $this->assertSame([(int) $d['id']], $this->primaryIds($acme));
        $this->assertContains('crm.contact.created', $this->events((int) $d['id']), 'creating a person is in its history');
    }

    public function test_a_person_answers_with_the_name_of_its_owner_and_never_names_a_user_of_another_organisation(): void
    {
        // The Owner showed "User #28" whenever the screen's own employee list had not loaded: the answer now carries the name.
        $u = $this->crmUser(self::T, 'Asha', 'Rao');
        $stranger = $this->user([], self::T2, 'Zed', 'Other');
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $owned = $this->person($acme, ['assigned_to' => $u['id']]);
        $foreign = $this->person($acme, ['assigned_to' => $stranger['id']]);

        $this->getJson('/api/crm/contacts/' . $owned, $u['h'])->assertOk()->assertJsonPath('data.assignedTo', (string) $u['id'])->assertJsonPath('data.assignedToName', 'Asha Rao');
        $this->getJson('/api/crm/contacts/' . $foreign, $u['h'])->assertOk()->assertJsonPath('data.assignedToName', null);
    }

    public function test_an_update_changes_the_new_fields_and_the_description_and_a_postal_code_goes_by_either_name(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization();
        $id = $this->person($acme, ['description' => 'old']);

        $this->putJson("/api/crm/contacts/{$id}", ['contactRole' => 'Billing', 'description' => 'new', 'linkedinUrl' => 'https://l.example/x', 'mailingPostalCode' => '560001', 'otherPostalCode' => '110001', 'birthday' => '2000-02-29'], $u['h'])
            ->assertOk()->assertJsonPath('data.contactRole', 'Billing')->assertJsonPath('data.description', 'new')->assertJsonPath('data.mailingCode', '560001')->assertJsonPath('data.otherCode', '110001')
            ->assertJsonPath('data.birthday', '2000-02-29');

        $row = DB::table('crm_contacts')->find($id);
        $this->assertSame(['Billing', 'new', 'https://l.example/x', '560001', '110001'], [$row->contact_role, $row->description, $row->linkedin_url, $row->mailing_code, $row->other_code]);
        $this->assertContains('crm.contact.updated', $this->events($id));

        $this->putJson("/api/crm/contacts/{$id}", ['contactRole' => ''], $u['h'])->assertOk()->assertJsonPath('data.contactRole', null);
    }

    public function test_the_checks_on_lengths_dates_company_person_and_role_say_what_to_do(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization(self::T);
        $theirs = $this->makeOrganization(self::T2);
        $gone = $this->makeOrganization(self::T, ['deleted_at' => now()]);
        $stranger = $this->makeContact(self::T2);
        $id = $this->person($acme);

        $this->postJson('/api/crm/contacts', $this->payload(['phone' => str_repeat('9', 31)]), $u['h'])->assertStatus(422)->assertJsonPath('status', 0);
        $this->postJson('/api/crm/contacts', $this->payload(['lastName' => str_repeat('x', 101)]), $u['h'])->assertStatus(422);
        $this->postJson('/api/crm/contacts', $this->payload(['mailingStreet' => str_repeat('x', 251)]), $u['h'])->assertStatus(422);
        $this->postJson('/api/crm/contacts', $this->payload(['birthday' => '31/12/1990']), $u['h'])->assertStatus(422)->assertJsonPath('message', 'Birthday must be a date (YYYY-MM-DD).');
        $this->postJson('/api/crm/contacts', $this->payload(['birthday' => '1990-02-30']), $u['h'])->assertStatus(422);
        $this->postJson('/api/crm/contacts', $this->payload(['organizationId' => $theirs]), $u['h'])->assertStatus(422)->assertJsonPath('message', 'Choose a company from the list.');
        $this->postJson('/api/crm/contacts', $this->payload(['organizationId' => $gone]), $u['h'])->assertStatus(422)->assertJsonPath('message', 'Choose a company from the list.');
        $this->postJson('/api/crm/contacts', $this->payload(['organizationId' => 99999]), $u['h'])->assertStatus(422)->assertJsonPath('message', 'Choose a company from the list.');
        $this->postJson('/api/crm/contacts', $this->payload(['contactRole' => 'Wizard']), $u['h'])->assertStatus(422)->assertJsonPath('message', 'Choose a role from the list.');
        $this->postJson('/api/crm/contacts', $this->payload(['reportsToId' => $stranger]), $u['h'])->assertStatus(422)->assertJsonPath('message', 'Choose a person from the list.');
        $this->postJson('/api/crm/contacts', $this->payload(['isPrimary' => true]), $u['h'])->assertStatus(422)->assertJsonPath('message', 'A person needs a company before it can be the primary contact.');
        $this->putJson("/api/crm/contacts/{$id}", ['reportsToId' => $id], $u['h'])->assertStatus(422)->assertJsonPath('message', 'A contact cannot report to themselves.');
        $this->putJson("/api/crm/contacts/{$id}", ['organizationId' => $theirs], $u['h'])->assertStatus(422)->assertJsonPath('message', 'Choose a company from the list.');
        $this->putJson("/api/crm/contacts/{$id}", ['organizationId' => null], $u['h'])->assertOk()->assertJsonPath('data.organizationId', null);
        $this->postJson('/api/crm/contacts', $this->payload(['organizationId' => null, 'contactRole' => 'Other']), $u['h'])->assertCreated();
        $this->assertSame(2, DB::table('crm_contacts')->where('sub_institute_id', self::T)->whereNull('organization_id')->count(), 'a person with no company is allowed');
    }

    // ── list: search and filters ─────────────────────────────────────────────

    public function test_the_list_searches_phone_mobile_title_and_department_and_filters_by_the_new_fields(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme School']);
        $beta = $this->makeOrganization(self::T, ['name' => 'Beta College']);
        $a = $this->person($acme, ['first_name' => 'Asha', 'last_name' => 'Alpha', 'phone' => '020-5550001', 'title' => 'Principal', 'is_primary' => 1, 'contact_role' => 'Decision maker', 'assigned_to' => 5, 'consent_status' => 'opted_out']);
        $b = $this->person($beta, ['first_name' => 'Bala', 'last_name' => 'Beta', 'mobile' => '98765-43210', 'department' => 'Finance', 'do_not_call' => 1, 'assigned_to' => 6, 'consent_status' => 'unknown']);
        $c = $this->makeContact(self::T, ['first_name' => 'Chitra', 'last_name' => 'Gamma', 'consent_status' => null]);
        $this->person($acme, ['first_name' => 'Other', 'last_name' => 'Tenant'], self::T2);

        $ids = fn (string $q): array => collect($this->getJson("/api/crm/contacts?{$q}", $u['h'])->assertOk()->json('data.items'))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        $this->assertSame([$a], $ids('search=5550001'), 'phone');
        $this->assertSame([$b], $ids('search=43210'), 'mobile');
        $this->assertSame([$a], $ids('search=Principal'), 'title');
        $this->assertSame([$b], $ids('search=Finance'), 'department');
        $this->assertSame([$a], $ids('search=' . urlencode('Asha Alpha')), 'first and last name together');
        $this->assertSame([$a], $ids('search=' . urlencode('Acme School')), 'the company name still matches');
        $this->assertSame([$a], $ids('primary=1'));
        $this->assertSame([$b], $ids('owner=6'));
        $this->assertSame([$a], $ids('consent=opted_out'));
        $this->assertSame([$b, $c], $ids('consent=unknown'), 'a person nobody recorded anything for is unknown, empty or not');
        $this->assertSame([$b], $ids('do_not_call=1'));
        $this->assertSame([$c], $ids('no_company=1'));
        $this->assertSame([$b], $ids("organization_id={$beta}"));
        $this->assertCount(3, $this->getJson('/api/crm/contacts', $u['h'])->json('data.items'), 'the other organisation\'s person is never listed');

        $first = $this->getJson('/api/crm/contacts?sort_by=is_primary&sort_dir=desc', $u['h'])->assertOk()->json('data.items.0');
        $this->assertSame((string) $a, $first['id']);
        $this->assertSame([true, 'Decision maker', 'Acme School'], [$first['isPrimary'], $first['contactRole'], $first['organizationName']]);
        $this->assertSame((string) $a, $this->getJson('/api/crm/contacts?sort_by=contact_role&sort_dir=desc', $u['h'])->json('data.items.0.id'));
    }

    // ── the Primary star ─────────────────────────────────────────────────────

    public function test_one_primary_per_company_the_new_one_takes_the_star_from_the_old_and_clearing_leaves_none(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization();
        $beta = $this->makeOrganization();
        $a = $this->person($acme);
        $b = $this->person($acme);
        $other = $this->person($beta, ['is_primary' => 1]);

        $this->postJson("/api/crm/contacts/{$a}/primary", [], $u['h'])->assertOk()->assertJsonPath('data.id', (string) $a)->assertJsonPath('data.isPrimary', true);
        $this->assertSame([$a], $this->primaryIds($acme));

        $this->postJson("/api/crm/contacts/{$b}/primary", [], $u['h'])->assertOk()->assertJsonPath('data.isPrimary', true);
        $this->assertSame([$b], $this->primaryIds($acme), 'the star moved: exactly one person of the company has it');
        $this->assertSame([$other], $this->primaryIds($beta), 'another company\'s primary is not touched');
        $this->assertContains('crm.contact.primary_set', $this->events($b));
        $this->assertContains('crm.contact.primary_cleared', $this->events($a), 'the person who lost the star has it in their history');

        $this->deleteJson("/api/crm/contacts/{$b}/primary", [], $u['h'])->assertOk()->assertJsonPath('data.isPrimary', false);
        $this->assertSame([], $this->primaryIds($acme));
        $this->assertContains('crm.contact.primary_cleared', $this->events($b));
        $this->deleteJson("/api/crm/contacts/{$b}/primary", [], $u['h'])->assertOk();

        // through create and update as well
        $c = $this->postJson('/api/crm/contacts', $this->payload(['organizationId' => $acme, 'isPrimary' => true]), $u['h'])->assertCreated()->json('data.id');
        $this->assertSame([(int) $c], $this->primaryIds($acme));
        $this->putJson("/api/crm/contacts/{$a}", ['isPrimary' => true], $u['h'])->assertOk()->assertJsonPath('data.isPrimary', true);
        $this->assertSame([$a], $this->primaryIds($acme));
        $this->putJson("/api/crm/contacts/{$a}", ['isPrimary' => false], $u['h'])->assertOk();
        $this->assertSame([], $this->primaryIds($acme));
    }

    public function test_a_person_with_no_company_cannot_be_the_primary_and_another_organisations_person_is_not_found(): void
    {
        $u = $this->crmUser();
        $lonely = $this->makeContact();
        $theirs = $this->makeContact(self::T2, ['organization_id' => $this->makeOrganization(self::T2)]);

        $this->postJson("/api/crm/contacts/{$lonely}/primary", [], $u['h'])->assertStatus(422)->assertJsonPath('message', 'A person needs a company before it can be the primary contact.');
        $this->putJson("/api/crm/contacts/{$lonely}", ['isPrimary' => true], $u['h'])->assertStatus(422)->assertJsonPath('message', 'A person needs a company before it can be the primary contact.');
        $this->postJson("/api/crm/contacts/{$theirs}/primary", [], $u['h'])->assertStatus(404);
        $this->deleteJson("/api/crm/contacts/{$theirs}/primary", [], $u['h'])->assertStatus(404);
        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $theirs)->value('is_primary'));
    }

    public function test_deleting_moving_or_removing_the_company_of_a_primary_takes_the_star_off(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization();
        $beta = $this->makeOrganization();
        $stars = fn (int ...$ids): array => array_map(fn ($i) => (int) DB::table('crm_contacts')->where('id', $i)->value('is_primary'), $ids);

        $deleted = $this->person($acme, ['is_primary' => 1]);
        $this->deleteJson("/api/crm/contacts/{$deleted}", [], $u['h'])->assertOk();
        $this->assertSame([0], $stars($deleted));

        $moved = $this->person($acme, ['is_primary' => 1]);
        $this->putJson("/api/crm/contacts/{$moved}", ['organizationId' => $beta], $u['h'])->assertOk()->assertJsonPath('data.isPrimary', false);
        $this->assertSame([0], $stars($moved));

        $removed = $this->person($acme, ['is_primary' => 1]);
        $this->putJson("/api/crm/contacts/{$removed}", ['organizationId' => null], $u['h'])->assertOk()->assertJsonPath('data.isPrimary', false);

        $same = $this->person($acme, ['is_primary' => 1]);
        $this->putJson("/api/crm/contacts/{$same}", ['title' => 'Boss', 'organizationId' => $acme], $u['h'])->assertOk()->assertJsonPath('data.isPrimary', true);

        $bulk = $this->person($acme, ['is_primary' => 1]);
        $this->postJson('/api/crm/contacts/bulk/delete', ['ids' => [$bulk]], $u['h'])->assertOk();
        $this->assertSame([0], $stars($bulk));
        $this->assertContains('crm.contact.deleted', $this->events($bulk), 'bulk delete is in the history');
    }

    // ── the company that is in the Recycle Bin ───────────────────────────────

    public function test_a_deleted_company_keeps_its_people_linked_and_they_are_under_it_again_when_it_is_restored(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $id = $this->person($acme, ['first_name' => 'Meera']);

        $this->deleteJson("/api/crm/organizations/{$acme}", [], $u['h'])->assertOk();

        $this->assertSame($acme, (int) DB::table('crm_contacts')->where('id', $id)->value('organization_id'), 'deleting a company does not unlink its people');
        foreach ([$this->getJson("/api/crm/contacts/{$id}", $u['h'])->json('data'), $this->getJson('/api/crm/contacts', $u['h'])->json('data.items.0')] as $row) {
            $this->assertSame([(string) $acme, 'Acme', true], [$row['organizationId'], $row['organizationName'], $row['organizationDeleted']]);
        }

        $this->postJson("/api/crm/recycle-bin/organizations/{$acme}/restore", [], $u['h'])->assertOk();

        $this->assertFalse($this->getJson("/api/crm/contacts/{$id}", $u['h'])->json('data.organizationDeleted'), 'restored: the person is under the company again');
        $this->assertSame($acme, (int) DB::table('crm_contacts')->where('id', $id)->value('organization_id'));
    }

    // ── Deals of a person ────────────────────────────────────────────────────

    public function test_the_deals_of_a_person_come_from_the_junction_and_the_contact_pointers_one_query_per_table(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization();
        $id = $this->person($acme);
        $other = $this->person($acme);
        $opp = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-1', 'name' => 'Big deal', 'sales_stage' => 'Proposal', 'amount' => 5000, 'currency' => 'INR', 'closing_date' => '2026-12-31', 'sub_institute_id' => self::T]);
        $oppOther = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-2', 'name' => 'Not mine', 'sub_institute_id' => self::T]);
        $oppDeleted = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-3', 'name' => 'Gone', 'sub_institute_id' => self::T, 'deleted_at' => now()]);
        DB::table('crm_opportunity_contacts')->insert([['opportunity_id' => $opp, 'contact_id' => $id], ['opportunity_id' => $oppOther, 'contact_id' => $other], ['opportunity_id' => $oppDeleted, 'contact_id' => $id]]);
        $quote = $this->makeQuote(self::T, ['contact_id' => $id, 'quote_no' => 'Q-1', 'subject' => 'Q one', 'quote_stage' => 'Sent', 'total' => 120.5, 'currency' => 'INR', 'valid_till' => '2026-12-01']);
        $this->makeQuote(self::T, ['contact_id' => $other]);
        DB::table('crm_sales_orders')->insert(['so_no' => 'SO-1', 'subject' => 'Order', 'contact_id' => $id, 'so_stage' => 'Created', 'total' => 99, 'currency' => 'INR', 'due_date' => '2027-01-05', 'sub_institute_id' => self::T]);
        DB::table('crm_invoices')->insert(['invoice_no' => 'INV-1', 'subject' => 'Invoice', 'contact_id' => $id, 'invoice_stage' => 'Paid', 'total' => 10, 'currency' => 'USD', 'invoice_date' => '2026-11-20', 'sub_institute_id' => self::T]);
        DB::table('crm_tickets')->insert(['ticket_no' => 'TKT-1', 'title' => 'Printer', 'contact_id' => $id, 'status' => 'Open', 'sub_institute_id' => self::T, 'created_at' => '2026-11-21 10:00:00']);

        $tables = [];
        DB::listen(function ($q) use (&$tables) {
            foreach (['crm_opportunities', 'crm_quotes', 'crm_sales_orders', 'crm_invoices', 'crm_tickets'] as $table) {
                if (str_contains($q->sql, "\"{$table}\"") && str_starts_with(ltrim(strtolower($q->sql)), 'select')) {
                    $tables[$table] = ($tables[$table] ?? 0) + 1;
                }
            }
        });
        $d = $this->getJson("/api/crm/contacts/{$id}/deals", $u['h'])->assertOk()->json('data');

        $this->assertSame(['opportunities', 'quotes', 'salesOrders', 'invoices', 'tickets'], array_keys($d));
        $this->assertSame([['id' => (string) $opp, 'number' => 'OPP-1', 'name' => 'Big deal', 'status' => 'Proposal', 'amount' => 5000, 'currency' => 'INR', 'date' => '2026-12-31']], $d['opportunities'], 'only the live opportunity this person is on');
        $this->assertSame([['id' => (string) $quote, 'number' => 'Q-1', 'name' => 'Q one', 'status' => 'Sent', 'amount' => 120.5, 'currency' => 'INR', 'date' => '2026-12-01']], $d['quotes']);
        $this->assertSame(['SO-1', 99, '2027-01-05'], [$d['salesOrders'][0]['number'], $d['salesOrders'][0]['amount'], $d['salesOrders'][0]['date']]);
        $this->assertSame(['INV-1', 'USD'], [$d['invoices'][0]['number'], $d['invoices'][0]['currency']]);
        $this->assertSame(['TKT-1', 'Printer', 'Open', null, null, '2026-11-21'], [$d['tickets'][0]['number'], $d['tickets'][0]['name'], $d['tickets'][0]['status'], $d['tickets'][0]['amount'], $d['tickets'][0]['currency'], $d['tickets'][0]['date']]);
        $this->assertSame(['crm_opportunities' => 1, 'crm_quotes' => 1, 'crm_sales_orders' => 1, 'crm_invoices' => 1, 'crm_tickets' => 1], $tables, 'one query per table');
    }

    // ── tenant isolation and rights ──────────────────────────────────────────

    public function test_another_organisations_person_is_never_found_changed_or_listed(): void
    {
        $u = $this->crmUser();
        $theirs = $this->makeContact(self::T2, ['organization_id' => $this->makeOrganization(self::T2), 'is_primary' => 1]);

        $this->getJson("/api/crm/contacts/{$theirs}", $u['h'])->assertStatus(404);
        $this->putJson("/api/crm/contacts/{$theirs}", ['title' => 'X'], $u['h'])->assertStatus(404);
        $this->deleteJson("/api/crm/contacts/{$theirs}", [], $u['h'])->assertStatus(404);
        $this->getJson("/api/crm/contacts/{$theirs}/deals", $u['h'])->assertStatus(404);
        $this->postJson("/api/crm/contacts/{$theirs}/transfer-ownership", ['assignedTo' => 3], $u['h'])->assertStatus(404);
        $this->postJson('/api/crm/contacts/bulk/delete', ['ids' => [$theirs]], $u['h'])->assertOk()->assertJsonPath('data.summary.failed', 1);
        $this->postJson('/api/crm/contacts/bulk/assign', ['ids' => [$theirs], 'assignedTo' => 3], $u['h'])->assertOk()->assertJsonPath('data.summary.failed', 1);
        $this->assertNull(DB::table('crm_contacts')->where('id', $theirs)->value('deleted_at'));
        $this->assertSame(0, $this->getJson('/api/crm/contacts', $u['h'])->json('data.pagination.total'));
    }

    public function test_the_rights_gate_every_new_route_and_either_contacts_link_opens_them(): void
    {
        $viewer = $this->user([self::CONTACTS_LINK => ['view']]);
        $nobody = $this->user([]);
        $support = $this->user([self::CONTACTS_SUPPORT => ['view', 'edit']]);
        $id = $this->person($this->makeOrganization());

        $this->getJson("/api/crm/contacts/{$id}/deals", $viewer['h'])->assertOk();
        $this->postJson("/api/crm/contacts/{$id}/primary", [], $viewer['h'])->assertStatus(403);
        $this->deleteJson("/api/crm/contacts/{$id}/primary", [], $viewer['h'])->assertStatus(403);
        $this->postJson('/api/crm/contacts', $this->payload(), $viewer['h'])->assertStatus(403);
        $this->getJson("/api/crm/contacts/{$id}/deals", $nobody['h'])->assertStatus(403);
        $this->postJson("/api/crm/contacts/{$id}/primary", [], $nobody['h'])->assertStatus(403);

        $this->getJson("/api/crm/contacts/{$id}/deals", $support['h'])->assertOk();
        $this->postJson("/api/crm/contacts/{$id}/primary", [], $support['h'])->assertOk();
    }

    // ── history of the writes that had none ──────────────────────────────────

    public function test_bulk_assign_transfer_and_import_are_in_the_history_too(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization();
        $a = $this->person($acme, ['assigned_to' => 1]);
        $b = $this->person($acme, ['assigned_to' => 1]);

        $this->postJson('/api/crm/contacts/bulk/assign', ['ids' => [$a], 'assignedTo' => 7], $u['h'])->assertOk();
        $this->postJson("/api/crm/contacts/{$b}/transfer-ownership", ['assignedTo' => 8], $u['h'])->assertOk();
        $this->assertSame(['crm.contact.transferred'], $this->events($a));
        $this->assertSame(['crm.contact.transferred'], $this->events($b));
        $change = json_decode((string) DB::table('g2g_event')->where('entity_id', $b)->where('entity_type', 'crm_contacts')->value('payload'), true)['changes'][0];
        $this->assertSame(['assigned_to', 1, 8], [$change['field'], $change['oldValue'], $change['newValue']]);

        $this->postJson('/api/crm/contacts/import', ['rows' => [['lastName' => 'Imported', 'assignedTo' => 1, 'organizationId' => $acme]]], $u['h'])->assertOk()->assertJsonPath('data.created', 1);
        $imported = (int) DB::table('crm_contacts')->where('last_name', 'Imported')->value('id');
        $this->assertSame(['crm.contact.created'], $this->events($imported));
    }

    // ── CSV import and export ────────────────────────────────────────────────

    public function test_the_import_takes_a_role_only_from_the_picklist_and_a_star_only_when_the_company_has_none(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $beta = $this->makeOrganization(self::T, ['name' => 'Beta']);
        $this->person($beta, ['is_primary' => 1, 'last_name' => 'AlreadyStar']);

        $rows = [
            ['lastName' => 'One', 'assignedTo' => 1, 'organizationId' => $acme, 'contactRole' => 'Influencer', 'isPrimary' => 'yes'],
            ['lastName' => 'Two', 'assignedTo' => 1, 'organizationId' => $acme, 'contactRole' => 'Technical', 'isPrimary' => 'yes'],
            ['lastName' => 'Three', 'assignedTo' => 1, 'organizationId' => $acme, 'contactRole' => 'Wizard'],
            ['lastName' => 'Four', 'assignedTo' => 1, 'organizationId' => $beta, 'isPrimary' => 'yes'],
            ['lastName' => 'Five', 'assignedTo' => 1, 'isPrimary' => 'yes'],
            ['lastName' => 'Six', 'assignedTo' => 1, 'organizationId' => $acme, 'isPrimary' => 'no', 'contactRole' => ''],
        ];
        $r = $this->postJson('/api/crm/contacts/import', ['rows' => $rows], $u['h'])->assertOk()->assertJsonPath('data.created', 5);

        $this->assertSame([false, 'Choose a role from the list.'], [$r->json('data.results.2.ok'), $r->json('data.results.2.reason')], 'a role outside the picklist: the row is reported, not saved');
        $this->assertNull(DB::table('crm_contacts')->where('last_name', 'Three')->first());
        $this->assertSame(['Influencer', 'Technical'], [DB::table('crm_contacts')->where('last_name', 'One')->value('contact_role'), DB::table('crm_contacts')->where('last_name', 'Two')->value('contact_role')]);
        $one = (int) DB::table('crm_contacts')->where('last_name', 'One')->value('id');
        $this->assertSame([$one], $this->primaryIds($acme), 'the first row with a star got it; the second found the company already had one');
        $this->assertSame(['AlreadyStar'], DB::table('crm_contacts')->where('organization_id', $beta)->where('is_primary', 1)->pluck('last_name')->all(), 'an import never takes the star from someone');
        $this->assertSame(0, (int) DB::table('crm_contacts')->where('last_name', 'Five')->value('is_primary'), 'no company, no star');
    }

    public function test_the_export_gains_primary_role_and_company(): void
    {
        $u = $this->crmUser();
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
        $this->person($acme, ['first_name' => 'Meera', 'last_name' => 'Nair', 'is_primary' => 1, 'contact_role' => 'Billing']);
        $this->makeContact(self::T, ['first_name' => 'Lonely', 'last_name' => 'One']);
        $this->person($acme, ['last_name' => 'Hidden'], self::T2);

        $csv = $this->get('/api/crm/contacts/export', $u['h'])->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        $header = array_shift($lines);
        $this->assertSame(['Primary', 'Role', 'Company'], array_slice($header, -3));
        $this->assertSame('Salutation', $header[0], 'the earlier columns keep their place');
        $byName = [];
        foreach ($lines as $line) {
            $byName[$line[2]] = array_slice($line, -3);
        }
        $this->assertSame(['yes', 'Billing', 'Acme'], $byName['Nair']);
        $this->assertSame(['no', '', ''], $byName['One']);
        $this->assertArrayNotHasKey('Hidden', $byName, 'only this organisation\'s people');
    }

    // ── the two rights fixes ─────────────────────────────────────────────────

    public function test_the_recycle_bin_shows_and_restores_contacts_to_someone_who_holds_only_the_support_link(): void
    {
        $support = $this->user([self::CONTACTS_SUPPORT => ['view', 'delete']]);
        $none = $this->user([self::ORGS_LINK => ['view', 'delete']]);
        $id = $this->person($this->makeOrganization(), ['deleted_at' => now(), 'first_name' => 'Binned']);

        $seen = fn (array $user): array => collect($this->getJson('/api/crm/recycle-bin?per_page=100', $user['h'])->assertOk()->json('data.items'))->where('type', 'contacts')->pluck('id')->all();

        $this->assertSame([(string) $id], $seen($support));
        $this->assertSame([], $seen($none));
        $this->postJson("/api/crm/recycle-bin/contacts/{$id}/restore", [], $none['h'])->assertStatus(403);
        $this->postJson("/api/crm/recycle-bin/contacts/{$id}/restore", [], $support['h'])->assertOk();
        $this->assertNull(DB::table('crm_contacts')->where('id', $id)->value('deleted_at'));
    }

    public function test_whatsapp_sends_need_the_sms_notifier_right_under_sales_or_support(): void
    {
        $sender = $this->user([self::SMS_LINK => ['view', 'edit']]);
        $supportSender = $this->user([self::SMS_SUPPORT => ['edit']]);
        $viewer = $this->user([self::SMS_LINK => ['view']]);
        $nobody = $this->user([self::CONTACTS_LINK => ['view', 'edit']]);
        $body = ['toNumber' => '919820011111', 'message' => 'Hello'];

        foreach (['/api/crm/whatsapp/send' => $body, '/api/crm/whatsapp/bulk-send' => ['relatedType' => 'contact', 'ids' => [1], 'message' => 'Hello']] as $url => $payload) {
            $this->postJson($url, $payload, $nobody['h'])->assertStatus(403);
            $this->postJson($url, $payload, $viewer['h'])->assertStatus(403);
            // past the right check the controller answers for itself (WhatsApp is not connected in the test world), which is anything but 403
            $this->assertNotSame(403, $this->postJson($url, $payload, $sender['h'])->getStatusCode(), $url . ' for the SMS notifier editor');
            $this->assertNotSame(403, $this->postJson($url, $payload, $supportSender['h'])->getStatusCode(), $url . ' for the support SMS notifier editor');
        }
    }
}
