<?php

namespace Tests\Feature;

use App\Domain\Crm\Contacts\ContactMerger;
use App\Domain\Crm\Contacts\ContactReferences;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsContactWorld;
use Tests\TestCase;

/**
 * Merging two contacts through the real endpoint, POST /api/crm/contacts/merge (S18, contract section 2): every record that belonged to the removed duplicate (quotes, orders, invoices, tickets,
 * the opportunities it is on, comments, e-mails, documents, schedule, campaign targets, SMS, approvals, knowledge, relationships, custom fields, consent, people reporting to it,
 * the lead it was converted from) follows the survivor, a row the survivor already has is dropped instead of colliding, nothing of another organisation moves, the star follows the rules, and
 * the history says what happened. Until S18 only campaign targets, facts, consent and the reporting line followed. The registry and the property test (ContactMergePropertyTest) cover the rest of
 * the reference points; here the tables are the real migrations. In-memory sqlite only.
 */
class CrmContactMergeTest extends TestCase
{
    use BuildsContactWorld;

    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildContactWorld();
        $this->acme = $this->makeOrganization(self::T, ['name' => 'Acme']);
    }

    private function merge(int $survivor, array $duplicates, ?array $user = null)
    {
        return $this->postJson('/api/crm/contacts/merge', ['survivorId' => $survivor, 'duplicateIds' => $duplicates], ($user ?? $this->crmUser())['h']);
    }

    private function person(array $over = [], int $tenant = self::T): int
    {
        return $this->makeContact($tenant, $over + ['organization_id' => $tenant === self::T ? $this->acme : null]);
    }

    /**
     * One row in every table a contact id can be stored in, pointing at $contact. Returns table.column => the id of the row.
     *
     * @return array<string, int>
     */
    private function pointers(int $contact, int $tenant = self::T, string $tag = 'a'): array
    {
        $t = $tenant;
        $email = DB::table('crm_emails')->insertGetId(['subject' => "mail {$tag}", 'body' => 'b', 'to_address' => 'x@y.example', 'sub_institute_id' => $t, 'created_at' => now(), 'updated_at' => now()]);
        $doc = DB::table('document_library')->insertGetId(['sub_institute_id' => $t, 'title' => "doc {$tag}"]);
        $campaign = DB::table('crm_campaigns')->insertGetId(['campaign_no' => 'CMP-' . uniqid(), 'name' => "camp {$tag}", 'sub_institute_id' => $t, 'created_at' => now(), 'updated_at' => now()]);
        $opp = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-' . uniqid(), 'name' => "opp {$tag}", 'sub_institute_id' => $t]);

        return [
            'crm_quotes.contact_id' => $this->makeQuote($t, ['contact_id' => $contact]),
            'crm_sales_orders.contact_id' => DB::table('crm_sales_orders')->insertGetId(['so_no' => 'SO-' . uniqid(), 'subject' => 's', 'contact_id' => $contact, 'sub_institute_id' => $t]),
            'crm_invoices.contact_id' => DB::table('crm_invoices')->insertGetId(['invoice_no' => 'INV-' . uniqid(), 'subject' => 's', 'contact_id' => $contact, 'sub_institute_id' => $t]),
            'crm_service_contracts.contact_id' => DB::table('crm_service_contracts')->insertGetId(['contract_no' => 'SC-' . uniqid(), 'subject' => 's', 'contact_id' => $contact, 'sub_institute_id' => $t]),
            'crm_tickets.contact_id' => DB::table('crm_tickets')->insertGetId(['ticket_no' => 'T-' . uniqid(), 'title' => 't', 'contact_id' => $contact, 'sub_institute_id' => $t]),
            'crm_leads.converted_contact_id' => $this->makeLead($t, ['converted_contact_id' => $contact, 'converted' => 1]),
            'crm_contact_comments.contact_id' => DB::table('crm_contact_comments')->insertGetId(['contact_id' => $contact, 'sub_institute_id' => $t, 'comment' => "note {$tag}"]),
            'crm_opportunity_contacts.contact_id' => DB::table('crm_opportunity_contacts')->insertGetId(['opportunity_id' => $opp, 'contact_id' => $contact]),
            'crm_email_links.linked_id' => DB::table('crm_email_links')->insertGetId(['email_id' => $email, 'linked_type' => 'CONTACT', 'linked_id' => $contact, 'sub_institute_id' => $t]),
            'document_library_links.linked_id' => DB::table('document_library_links')->insertGetId(['document_id' => $doc, 'linked_type' => 'CONTACT', 'linked_id' => $contact, 'sub_institute_id' => $t]),
            'task_management_calendar_events.linked_id' => DB::table('task_management_calendar_events')->insertGetId(['sub_institute_id' => $t, 'syear' => '2026', 'title' => "call {$tag}", 'start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 11:00:00', 'owner_id' => 1, 'created_by' => 1, 'linked_type' => 'CONTACT', 'linked_id' => $contact]),
            'crm_campaign_targets.target_id' => DB::table('crm_campaign_targets')->insertGetId(['campaign_id' => $campaign, 'target_type' => 'contact', 'target_id' => $contact]),
            'crm_outreach_approvals.target_id' => DB::table('crm_outreach_approvals')->insertGetId(['campaign_id' => $campaign, 'target_type' => 'contact', 'target_id' => $contact, 'sub_institute_id' => $t]),
            'crm_sms_log.related_id' => DB::table('crm_sms_log')->insertGetId(['to_number' => '98200', 'message' => 'hi', 'related_type' => 'contact', 'related_id' => $contact, 'sub_institute_id' => $t]),
            'crm_consent_preferences.entity_id' => DB::table('crm_consent_preferences')->insertGetId(['entity_type' => 'contact', 'entity_id' => $contact, 'channel' => "sms-{$tag}", 'status' => 'opted_in', 'sub_institute_id' => $t]),
            'crm_entity_relationships.from_id' => DB::table('crm_entity_relationships')->insertGetId(['from_type' => 'contact', 'from_id' => $contact, 'to_type' => 'organization', 'to_id' => 900 + $t, 'relationship_type' => 'contact_of', 'sub_institute_id' => $t]),
            'crm_knowledge_sources.entity_id' => DB::table('crm_knowledge_sources')->insertGetId(['entity_type' => 'contact', 'entity_id' => $contact, 'source_type' => 'manual', 'sub_institute_id' => $t]),
            'g2g_custom_field_values.record_id' => DB::table('g2g_custom_field_values')->insertGetId(['sub_institute_id' => $t, 'field_id' => $contact * 10 + ($tag === 'a' ? 1 : 2), 'record_table' => 'crm_contacts', 'record_id' => $contact, 'value' => $tag]),
            'crm_contacts.reports_to_id' => $this->person(['reports_to_id' => $contact, 'last_name' => "Report {$tag}"], $tenant),
        ];
    }

    /** The column a pointer lives in, "table.column" => [table, column]. */
    private function column(string $key): array
    {
        return explode('.', $key, 2);
    }

    // ── every pointer follows ────────────────────────────────────────────────

    public function test_every_record_of_the_removed_contact_follows_the_survivor_and_nothing_of_another_organisation_moves(): void
    {
        $keep = $this->person(['last_name' => 'Keep']);
        $dupe = $this->person(['last_name' => 'Dupe']);
        $theirKeep = $this->person(['last_name' => 'TheirKeep'], self::T2);
        $theirs = $this->pointers($dupe, self::T2, 'z');   // another organisation's rows that happen to carry the very same contact id
        $mine = $this->pointers($dupe);

        $this->merge($keep, [$dupe])->assertOk()->assertJsonPath('data.merged', 1);

        foreach ($mine as $key => $rowId) {
            [$table, $column] = $this->column($key);
            if ($table === 'crm_consent_preferences') {
                // The contact merge copies what the duplicate said about being contacted onto the survivor first (ConsentRecorder::carryOver), so the original row is the copy's duplicate and is dropped.
                $this->assertContains('sms-a', DB::table($table)->where('entity_type', 'contact')->where('entity_id', $keep)->where('sub_institute_id', self::T)->pluck('channel')->all(), 'the survivor holds the duplicate\'s consent choice');
                $this->assertSame(0, DB::table($table)->where('entity_type', 'contact')->where('entity_id', $dupe)->where('sub_institute_id', self::T)->count());

                continue;
            }
            $this->assertSame($keep, (int) DB::table($table)->where('id', $rowId)->value($column), "{$key} must follow the survivor");
        }
        foreach ($theirs as $key => $rowId) {
            [$table, $column] = $this->column($key);
            $this->assertSame($dupe, (int) DB::table($table)->where('id', $rowId)->value($column), "{$key} of another organisation must not move");
        }
        $this->assertNotNull(DB::table('crm_contacts')->where('id', $dupe)->value('deleted_at'));
        $this->assertNull(DB::table('crm_contacts')->where('id', $keep)->value('deleted_at'));
        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $theirKeep)->where('is_primary', 1)->count());

        // and the whole registry agrees: no row of this organisation points at the removed contact any more
        foreach (ContactReferences::direct() as $d) {
            $this->assertSame(0, DB::table($d['table'])->where('sub_institute_id', self::T)->where($d['column'], $dupe)->count(), "{$d['table']}.{$d['column']}");
        }

        // run again: nothing left to move
        $this->assertSame(['moved' => [], 'dropped' => [], 'cleared' => []], DB::transaction(fn () => ContactMerger::repoint(self::T, $dupe, $keep)));
    }

    public function test_a_row_the_survivor_already_has_is_dropped_instead_of_colliding_and_a_line_between_the_two_is_dropped(): void
    {
        $keep = $this->person(['last_name' => 'Keep']);
        $dupe = $this->person(['last_name' => 'Dupe']);
        $opp = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-X', 'name' => 'Shared', 'sub_institute_id' => self::T]);
        $campaign = DB::table('crm_campaigns')->insertGetId(['campaign_no' => 'CMP-X', 'name' => 'Shared', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $email = DB::table('crm_emails')->insertGetId(['subject' => 's', 'body' => 'b', 'to_address' => 'x@y.example', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$keep, $dupe] as $c) {
            DB::table('crm_opportunity_contacts')->insert(['opportunity_id' => $opp, 'contact_id' => $c]);
            DB::table('crm_campaign_targets')->insert(['campaign_id' => $campaign, 'target_type' => 'contact', 'target_id' => $c]);
            DB::table('crm_email_links')->insert(['email_id' => $email, 'linked_type' => 'CONTACT', 'linked_id' => $c, 'sub_institute_id' => self::T]);
            DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $c, 'channel' => 'sms', 'status' => 'opted_in', 'sub_institute_id' => self::T]);
            DB::table('g2g_custom_field_values')->insert(['sub_institute_id' => self::T, 'field_id' => 7, 'record_table' => 'crm_contacts', 'record_id' => $c, 'value' => 'v']);
        }
        DB::table('crm_entity_relationships')->insert(['from_type' => 'contact', 'from_id' => $dupe, 'to_type' => 'contact', 'to_id' => $keep, 'relationship_type' => 'colleague_of', 'sub_institute_id' => self::T]);

        $this->merge($keep, [$dupe])->assertOk();

        foreach (['crm_opportunity_contacts' => 'contact_id', 'crm_campaign_targets' => 'target_id', 'crm_email_links' => 'linked_id', 'crm_consent_preferences' => 'entity_id',
            'g2g_custom_field_values' => 'record_id'] as $table => $column) {
            $this->assertSame([$keep], DB::table($table)->pluck($column)->map(fn ($v) => (int) $v)->all(), "{$table}: one row, the survivor's, no collision");
        }
        $this->assertSame(0, DB::table('crm_entity_relationships')->count(), 'a relationship between the two merged contacts would relate the survivor to itself: dropped');
    }

    public function test_the_survivor_that_reported_to_the_duplicate_does_not_end_up_reporting_to_itself_and_others_report_to_the_survivor(): void
    {
        $keep = $this->person(['last_name' => 'Keep']);
        $dupe = $this->person(['last_name' => 'Dupe']);
        DB::table('crm_contacts')->where('id', $keep)->update(['reports_to_id' => $dupe]);
        $junior = $this->person(['last_name' => 'Junior', 'reports_to_id' => $dupe]);

        $this->merge($keep, [$dupe])->assertOk();

        $this->assertNull(DB::table('crm_contacts')->where('id', $keep)->value('reports_to_id'));
        $this->assertSame($keep, (int) DB::table('crm_contacts')->where('id', $junior)->value('reports_to_id'));
    }

    public function test_three_duplicates_at_once_and_a_survivor_of_another_organisation_is_not_found(): void
    {
        $keep = $this->person(['last_name' => 'Keep']);
        $d1 = $this->person();
        $d2 = $this->person();
        $d3 = $this->person();
        $rows = [$this->makeQuote(self::T, ['contact_id' => $d1]), $this->makeQuote(self::T, ['contact_id' => $d2]), $this->makeQuote(self::T, ['contact_id' => $d3])];

        $this->merge($keep, [$d1, $d2, $d3])->assertOk()->assertJsonPath('data.merged', 3);
        $this->assertSame([$keep, $keep, $keep], array_map(fn ($q) => (int) DB::table('crm_quotes')->where('id', $q)->value('contact_id'), $rows));

        $theirs = $this->makeContact(self::T2);
        $this->merge($theirs, [$this->person()])->assertStatus(404);
    }

    // ── the star ─────────────────────────────────────────────────────────────

    public function test_the_survivor_keeps_its_star_and_takes_the_duplicates_only_when_the_company_has_none(): void
    {
        $star = fn (int $id): int => (int) DB::table('crm_contacts')->where('id', $id)->value('is_primary');

        // the survivor is primary: it stays, the duplicate's star does not survive the merge
        $keep = $this->person(['is_primary' => 1]);
        $dupe = $this->person();
        $this->merge($keep, [$dupe])->assertOk();
        $this->assertSame([1, 0], [$star($keep), $star($dupe)]);
        $this->assertSame([$keep], DB::table('crm_contacts')->where('organization_id', $this->acme)->where('is_primary', 1)->pluck('id')->map(fn ($i) => (int) $i)->all());

        // the duplicate is primary, the survivor is not and the company has no other: the survivor takes it
        $acme2 = $this->makeOrganization(self::T, ['name' => 'Acme 2']);
        $keep2 = $this->makeContact(self::T, ['organization_id' => $acme2]);
        $dupe2 = $this->makeContact(self::T, ['organization_id' => $acme2, 'is_primary' => 1]);
        $this->merge($keep2, [$dupe2])->assertOk();
        $this->assertSame([1, 0], [$star($keep2), $star($dupe2)]);

        // the duplicate is primary but ANOTHER person of the company already is: that one keeps it, the survivor gets nothing
        $acme3 = $this->makeOrganization(self::T, ['name' => 'Acme 3']);
        $keep3 = $this->makeContact(self::T, ['organization_id' => $acme3]);
        $someone = $this->makeContact(self::T, ['organization_id' => $acme3, 'is_primary' => 1]);
        $dupe3 = $this->makeContact(self::T, ['organization_id' => $acme3]);
        DB::table('crm_contacts')->where('id', $dupe3)->update(['is_primary' => 1]);
        $this->merge($keep3, [$dupe3])->assertOk();
        $this->assertSame([0, 1, 0], [$star($keep3), $star($someone), $star($dupe3)]);
    }

    public function test_the_survivor_fills_its_blank_role_from_the_duplicate_and_keeps_its_own(): void
    {
        $keep = $this->person(['contact_role' => null]);
        $dupe = $this->person(['contact_role' => 'Billing']);
        $this->merge($keep, [$dupe])->assertOk();
        $this->assertSame('Billing', DB::table('crm_contacts')->where('id', $keep)->value('contact_role'));

        $keep2 = $this->person(['contact_role' => 'Technical']);
        $dupe2 = $this->person(['contact_role' => 'Billing']);
        $this->merge($keep2, [$dupe2])->assertOk();
        $this->assertSame('Technical', DB::table('crm_contacts')->where('id', $keep2)->value('contact_role'));
    }

    public function test_merging_two_companies_leaves_one_primary_among_their_people(): void
    {
        $star = fn (int $company): array => DB::table('crm_contacts')->where('organization_id', $company)->where('is_primary', 1)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $mergeCompanies = fn (int $survivor, int $duplicate) => $this->postJson('/api/crm/organizations/merge', ['survivorId' => $survivor, 'duplicateIds' => [$duplicate]], $this->crmUser()['h'])->assertOk();

        // both companies had a primary: the survivor company's own keeps the star, the other person loses it
        $keep = $this->makeOrganization(self::T, ['name' => 'Keep Co']);
        $dupe = $this->makeOrganization(self::T, ['name' => 'Dupe Co']);
        $keepStar = $this->makeContact(self::T, ['organization_id' => $keep, 'is_primary' => 1]);
        $dupeStar = $this->makeContact(self::T, ['organization_id' => $dupe, 'is_primary' => 1]);
        $plain = $this->makeContact(self::T, ['organization_id' => $dupe]);
        $mergeCompanies($keep, $dupe);
        $this->assertSame([$keep, $keep, $keep], array_map(fn ($c) => (int) DB::table('crm_contacts')->where('id', $c)->value('organization_id'), [$keepStar, $dupeStar, $plain]));
        $this->assertSame([$keepStar], $star($keep), 'one primary in the merged company, the survivor company\'s own');

        // only the removed company had one: it keeps it in the survivor
        $keep2 = $this->makeOrganization(self::T, ['name' => 'Keep Two']);
        $dupe2 = $this->makeOrganization(self::T, ['name' => 'Dupe Two']);
        $only = $this->makeContact(self::T, ['organization_id' => $dupe2, 'is_primary' => 1]);
        $mergeCompanies($keep2, $dupe2);
        $this->assertSame([$only], $star($keep2));
    }
    // ── history ──────────────────────────────────────────────────────────────

    public function test_a_merge_is_in_the_history_of_the_survivor_and_of_the_removed_contact(): void
    {
        $keep = $this->person();
        $dupe = $this->person();

        $this->merge($keep, [$dupe])->assertOk();

        $types = fn (int $id) => DB::table('g2g_event')->where('entity_type', 'crm_contacts')->where('entity_id', $id)->pluck('type')->all();
        $this->assertContains('crm.contact.merged', $types($keep));
        $this->assertContains('crm.contact.merged_away', $types($dupe));
        $merged = json_decode((string) DB::table('g2g_event')->where('type', 'crm.contact.merged_away')->value('payload'), true)['changes'];
        $this->assertContains('merged_into_id', array_column($merged, 'field'));
    }

    public function test_the_merge_still_needs_the_delete_right_on_contacts(): void
    {
        $keep = $this->person();
        $dupe = $this->person();
        $editor = $this->user([self::CONTACTS_LINK => ['view', 'edit']]);

        $this->merge($keep, [$dupe], $editor)->assertStatus(403);
        $this->assertNull(DB::table('crm_contacts')->where('id', $dupe)->value('deleted_at'));
    }
}
