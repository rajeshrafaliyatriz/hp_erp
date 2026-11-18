<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\Rejected;
use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRecords;
use App\Domain\Ask\AskRow;
use App\Domain\Ask\AskToolRegistry;
use App\Domain\Ask\ToolResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAskWorld;
use Tests\TestCase;

/**
 * The five read-only lookups of the Ask chat (slice 8), one by one: what each returns, that it reads the caller's organisation only, that it checks the right
 * of the kind of record it opens for the caller's own profile, that its results are capped, that "nothing found" is a result, and that its arguments are
 * validated on the server. The planner and the answerer are tested in AskPipelineTest, the API in AskApiTest.
 */
class AskToolsTest extends TestCase
{
    use BuildsAskWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAskWorld();
    }

    /** @return list<string> */
    private function keys(ToolResult $r): array
    {
        return array_map(fn (AskRow $row) => $row->refKey(), $r->rows);
    }

    private function everything(): AskCaller
    {
        return $this->callerOf($this->asker());
    }

    // ---- the registry ----

    public function test_the_registry_lists_exactly_five_read_only_lookups_and_none_can_send_delete_or_write(): void
    {
        $this->assertSame(['search_records', 'get_record', 'list_signals', 'list_products', 'pipeline_summary'], AskToolRegistry::names());
        foreach (AskToolRegistry::names() as $name) {
            $this->assertDoesNotMatchRegularExpression('/send|mail|sms|delete|remove|merge|create|add|update|write|set_|insert|note|task|link/i', $name, "{$name} must not be a tool that writes");
        }
        $registry = new AskToolRegistry();
        $this->assertCount(5, $registry->all());
        $this->assertNull($registry->find('add_note'), 'the Organisation agent\'s tools are not Ask tools');
        $this->assertNull($registry->find('send_email'));
        $this->assertNull($registry->find(''));
    }

    public function test_nothing_in_the_ask_code_can_write_a_record_send_a_message_or_call_out(): void
    {
        $files = array_merge(glob(app_path('Domain/Ask/*.php')) ?: [], glob(app_path('Domain/Ask/Tools/*.php')) ?: [], [app_path('Http/Controllers/Api/Agent/AskController.php')]);
        $this->assertGreaterThanOrEqual(20, count($files));
        $outward = '/\b(Http::|Mail::|Notification::|Queue::|Bus::|Event::|dispatch\(|curl_|fsockopen|file_put_contents|fopen\(|Storage::|Artisan::|shell_exec|exec\(|system\(|passthru|proc_open|mail\()/';
        $writes = '/(->insert\(|->insertGetId\(|->insertOrIgnore\(|->update\(|->upsert\(|->delete\(|->truncate\(|->increment\(|->decrement\(|->save\(|->create\(|->forceDelete\(|DB::statement|DB::unprepared|Schema::(create|table|drop|dropIfExists|rename))/';
        // The only files that write anything: the service (its own conversation, message and run rows) and nothing else.
        $mayWrite = ['AskService.php'];
        $tables = ['g2g_agent_conversations', 'g2g_agent_messages', 'g2g_agent_runs'];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);   // comments say what the code does not do
            $this->assertDoesNotMatchRegularExpression($outward, $code, basename($file) . ' reaches outside the database');
            if (! in_array(basename($file), $mayWrite, true)) {
                $this->assertDoesNotMatchRegularExpression($writes, $code, basename($file) . ' writes');

                continue;
            }
            foreach (explode(';', $code) as $statement) {
                if (preg_match($writes, $statement) === 1) {
                    $this->assertSame(1, preg_match('/DB::table\(\'(' . implode('|', $tables) . ')\'\)/', $statement), basename($file) . ' writes to a table that is not the chat\'s own: ' . trim($statement));
                }
            }
        }
        // The service writes its own rows and the audit trail (through AgentAudit): and nothing of the CRM.
        $service = (string) file_get_contents(app_path('Domain/Ask/AskService.php'));
        foreach (['crm_leads', 'crm_contacts', 'crm_organizations', 'crm_opportunities', 'g2g_company_opportunities', 'task_management_calendar_events'] as $crmTable) {
            $this->assertStringNotContainsString($crmTable, $service, "the service never names {$crmTable}");
        }
    }

    // ---- arguments ----

    public function test_arguments_are_validated_and_a_key_that_is_not_an_argument_rejects_the_whole_call(): void
    {
        $caller = $this->everything();
        $registry = new AskToolRegistry();
        $bad = [
            ['search_records', ['text' => 'Tapovan', 'sub_institute_id' => 2], 'unknown_argument'],
            ['search_records', ['text' => 'Tapovan', 'user_id' => 5], 'unknown_argument'],
            ['search_records', ['text' => 'Tapovan', 'where' => '1=1'], 'unknown_argument'],
            ['search_records', ['type' => 'user', 'text' => 'Tapovan'], 'bad_type'],
            ['search_records', ['type' => ['lead'], 'text' => 'Tapovan'], 'bad_type'],
            ['search_records', ['text' => 'a'], 'bad_text'],
            ['search_records', ['text' => str_repeat('x', 101)], 'bad_text'],
            ['search_records', [], 'bad_text'],
            ['search_records', ['text' => ['a', 'b']], 'bad_text'],
            ['search_records', ['a', 'b'], 'bad_arguments'],
            ['get_record', ['type' => 'lead'], 'bad_id'],
            ['get_record', ['type' => 'lead', 'id' => 'abc'], 'bad_id'],
            ['get_record', ['type' => 'lead', 'id' => 0], 'bad_id'],
            ['get_record', ['type' => 'lead', 'id' => -4], 'bad_id'],
            ['get_record', ['type' => 'lead', 'id' => '4 OR 1=1'], 'bad_id'],
            ['get_record', ['type' => 'lead', 'id' => '$4'], 'bad_id'],
            ['get_record', ['type' => 'signal', 'id' => 1], 'bad_type'],
            ['get_record', ['type' => 'lead', 'id' => 1, 'tenant' => 2], 'unknown_argument'],
            ['list_signals', ['status' => 'Archived'], 'bad_status'],
            ['list_signals', ['since' => '2026-13-45'], 'bad_date'],
            ['list_signals', ['since' => 'last month'], 'bad_date'],
            ['list_signals', ['since' => '2026-11-01', 'until' => '2026-10-01'], 'bad_date_range'],
            ['list_signals', ['product_key' => 'no_such_product'], 'unknown_product'],
            ['list_signals', ['sub_institute_id' => 2], 'unknown_argument'],
            ['list_products', ['all_tenants' => true], 'unknown_argument'],
            ['pipeline_summary', ['tenant' => 2], 'unknown_argument'],
        ];
        foreach ($bad as [$name, $args, $reason]) {
            try {
                $registry->find($name)->validate($caller, $args);
                $this->fail("{$name} " . json_encode($args) . ' was accepted');
            } catch (Rejected $r) {
                $this->assertSame($reason, $r->reason, "{$name} " . json_encode($args));
            }
        }
    }

    public function test_good_arguments_are_normalised_the_way_the_tool_uses_them(): void
    {
        $caller = $this->everything();
        $registry = new AskToolRegistry();

        $this->assertSame(['type' => 'organization', 'text' => 'Tapovan'], $registry->find('search_records')->validate($caller, ['type' => 'Organisations', 'text' => '  Tapovan  ']));
        $this->assertSame(['type' => null, 'text' => 'Tapovan School'], $registry->find('search_records')->validate($caller, ['text' => "Tapovan\n  School"]));
        $this->assertSame(['type' => 'opportunity', 'id' => 7, 'from_call' => null], $registry->find('get_record')->validate($caller, ['type' => 'deal', 'id' => '7']));
        $this->assertSame(['type' => 'organization', 'id' => null, 'from_call' => 2], $registry->find('get_record')->validate($caller, ['type' => 'organization', 'id' => '$2']));
        $this->assertSame(['product_key' => 'scholarclone', 'status' => 'Follow-up', 'since' => '2026-10-01', 'until' => '2026-10-31'],
            $registry->find('list_signals')->validate($caller, ['product_key' => 'ScholarClone', 'status' => 'follow up', 'since' => '2026-10-01', 'until' => '2026-10-31']));
        $this->assertSame(['product_key' => null, 'status' => null, 'since' => null, 'until' => null], $registry->find('list_signals')->validate($caller, null));
        $this->assertSame([], $registry->find('list_products')->validate($caller, []));
    }

    // ---- search_records ----

    public function test_search_finds_each_kind_by_name_email_or_number_and_every_word_must_match(): void
    {
        $caller = $this->everything();
        $org = $this->makeOrg(self::T, 'Tapovan Vidya Vihar');
        $lead = $this->lead(self::T, 'Asha', 'Rao', ['company' => 'Tapovan Trust', 'lead_no' => 'LEA-0007']);
        $contact = $this->contact(self::T, 'Meera', 'Shah', $org['crm']);
        $opp = $this->opportunity(self::T, 'Tapovan renewal 2027', $org['crm'], ['opportunity_no' => 'OPP-0042']);

        $this->assertEqualsCanonicalizing(["organization:{$org['crm']}", "lead:{$lead}", "opportunity:{$opp}"], $this->keys($this->lookup($caller, 'search_records', ['text' => 'tapovan'])));
        $this->assertSame(["lead:{$lead}"], $this->keys($this->lookup($caller, 'search_records', ['text' => 'asha.rao@example'])), 'by email');
        $this->assertSame(["lead:{$lead}"], $this->keys($this->lookup($caller, 'search_records', ['text' => 'LEA-0007'])), 'by number');
        $this->assertSame(["opportunity:{$opp}"], $this->keys($this->lookup($caller, 'search_records', ['text' => 'opp-0042'])), 'by number, any case');
        $this->assertSame(["lead:{$lead}"], $this->keys($this->lookup($caller, 'search_records', ['text' => 'Asha Rao'])), 'two words, first and last name');
        $this->assertSame([], $this->keys($this->lookup($caller, 'search_records', ['text' => 'Asha Shah'])), 'every word must match: Asha is a lead, Shah is a contact');
        $this->assertSame(["contact:{$contact}"], $this->keys($this->lookup($caller, 'search_records', ['type' => 'contact', 'text' => 'meera'])));
    }

    public function test_a_row_is_a_ref_with_type_id_label_and_a_page_address_and_shows_no_contact_detail(): void
    {
        $caller = $this->everything();
        $lead = $this->lead(self::T, 'Asha', 'Rao', ['email' => 'asha.private@example.test', 'phone' => '+91 98765 43210', 'secondary_email' => 'other@example.test']);
        $contact = $this->contact(self::T, 'Meera', 'Shah', null, ['email' => 'meera.private@example.test', 'mobile' => '+91 90909 09090']);

        $leadRow = $this->lookup($caller, 'search_records', ['type' => 'lead', 'text' => 'Asha'])->rows[0];
        $contactRow = $this->lookup($caller, 'search_records', ['type' => 'contact', 'text' => 'Meera'])->rows[0];

        $this->assertSame(['type' => 'lead', 'id' => (string) $lead, 'label' => 'Asha Rao (Acme School)', 'url' => "/module/crm/marketing/leads/{$lead}"], $leadRow->ref());
        $this->assertSame(['type' => 'contact', 'id' => (string) $contact, 'label' => 'Meera Shah', 'url' => "/module/crm/marketing/contacts/{$contact}"], $contactRow->ref());
        foreach ([$leadRow, $contactRow] as $row) {
            $line = $row->line();
            foreach (['private@example', '98765', '90909', 'other@example', '+91'] as $secret) {
                $this->assertStringNotContainsString($secret, $line, 'a contact detail reaches the model');
            }
            $this->assertTrue($row->data['email_on_file'], 'only whether one exists');
            $this->assertTrue($row->data['phone_on_file']);
            $this->assertArrayHasKey('not_shown_to_you', $row->data);
        }
    }

    public function test_search_reads_only_the_callers_organisation_for_every_kind_of_record(): void
    {
        $caller = $this->everything();
        $mine = $this->makeOrg(self::T, 'Greenfield School');
        $theirs = $this->makeOrg(self::T2, 'Greenfield Academy');
        $this->lead(self::T2, 'Greenfield', 'Lead');
        $this->contact(self::T2, 'Greenfield', 'Contact', $theirs['crm']);
        $this->opportunity(self::T2, 'Greenfield deal', $theirs['crm']);
        DB::table('crm_leads')->insert(['first_name' => 'Greenfield', 'last_name' => 'Orphan', 'sub_institute_id' => null, 'converted' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->lookup($caller, 'search_records', ['text' => 'greenfield']);

        $this->assertSame(["organization:{$mine['crm']}"], $this->keys($result), "only this organisation's record: not the other's organisation, lead, contact or deal, and not a lead with no organisation at all");
        $this->assertSame(1, $result->total);
        $this->assertStringNotContainsString('Academy', json_encode(array_map(fn ($r) => $r->line(), $result->rows)));
    }

    public function test_an_owner_or_an_organisation_name_is_shown_only_when_it_belongs_to_the_callers_organisation(): void
    {
        $asker = $this->asker();
        $caller = $this->callerOf($asker);
        $colleague = $this->asker(self::T, null, 'hr_manager', 'Kiran', 'Owner');
        $stranger = $this->asker(self::T2, null, 'hr_manager', 'Mallory', 'Villain');
        $mine = $this->makeOrg(self::T, 'Mine Org');
        $theirs = $this->makeOrg(self::T2, 'Their Secret Org');

        $own = $this->lead(self::T, 'Owned', 'ByColleague', ['assigned_to' => $colleague['id']]);
        $stray = $this->lead(self::T, 'Owned', 'ByStranger', ['assigned_to' => $stranger['id']]);
        $contactOfMine = $this->contact(self::T, 'Owned', 'Contact', $mine['crm']);
        $contactStray = $this->contact(self::T, 'Owned', 'Stray', $theirs['crm']);

        $rows = collect($this->lookup($caller, 'search_records', ['text' => 'owned'])->rows)->keyBy(fn ($r) => $r->refKey());

        $this->assertSame('Kiran Owner', $rows["lead:{$own}"]->data['owner']);
        $this->assertNull($rows["lead:{$stray}"]->data['owner'], 'a person of another organisation is not named, whatever the pointer says');
        $this->assertSame('Mine Org', $rows["contact:{$contactOfMine}"]->data['organisation']);
        $this->assertNull($rows["contact:{$contactStray}"]->data['organisation'], "another organisation's organisation is not named");
        $this->assertStringNotContainsString('Mallory', json_encode(array_map(fn ($r) => $r->line(), $rows->all())));
        $this->assertStringNotContainsString('Secret', json_encode(array_map(fn ($r) => $r->line(), $rows->all())));
    }

    public function test_search_leaves_out_deleted_records_and_converted_leads(): void
    {
        $caller = $this->everything();
        $this->lead(self::T, 'Dora', 'Deleted', ['deleted_at' => now()]);
        $this->lead(self::T, 'Cora', 'Converted', ['converted' => 1]);
        $live = $this->lead(self::T, 'Lara', 'Live');
        $deletedContact = $this->contact(self::T, 'Dora', 'Gone');
        DB::table('crm_contacts')->where('id', $deletedContact)->update(['deleted_at' => now()]);
        $org = $this->makeOrg(self::T, 'Dora Deleted Trust');
        DB::table('crm_organizations')->where('id', $org['crm'])->update(['deleted_at' => now()]);

        $this->assertSame([], $this->keys($this->lookup($caller, 'search_records', ['text' => 'dora'])));
        $this->assertSame([], $this->keys($this->lookup($caller, 'search_records', ['text' => 'cora'])), 'a converted lead is a soft archive: the lead list hides it, so does the search');
        $this->assertSame(["lead:{$live}"], $this->keys($this->lookup($caller, 'search_records', ['text' => 'lara'])));
    }

    public function test_search_checks_the_right_of_each_kind_of_record_for_the_callers_profile(): void
    {
        $this->makeOrg(self::T, 'Zenith Org');
        $lead = $this->lead(self::T, 'Zenith', 'Lead');
        $this->contact(self::T, 'Zenith', 'Contact');
        $this->opportunity(self::T, 'Zenith deal');

        // May ask, may open leads only.
        $leadsOnly = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::LEADS_LINK => ['view']]));
        $all = $this->lookup($leadsOnly, 'search_records', ['text' => 'zenith']);
        $this->assertSame(ToolResult::OK, $all->status);
        $this->assertSame(["lead:{$lead}"], $this->keys($all), 'the lead is found; nothing of the kinds the profile cannot open is read');
        $this->assertEqualsCanonicalizing(['contacts', 'organisations', 'opportunities'], $all->withheld);
        $this->assertStringNotContainsString('contacts', $all->searched, 'the "nothing found" sentence never claims a search that was not made');

        $one = $this->lookup($leadsOnly, 'search_records', ['type' => 'contact', 'text' => 'zenith']);
        $this->assertSame(ToolResult::NOT_PERMITTED, $one->status);
        $this->assertSame([], $one->rows);
        $this->assertSame(['contacts'], $one->withheld);

        // May ask and open nothing.
        $none = $this->lookup($this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view']])), 'search_records', ['text' => 'zenith']);
        $this->assertSame(ToolResult::NOT_PERMITTED, $none->status);
        $this->assertSame([], $none->rows);

        // The Support row of contacts and organisations opens them too (the CRM's own routes accept either row).
        $support = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::CONTACTS_SUPPORT_LINK => ['view'], self::ORG_LINK_SUPPORT => ['view']]));
        $this->assertEqualsCanonicalizing(['contact', 'organization'], array_unique(array_map(fn ($r) => $r->type, $this->lookup($support, 'search_records', ['text' => 'zenith'])->rows)));

        // A right that is only EDIT is not a right to read.
        $editOnly = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::LEADS_LINK => ['edit']]));
        $this->assertSame(ToolResult::NOT_PERMITTED, $this->lookup($editOnly, 'search_records', ['type' => 'lead', 'text' => 'zenith'])->status);
    }

    public function test_another_organisations_profile_right_does_not_open_this_ones_records(): void
    {
        // Rights rows are looked up by the profile of the caller; a profile of organisation 2 holds nothing on organisation 1's records because it cannot reach them at all.
        $this->lead(self::T, 'Priya', 'Mine');
        $outsider = $this->callerOf($this->asker(self::T2));
        $this->assertSame([], $this->keys($this->lookup($outsider, 'search_records', ['text' => 'priya'])), 'the tenant is the caller\'s own, whatever the rights');
    }

    public function test_search_returns_at_most_ten_rows_shares_them_between_the_kinds_and_says_how_many_matched(): void
    {
        $caller = $this->everything();
        for ($i = 1; $i <= 7; $i++) {
            $this->lead(self::T, "Zed{$i}", 'Lead');
            $this->contact(self::T, "Zed{$i}", 'Contact');
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->makeOrg(self::T, "Zed Org {$i}");
        }

        $result = $this->lookup($caller, 'search_records', ['text' => 'zed']);

        $this->assertCount(10, $result->rows, 'the cap');
        $this->assertSame(18, $result->total, 'but the answer can say how many matched');
        $types = array_map(fn ($r) => $r->type, $result->rows);
        $this->assertSame(['lead', 'contact', 'organization'], array_slice($types, 0, 3), 'one of each kind in turn, so the first kind does not use up every place');
        $this->assertGreaterThanOrEqual(3, count(array_filter($types, fn ($t) => $t === 'organization')));
        $this->assertSame(10, count($this->lookup($caller, 'search_records', ['type' => 'lead', 'text' => 'zed'])->rows) + 3, 'a single kind is cut at ten too (seven here)');
    }

    public function test_search_with_nothing_found_is_a_result_with_a_sentence_to_say_so(): void
    {
        $caller = $this->everything();
        $result = $this->lookup($caller, 'search_records', ['text' => 'nobody at all']);

        $this->assertSame(ToolResult::OK, $result->status);
        $this->assertSame([], $result->rows);
        $this->assertSame(0, $result->total);
        $this->assertSame('in leads, contacts, organisations and opportunities matching "nobody at all"', $result->searched);
    }

    public function test_a_wildcard_typed_by_the_person_matches_only_itself(): void
    {
        $caller = $this->everything();
        $this->lead(self::T, 'Percent', 'Sign', ['company' => '100% Schools']);
        $this->lead(self::T, 'Other', 'Person', ['company' => 'Plain Co']);

        $this->assertSame(1, count($this->lookup($caller, 'search_records', ['type' => 'lead', 'text' => '100%'])->rows));
        $this->assertSame([], $this->lookup($caller, 'search_records', ['type' => 'lead', 'text' => '%%'])->rows, 'a bare % is not "everything"');
        $this->assertSame([], $this->lookup($caller, 'search_records', ['type' => 'lead', 'text' => 'Pl_in'])->rows, '_ is not "any character"');
        $this->assertSame([], $this->lookup($caller, 'search_records', ['type' => 'lead', 'text' => "x' OR '1'='1"])->rows, 'text is a bound value, never SQL');
    }

    // ---- get_record ----

    public function test_get_record_opens_an_organisation_with_its_open_deals_contacts_tasks_and_signals_as_rows_of_their_own(): void
    {
        $caller = $this->everything();
        $org = $this->makeOrg(self::T, 'Tapovan Vidya Vihar', ['signals' => 2]);
        $open = $this->opportunity(self::T, 'Open deal', $org['crm'], ['sales_stage' => 'Negotiation']);
        $won = $this->opportunity(self::T, 'Won deal', $org['crm'], ['sales_stage' => 'Closed Won']);
        $lost = $this->opportunity(self::T, 'Lost deal', $org['crm'], ['sales_stage' => 'Closed Lost']);
        $contact = $this->contact(self::T, 'Meera', 'Shah', $org['crm']);
        $this->note('crm_organization_comments', 'organization_id', $org['crm'], self::T, 'Called the principal; wants a demo in December.');
        DB::table('crm_organizations')->where('id', $org['crm'])->update(['description' => 'A school in Ahmedabad.']);
        DB::table('task_management_calendar_events')->insert([
            'sub_institute_id' => self::T, 'syear' => '2026', 'title' => 'Send the proposal', 'start_at' => '2026-11-10 10:00:00', 'end_at' => '2026-11-10 11:00:00', 'status' => 'Planned',
            'visibility' => 'PUBLIC', 'owner_id' => 99, 'linked_type' => 'ORGANIZATION', 'linked_id' => $org['crm'], 'created_by' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->lookup($caller, 'get_record', ['type' => 'organization', 'id' => $org['crm']]);
        $keys = $this->keys($result);

        $this->assertSame("organization:{$org['crm']}", $keys[0], 'the record first');
        $this->assertContains("opportunity:{$open}", $keys);
        $this->assertNotContains("opportunity:{$won}", $keys, 'a deal that is won is not open');
        $this->assertNotContains("opportunity:{$lost}", $keys, 'nor one that is lost');
        $this->assertContains("contact:{$contact}", $keys);
        $this->assertCount(2, array_filter($keys, fn ($k) => str_starts_with($k, 'signal:')), 'the organisation\'s signals');
        $this->assertCount(1, array_filter($keys, fn ($k) => str_starts_with($k, 'task:')), 'its open task');
        $first = $result->rows[0];
        $this->assertSame('A school in Ahmedabad.', $first->data['description']);
        $this->assertSame('Called the principal; wants a demo in December.', $first->data['recent_notes'][0]['text']);
        $this->assertSame([], $result->withheld);
    }

    public function test_get_record_reads_other_organisations_and_deleted_records_as_nothing_found(): void
    {
        $caller = $this->everything();
        $theirs = $this->makeOrg(self::T2, 'Elsewhere Org');
        $theirLead = $this->lead(self::T2, 'Else', 'Where');
        $theirDeal = $this->opportunity(self::T2, 'Else deal');
        $gone = $this->lead(self::T, 'Gone', 'Lead', ['deleted_at' => now()]);

        foreach ([['organization', $theirs['crm']], ['lead', $theirLead], ['opportunity', $theirDeal], ['lead', $gone], ['contact', 999999]] as [$type, $id]) {
            $r = $this->lookup($caller, 'get_record', ['type' => $type, 'id' => $id]);
            $this->assertSame(ToolResult::OK, $r->status, "{$type} {$id}");
            $this->assertSame([], $r->rows, "{$type} {$id}: the same answer as a record that never existed");
        }
    }

    public function test_the_things_that_hang_off_a_record_are_the_callers_organisations_only(): void
    {
        $caller = $this->everything();
        $org = $this->makeOrg(self::T, 'Shared Id Org');
        // Rows of ANOTHER organisation that point at this organisation's id (a bad import, a hand edit): none of it may show.
        $strayDeal = $this->opportunity(self::T2, 'Stray deal', $org['crm']);
        $strayContact = $this->contact(self::T2, 'Stray', 'Contact', $org['crm']);
        DB::table('task_management_calendar_events')->insert([
            'sub_institute_id' => self::T2, 'syear' => '2026', 'title' => 'Stray task', 'start_at' => '2026-11-10 10:00:00', 'end_at' => '2026-11-10 11:00:00', 'status' => 'Planned',
            'visibility' => 'PUBLIC', 'owner_id' => 1, 'linked_type' => 'ORGANIZATION', 'linked_id' => $org['crm'], 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->note('crm_organization_comments', 'organization_id', $org['crm'], self::T2, 'A note of the other organisation.');

        $result = $this->lookup($caller, 'get_record', ['type' => 'organization', 'id' => $org['crm']]);

        $this->assertSame(["organization:{$org['crm']}"], $this->keys($result));
        $this->assertSame([], $result->rows[0]->data['recent_notes'] ?? []);
        $this->assertStringNotContainsString('Stray', json_encode(array_map(fn ($r) => $r->line(), $result->rows)));
        $this->assertStringNotContainsString('other organisation', json_encode(array_map(fn ($r) => $r->line(), $result->rows)));
        $this->assertNotContains("opportunity:{$strayDeal}", $this->keys($result));
        $this->assertNotContains("contact:{$strayContact}", $this->keys($result));
    }

    public function test_a_private_task_of_somebody_else_is_not_listed_but_the_callers_own_is(): void
    {
        $asker = $this->asker();
        $caller = $this->callerOf($asker);
        $org = $this->makeOrg(self::T, 'Task Org');
        $task = fn (string $title, string $visibility, int $owner) => DB::table('task_management_calendar_events')->insertGetId([
            'sub_institute_id' => self::T, 'syear' => '2026', 'title' => $title, 'start_at' => '2026-11-10 10:00:00', 'end_at' => '2026-11-10 11:00:00', 'status' => 'Planned',
            'visibility' => $visibility, 'owner_id' => $owner, 'linked_type' => 'ORGANIZATION', 'linked_id' => $org['crm'], 'created_by' => $owner, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $public = $task('Public task', 'PUBLIC', 99);
        $theirPrivate = $task('Private task of somebody else', 'PRIVATE', 99);
        $myPrivate = $task('My own private task', 'PRIVATE', $caller->userId);
        DB::table('task_management_calendar_events')->insert(['sub_institute_id' => self::T, 'syear' => '2026', 'title' => 'Done task', 'start_at' => '2026-11-01 10:00:00', 'end_at' => '2026-11-01 11:00:00',
            'status' => 'Completed', 'visibility' => 'PUBLIC', 'owner_id' => 99, 'linked_type' => 'ORGANIZATION', 'linked_id' => $org['crm'], 'created_by' => 99, 'created_at' => now(), 'updated_at' => now()]);

        $keys = $this->keys($this->lookup($caller, 'get_record', ['type' => 'organization', 'id' => $org['crm']]));

        $this->assertContains("task:{$public}", $keys);
        $this->assertContains("task:{$myPrivate}", $keys);
        $this->assertNotContains("task:{$theirPrivate}", $keys);
        $this->assertCount(2, array_filter($keys, fn ($k) => str_starts_with($k, 'task:')), 'and a finished task is not open');
    }

    public function test_get_record_checks_the_right_of_the_kind_it_opens_and_of_each_list_under_it(): void
    {
        $org = $this->makeOrg(self::T, 'Rights Org', ['signals' => 1]);
        $deal = $this->opportunity(self::T, 'Rights deal', $org['crm']);
        $this->contact(self::T, 'Rita', 'Contact', $org['crm']);

        // Organisations only (an employee: no Signals right while the menu rights for Signals are off).
        $orgsOnly = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::ORG_LINK => ['view']], 'employee'));
        $result = $this->lookup($orgsOnly, 'get_record', ['type' => 'organization', 'id' => $org['crm']]);
        $this->assertSame(["organization:{$org['crm']}"], $this->keys($result), 'the organisation, and nothing that needs a right the profile does not hold');
        $this->assertEqualsCanonicalizing(['opportunities', 'contacts', 'signals'], $result->withheld, 'and it is said');

        // Cannot open the kind itself.
        $noOrgs = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::OPPS_LINK => ['view']]));
        $denied = $this->lookup($noOrgs, 'get_record', ['type' => 'organization', 'id' => $org['crm']]);
        $this->assertSame(ToolResult::NOT_PERMITTED, $denied->status);
        $this->assertSame([], $denied->rows);
        $this->assertSame(['organisations'], $denied->withheld);
        $this->assertSame(["opportunity:{$deal}"], $this->keys($this->lookup($noOrgs, 'get_record', ['type' => 'opportunity', 'id' => $deal])), 'a deal opens, but its organisation (a kind it cannot open) is withheld');
        $this->assertSame(['organisations'], $this->lookup($noOrgs, 'get_record', ['type' => 'opportunity', 'id' => $deal])->withheld);
    }

    public function test_an_opportunity_a_contact_and_a_lead_open_with_what_they_point_at(): void
    {
        $caller = $this->everything();
        $org = $this->makeOrg(self::T, 'Parent Org', ['signals' => 1]);
        $deal = $this->opportunity(self::T, 'Parent deal', $org['crm'], ['amount' => 1200000, 'currency' => 'RUP', 'next_step' => 'Send the contract']);
        $contact = $this->contact(self::T, 'Kiran', 'Patel', $org['crm']);
        $lead = $this->lead(self::T, 'Lia', 'Lead', ['source_company_opportunity_id' => $org['signals'][0]]);

        $dealRows = $this->lookup($caller, 'get_record', ['type' => 'opportunity', 'id' => $deal])->rows;
        $this->assertSame(["opportunity:{$deal}", "organization:{$org['crm']}"], array_map(fn ($r) => $r->refKey(), $dealRows));
        $this->assertSame('INR', $dealRows[0]->data['currency'], 'RUP is the legacy picklist word for rupees');
        $this->assertSame(1200000.0, (float) $dealRows[0]->data['amount']);
        $this->assertSame('Send the contract', $dealRows[0]->data['next_step']);
        $this->assertSame('Parent Org', $dealRows[0]->data['organisation']);

        $this->assertSame(["contact:{$contact}", "organization:{$org['crm']}"], $this->keys($this->lookup($caller, 'get_record', ['type' => 'contact', 'id' => $contact])));
        $this->assertSame(["lead:{$lead}", "signal:{$org['signals'][0]}"], $this->keys($this->lookup($caller, 'get_record', ['type' => 'lead', 'id' => $lead])), 'a lead points at the signal it came from');
    }

    public function test_an_unresolved_reference_opens_nothing(): void
    {
        $caller = $this->everything();
        $r = $this->lookup($caller, 'get_record', ['type' => 'organization', 'id' => '$1']);

        $this->assertSame(ToolResult::OK, $r->status);
        $this->assertSame([], $r->rows);
    }

    // ---- list_signals ----

    private function signalsWorld(): array
    {
        $a = $this->makeOrg(self::T, 'Alpha School', ['signals' => 3]);
        $b = $this->makeOrg(self::T2, 'Beta School', ['signals' => 2]);
        [$s1, $s2, $s3] = $a['signals'];
        DB::table('g2g_company_opportunities')->where('id', $s1)->update(['title' => 'Alpha hires a principal', 'business_fit' => 'scholar', 'product_key' => 'scholarclone', 'review_status' => 'Dismissed',
            'reviewed_at' => '2026-10-20 10:00:00', 'first_discovered_at' => '2026-10-01 10:00:00']);
        DB::table('g2g_company_opportunities')->where('id', $s2)->update(['title' => 'Alpha opens a campus', 'business_fit' => 'scholar', 'product_key' => 'scholarclone', 'review_status' => 'New',
            'first_discovered_at' => '2026-10-25 10:00:00']);
        DB::table('g2g_company_opportunities')->where('id', $s3)->update(['title' => 'Alpha buys software', 'business_fit' => 'eb', 'product_key' => 'enterprise_brain', 'review_status' => 'Follow-up',
            'reviewed_at' => '2026-11-02 10:00:00', 'first_discovered_at' => '2026-09-01 10:00:00']);
        DB::table('g2g_signal_reviews')->insert(['sub_institute_id' => self::T, 'opportunity_id' => $s1, 'decision' => 'dismissed', 'reason_code' => 'not_a_buyer', 'note' => 'A government school.',
            'from_status' => 'New', 'to_status' => 'Dismissed', 'decided_by' => 1, 'decided_at' => '2026-10-20 10:00:00', 'created_at' => now()]);

        return [$a, $b, $s1, $s2, $s3];
    }

    public function test_list_signals_filters_by_product_status_and_dates_and_a_dismissal_carries_its_reason(): void
    {
        [, , $s1, $s2, $s3] = $this->signalsWorld();
        $caller = $this->everything();

        $this->assertSame(["signal:{$s3}", "signal:{$s2}", "signal:{$s1}"], $this->keys($this->lookup($caller, 'list_signals')), 'newest first by the date last decided on, or found');
        $this->assertSame(["signal:{$s1}"], $this->keys($this->lookup($caller, 'list_signals', ['product_key' => 'scholarclone', 'status' => 'Dismissed', 'since' => '2026-10-01', 'until' => '2026-10-31'])), 'dismissed for ScholarClone in October');
        $this->assertSame(["signal:{$s2}", "signal:{$s1}"], $this->keys($this->lookup($caller, 'list_signals', ['product_key' => 'ScholarClone'])), 'the product by its name or its stored fit');
        $this->assertSame(["signal:{$s2}"], $this->keys($this->lookup($caller, 'list_signals', ['status' => 'New'])));
        $this->assertSame(["signal:{$s3}"], $this->keys($this->lookup($caller, 'list_signals', ['status' => 'Follow-up'])));
        $this->assertSame(["signal:{$s3}"], $this->keys($this->lookup($caller, 'list_signals', ['since' => '2026-11-01'])));
        $this->assertSame(["signal:{$s1}"], $this->keys($this->lookup($caller, 'list_signals', ['since' => '2026-10-01', 'until' => '2026-10-21'])), 's2 was found on the 25th');

        $dismissed = $this->lookup($caller, 'list_signals', ['status' => 'Dismissed'])->rows[0];
        $this->assertSame('not a buyer: A government school.', $dismissed->data['dismissal_reason']);
        $this->assertSame('ScholarClone', $dismissed->data['product']);
        $this->assertSame('Alpha School', $dismissed->data['company']);
        $this->assertSame('2026-10-20', $dismissed->data['last_decided_on']);
        $this->assertSame(['type' => 'signal', 'id' => (string) $s1, 'label' => 'Alpha hires a principal'], array_intersect_key($dismissed->ref(), array_flip(['type', 'id', 'label'])));
        $this->assertStringStartsWith('/module/crm/signal-review?status=all&q=', (string) $dismissed->url);
    }

    public function test_a_dismissal_with_no_reason_says_none_recorded_and_the_research_note_is_never_shown_as_one(): void
    {
        [$a, , $s1] = $this->signalsWorld();
        DB::table('g2g_signal_reviews')->where('opportunity_id', $s1)->delete();
        DB::table('g2g_company_opportunities')->where('id', $s1)->update(['evidence_note' => 'Single uncorroborated secondary source.']);
        // A decision of ANOTHER organisation that carries this signal's number (a stray pointer) is not this signal's reason.
        DB::table('g2g_signal_reviews')->insert(['sub_institute_id' => self::T2, 'opportunity_id' => $s1, 'decision' => 'dismissed', 'reason_code' => 'other', 'note' => 'Secret reason of another organisation',
            'from_status' => 'New', 'to_status' => 'Dismissed', 'decided_by' => 1, 'decided_at' => '2026-10-21 10:00:00', 'created_at' => now()]);

        $row = $this->lookup($this->everything(), 'list_signals', ['status' => 'Dismissed'])->rows[0];

        $this->assertSame('none recorded', $row->data['dismissal_reason']);
        $this->assertStringNotContainsString('Secret reason', $row->line());
        $this->assertStringNotContainsString('uncorroborated', $row->line(), 'what the research found about the source is not why a person dismissed it');
    }

    public function test_list_signals_reads_only_the_callers_organisation_and_never_a_demonstration_row(): void
    {
        [$a, $b] = $this->signalsWorld();
        DB::table('g2g_company_opportunities')->where('id', $a['signals'][1])->update(['is_sample' => 1]);
        $caller = $this->everything();

        $result = $this->lookup($caller, 'list_signals');

        $this->assertCount(2, $result->rows);
        $this->assertSame(2, $result->total);
        $this->assertNotContains((string) $a['signals'][1], array_map(fn ($r) => $r->id, $result->rows), 'a demonstration signal is not a signal');
        foreach ($b['signals'] as $theirs) {
            $this->assertNotContains((string) $theirs, array_map(fn ($r) => $r->id, $result->rows), "organisation 2's signal {$theirs}");
        }
        // A signal of the caller's organisation whose company belongs to another one shows no company name.
        $stray = $this->signal(self::T, $b['company'], ['title' => 'Stray']);
        $row = collect($this->lookup($caller, 'list_signals')->rows)->firstWhere('id', (string) $stray);
        $this->assertNotNull($row);
        $this->assertNull($row->data['company'], "another organisation's company name is not looked up");
        $this->assertStringNotContainsString('Beta School', $row->line());
    }

    public function test_list_signals_is_capped_at_ten_and_says_how_many_matched(): void
    {
        $this->makeOrg(self::T, 'Many Signals', ['signals' => 13]);
        $result = $this->lookup($this->everything(), 'list_signals');

        $this->assertCount(10, $result->rows);
        $this->assertSame(13, $result->total);
    }

    public function test_list_signals_checks_the_signals_right_by_role_while_the_menu_rights_are_off_and_by_the_menu_right_when_they_are_on(): void
    {
        $this->signalsWorld();
        $employee = $this->callerOf($this->asker(self::T, null, 'employee'));
        $denied = $this->lookup($employee, 'list_signals');
        $this->assertSame(ToolResult::NOT_PERMITTED, $denied->status);
        $this->assertSame([], $denied->rows);
        $this->assertSame(['signals'], $denied->withheld);

        foreach (['administrator', 'hr_manager', 'hr_executive', 'reporting_manager'] as $role) {
            $this->assertSame(ToolResult::OK, $this->lookup($this->callerOf($this->asker(self::T, null, $role)), 'list_signals')->status, "{$role} (the profile: admin,hr,manager tier of ModuleGate::signals)");
        }
        foreach (['employee', 'executive', 'auditor', 'recruiter', 'department_head'] as $role) {
            $this->assertSame(ToolResult::NOT_PERMITTED, $this->lookup($this->callerOf($this->asker(self::T, null, $role)), 'list_signals')->status, "{$role}");
        }

        config(['signals.permissions.use_menu_rights' => true]);
        $withRow = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::SIGNALS_RIGHT_LINK => ['view']], 'employee'));
        $this->assertSame(ToolResult::OK, $this->lookup($withRow, 'list_signals')->status, 'the menu right on /signals opens them once the flag is on, whatever the role');
        $adminNoRow = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view']], 'administrator'));
        $this->assertSame(ToolResult::NOT_PERMITTED, $this->lookup($adminNoRow, 'list_signals')->status, 'and the role alone no longer does');
    }

    public function test_the_signals_gate_here_is_the_one_moduleGate_gives_the_signals_routes(): void
    {
        config(['signals.permissions.use_menu_rights' => false]);
        $this->assertSame('profile:admin,hr,manager', \App\Support\ModuleGate::signals('/signals', 'view', 'read'));
        $this->assertSame(['admin', 'hr', 'manager'], \App\Domain\Ask\AskRights::SIGNALS_READ_PROFILES);
        config(['signals.permissions.use_menu_rights' => true]);
        $this->assertSame('platformright:/signals,view', \App\Support\ModuleGate::signals('/signals', 'view', 'read'));
    }

    // ---- list_products ----

    public function test_list_products_shows_the_organisations_own_and_the_shared_ones_never_another_organisations(): void
    {
        $now = now();
        $master = fn (int $tenant, string $key, string $kind, string $name, ?int $parent = null, string $status = 'active') => DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => $kind, 'parent_id' => $parent, 'name' => $name, 'status' => $status, 'origin' => 'manual', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $shared = $master(0, 'scholar', 'product', 'Scholar K-12');
        $mine = $master(self::T, 'mine_sub', 'sub_product', 'Scholar Analytics', $shared);
        $theirs = $master(self::T2, 'theirs', 'product', 'Their Secret Product');
        $stray = $master(self::T, 'stray_sub', 'sub_product', 'Stray Child', $theirs);   // points at another organisation's product line
        $archived = $master(self::T, 'old', 'product', 'Archived Product', null, 'archived');

        $result = $this->lookup($this->everything(), 'list_products');
        $ids = array_map(fn ($r) => (int) $r->id, $result->rows);

        $this->assertSame([$shared, $mine, $stray], $ids, 'product lines first, then sub-products');
        $this->assertNotContains($theirs, $ids);
        $this->assertNotContains($archived, $ids);
        $this->assertNull($result->rows[2]->data['product_line'], "another organisation's product line is not named");
        $this->assertSame('Scholar K-12', $result->rows[1]->data['product_line']);
        $this->assertSame('product line', $result->rows[0]->data['kind']);
        $this->assertSame("/module/crm/sales/products/lines/{$shared}", $result->rows[0]->url);
        $this->assertStringNotContainsString('Secret', json_encode(array_map(fn ($r) => $r->line(), $result->rows)));
    }

    public function test_a_sub_product_sold_in_the_crm_catalogue_links_to_its_catalogue_page_but_only_for_the_callers_own_catalogue(): void
    {
        \Illuminate\Support\Facades\Schema::create('crm_products', function ($t) {
            $t->id();
            $t->string('item_type', 20);
            $t->unsignedBigInteger('sub_institute_id');
            $t->timestamp('deleted_at')->nullable();
        });
        $service = DB::table('crm_products')->insertGetId(['item_type' => 'service', 'sub_institute_id' => self::T]);
        $product = DB::table('crm_products')->insertGetId(['item_type' => 'product', 'sub_institute_id' => self::T]);
        $theirs = DB::table('crm_products')->insertGetId(['item_type' => 'product', 'sub_institute_id' => self::T2]);
        $master = fn (string $key, string $name, ?int $crm) => DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T, 'product_key' => $key, 'kind' => 'item', 'name' => $name, 'crm_product_id' => $crm, 'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $master('a', 'A service', $service);
        $b = $master('b', 'B product', $product);
        $c = $master('c', 'C stray', $theirs);

        $rows = collect($this->lookup($this->everything(), 'list_products')->rows)->keyBy('id');

        $this->assertSame(["/module/crm/sales/services/{$service}", true], [$rows[(string) $a]->url, $rows[(string) $a]->data['sold_in_the_crm_catalogue']]);
        $this->assertSame(["/module/crm/sales/products/{$product}", true], [$rows[(string) $b]->url, $rows[(string) $b]->data['sold_in_the_crm_catalogue']]);
        $this->assertSame(["/module/crm/sales/products/lines/{$c}", false], [$rows[(string) $c]->url, $rows[(string) $c]->data['sold_in_the_crm_catalogue']], "another organisation's catalogue row is not this one's");
    }

    public function test_list_products_checks_the_products_right_and_says_nothing_found_for_an_empty_catalogue(): void
    {
        $this->assertSame(ToolResult::NOT_PERMITTED, $this->lookup($this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view']])), 'list_products')->status);
        $empty = $this->lookup($this->everything(), 'list_products');
        $this->assertSame(ToolResult::OK, $empty->status);
        $this->assertSame([], $empty->rows);
        $this->assertSame('in products', $empty->searched);
    }

    public function test_the_products_right_alone_opens_the_product_list(): void
    {
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'only', 'kind' => 'item', 'name' => 'Only Item', 'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $productsOnly = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::PRODUCTS_LINK => ['view']], 'employee'));

        $this->assertSame(1, count($this->lookup($productsOnly, 'list_products')->rows), 'the Products row, and no other, opens products');
        $leadsOnly = $this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::LEADS_LINK => ['view'], self::CONTACTS_LINK => ['view'], self::ORG_LINK => ['view'], self::OPPS_LINK => ['view']], 'hr_manager'));
        $this->assertSame(ToolResult::NOT_PERMITTED, $this->lookup($leadsOnly, 'list_products')->status, 'and every other row does not');
    }

    public function test_the_lists_under_an_opened_organisation_are_capped(): void
    {
        $caller = $this->everything();
        $org = $this->makeOrg(self::T, 'Busy Org', ['signals' => 8]);
        for ($i = 1; $i <= 8; $i++) {
            $this->opportunity(self::T, "Open deal {$i}", $org['crm']);
            $this->contact(self::T, "Contact{$i}", 'Busy', $org['crm']);
        }

        $keys = $this->keys($this->lookup($caller, 'get_record', ['type' => 'organization', 'id' => $org['crm']]));

        foreach (['opportunity', 'contact', 'signal'] as $kind) {
            $this->assertCount(5, array_filter($keys, fn ($k) => str_starts_with($k, "{$kind}:")), "at most five {$kind} rows under a record");
        }
    }

    public function test_list_products_is_capped(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => "p{$i}", 'kind' => 'item', 'name' => sprintf('Item %02d', $i), 'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        }
        $result = $this->lookup($this->everything(), 'list_products');

        $this->assertCount(40, $result->rows);
        $this->assertSame(45, $result->total);
    }

    // ---- pipeline_summary ----

    public function test_pipeline_summary_counts_and_sums_by_stage_and_leads_by_status_for_the_callers_organisation_only(): void
    {
        $org = $this->makeOrg(self::T, 'Pipe Org', ['signals' => 1]);
        $this->opportunity(self::T, 'A', $org['crm'], ['sales_stage' => 'Proposal', 'amount' => 100000, 'currency' => 'RUP']);
        $this->opportunity(self::T, 'B', $org['crm'], ['sales_stage' => 'Proposal', 'amount' => 50000.5, 'currency' => 'RUP']);
        $this->opportunity(self::T, 'C', $org['crm'], ['sales_stage' => 'Closed Won', 'amount' => 900000, 'currency' => 'RUP']);
        $this->opportunity(self::T, 'D', $org['crm'], ['sales_stage' => 'Negotiation', 'amount' => 70000, 'currency' => 'USD']);
        $this->opportunity(self::T2, 'Other tenant', null, ['sales_stage' => 'Proposal', 'amount' => 99999999]);
        $this->lead(self::T, 'L1', 'A', ['lead_status' => 'New', 'source_company_opportunity_id' => $org['signals'][0]]);
        $this->lead(self::T, 'L2', 'B', ['lead_status' => 'New']);
        $this->lead(self::T, 'L3', 'C', ['lead_status' => 'Qualified']);
        $this->lead(self::T, 'L4', 'D', ['lead_status' => 'Qualified', 'converted' => 1, 'source_company_opportunity_id' => $org['signals'][0]]);
        $this->lead(self::T2, 'Other', 'Tenant', ['source_company_opportunity_id' => 5]);
        $this->lead(self::T2, 'Other', 'Converted', ['converted' => 1, 'source_company_opportunity_id' => 6]);

        $result = $this->lookup($this->everything(), 'pipeline_summary');
        $rows = collect($result->rows)->keyBy('id');

        $this->assertSame(['pipeline', 'leads'], array_map(fn ($r) => $r->id, $result->rows));
        $this->assertSame(['summary:pipeline', 'summary:leads'], $this->keys($result));
        $pipeline = $rows['pipeline']->data;
        $this->assertSame(4, $pipeline['deals_in_all']);
        $this->assertSame(3, $pipeline['open_deals'], 'Proposal x2 and Negotiation: Closed Won is not open');
        $this->assertSame(['INR' => 150000.5, 'USD' => 70000.0], $pipeline['open_amount_by_currency'], 'worked out here, per currency, RUP read as INR');
        $this->assertContains(['stage' => 'Proposal', 'currency' => 'INR', 'deals' => 2, 'deals_with_an_amount' => 2, 'amount' => 150000.5], $pipeline['by_stage']);
        $leads = $rows['leads']->data;
        $this->assertSame(3, $leads['open_leads']);
        $this->assertSame(1, $leads['converted_leads']);
        $this->assertSame(['New' => 2, 'Qualified' => 1], $leads['open_leads_by_status']);
        $this->assertSame(2, $leads['leads_that_came_from_a_signal'], 'converted ones included: the data says so');
        $this->assertSame('/module/crm/sales/opportunities', $rows['pipeline']->url);
        $this->assertSame('/module/crm/marketing/leads', $rows['leads']->url);
    }

    public function test_a_stage_whose_deals_have_no_amount_has_no_amount_and_not_zero(): void
    {
        $this->opportunity(self::T, 'No amount', null, ['sales_stage' => 'Prospecting', 'amount' => null, 'currency' => null]);
        $this->opportunity(self::T, 'Some amount', null, ['sales_stage' => 'Qualification', 'amount' => 0, 'currency' => 'RUP']);
        $this->opportunity(self::T, 'Mixed A', null, ['sales_stage' => 'Proposal', 'amount' => 1000, 'currency' => 'RUP']);
        $this->opportunity(self::T, 'Mixed B', null, ['sales_stage' => 'Proposal', 'amount' => null, 'currency' => 'RUP']);

        $stages = collect($this->lookup($this->everything(), 'pipeline_summary')->rows[0]->data['by_stage'])->keyBy('stage');

        $this->assertNull($stages['Prospecting']['amount'], 'nothing recorded is not zero');
        $this->assertSame(0, $stages['Prospecting']['deals_with_an_amount']);
        $this->assertSame([0.0, 1], [(float) $stages['Qualification']['amount'], $stages['Qualification']['deals_with_an_amount']], 'an amount of 0 that WAS recorded is 0');
        $this->assertSame([1000.0, 1, 2], [(float) $stages['Proposal']['amount'], $stages['Proposal']['deals_with_an_amount'], $stages['Proposal']['deals']], 'the sum is of the deals that have an amount, and the line says how many do');
    }

    public function test_pipeline_summary_gives_each_part_only_to_a_profile_that_may_open_it(): void
    {
        $this->opportunity(self::T, 'A');
        $this->lead(self::T, 'L', 'A');

        $oppsOnly = $this->lookup($this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::OPPS_LINK => ['view']])), 'pipeline_summary');
        $this->assertSame(['summary:pipeline'], $this->keys($oppsOnly));
        $this->assertSame(['leads'], $oppsOnly->withheld);

        $leadsOnly = $this->lookup($this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view'], self::LEADS_LINK => ['view']])), 'pipeline_summary');
        $this->assertSame(['summary:leads'], $this->keys($leadsOnly));
        $this->assertSame(['opportunities'], $leadsOnly->withheld);

        $none = $this->lookup($this->callerOf($this->asker(self::T, [self::ASK_LINK => ['view']])), 'pipeline_summary');
        $this->assertSame(ToolResult::NOT_PERMITTED, $none->status);
        $this->assertSame([], $none->rows);
    }

    public function test_pipeline_summary_of_an_organisation_with_no_deals_and_no_leads_still_has_its_rows(): void
    {
        $result = $this->lookup($this->everything(), 'pipeline_summary');

        $this->assertSame(0, $result->rows[0]->data['deals_in_all']);
        $this->assertSame(0, $result->rows[1]->data['open_leads']);
    }

    // ---- pipeline_summary: where the leads came from (E2E finding B1, 4 Nov 2026) ----

    private function leadsSummary(AskCaller $caller): array
    {
        return collect($this->lookup($caller, 'pipeline_summary')->rows)->keyBy('id')['leads']->data;
    }

    public function test_the_leads_summary_says_where_the_leads_came_from_every_lead_counted_blanks_labelled_and_the_callers_organisation_only(): void
    {
        // The E2E run's own shape: Web Site 4, Self Generated 1, Signals 1, blank 2. One Web Site lead is converted (it still came from there).
        foreach (['L1', 'L2', 'L3'] as $n) {
            $this->lead(self::T, $n, 'Web', ['lead_source' => 'Web Site']);
        }
        $this->lead(self::T, 'L4', 'Web', ['lead_source' => 'Web Site', 'converted' => 1]);
        $this->lead(self::T, 'L5', 'Mine', ['lead_source' => 'Self Generated']);
        $this->lead(self::T, 'L6', 'Sig', ['lead_source' => 'Signals']);
        $this->lead(self::T, 'L7', 'Null', ['lead_source' => null]);
        $this->lead(self::T, 'L8', 'Spaces', ['lead_source' => '   ']);
        // Never counted: another organisation's leads, and a deleted lead.
        $this->lead(self::T2, 'O1', 'Other', ['lead_source' => 'Other Tenant Source']);
        $this->lead(self::T2, 'O2', 'Other', ['lead_source' => 'Web Site']);
        $this->lead(self::T, 'Gone', 'Deleted', ['lead_source' => 'Deleted Source', 'deleted_at' => now()]);
        // ... and the same two for the "not recorded" line, which is counted by its own query.
        $this->lead(self::T2, 'O3', 'Other', ['lead_source' => null]);
        $this->lead(self::T, 'Gone2', 'Deleted', ['lead_source' => null, 'deleted_at' => now()]);

        $leads = $this->leadsSummary($this->everything());

        $this->assertSame(['Web Site' => 4, 'not recorded' => 2, 'Self Generated' => 1, 'Signals' => 1], $leads['leads_by_source'], 'biggest first, equal counts alphabetical, a blank or spaces-only source is "not recorded"');
        $this->assertSame(8, $leads['all_leads']);
        $this->assertSame(8, array_sum($leads['leads_by_source']), 'every lead is in exactly one line');
        $this->assertSame(['other_sources' => 0, 'leads_in_those' => 0], $leads['leads_by_source_left_out'], 'nothing was cut, and the row says so');
        $this->assertSame(7, $leads['open_leads'], 'the open count still leaves the converted lead out');
        $this->assertSame(1, $leads['converted_leads']);
        $this->assertStringContainsString('leads_by_source counts every lead (open and converted)', $leads['about_these_counts'], 'the answer is told that converted leads are in the source counts');
        $this->assertStringContainsString('at most 10 sources', $leads['about_these_counts']);
        $this->assertStringContainsString('leads_by_source_left_out says how many more', $leads['about_these_counts'], 'and where to read what the cap left out');
    }

    public function test_the_lead_sources_are_capped_at_ten_biggest_first_and_the_row_counts_what_it_left_out(): void
    {
        // 13 sources: ten with two leads (S01..S10), one with five that sorts LAST by name, two single leads that sort FIRST by name.
        foreach (range(1, 10) as $i) {
            $source = sprintf('S%02d', $i);
            $this->lead(self::T, $source, 'a', ['lead_source' => $source]);
            $this->lead(self::T, $source, 'b', ['lead_source' => $source]);
        }
        foreach (range(1, 5) as $i) {
            $this->lead(self::T, 'Big' . $i, 'x', ['lead_source' => 'Zz Big']);
        }
        $this->lead(self::T, 'Tiny', 'a', ['lead_source' => 'A Tiny']);
        $this->lead(self::T, 'Tiny', 'b', ['lead_source' => 'B Tiny']);
        $this->lead(self::T, 'Blank', 'a', ['lead_source' => null]);

        $leads = $this->leadsSummary($this->everything());
        $bySource = $leads['leads_by_source'];

        $this->assertSame(11, count($bySource), 'ten named sources, and the leads with no source come on top as their own line');
        $this->assertSame('Zz Big', array_key_first($bySource), 'the biggest source is first even though it sorts last by name');
        $this->assertSame(['Zz Big' => 5, 'S01' => 2, 'S02' => 2, 'S03' => 2, 'S04' => 2, 'S05' => 2, 'S06' => 2, 'S07' => 2, 'S08' => 2, 'S09' => 2, 'not recorded' => 1], $bySource,
            'S10 and the two single-lead sources fell below the cap; the one blank lead is labelled');
        $this->assertSame(['other_sources' => 3, 'leads_in_those' => 4], $leads['leads_by_source_left_out'], 'S10 (2) + A Tiny (1) + B Tiny (1), so the answer can say what it left out');
        $this->assertSame(28, $leads['all_leads']);
        $this->assertSame(28, array_sum($bySource) + $leads['leads_by_source_left_out']['leads_in_those'], 'listed plus left out is every lead');
    }

    public function test_the_leads_summary_of_an_organisation_with_no_leads_has_an_empty_source_list_and_nothing_left_out(): void
    {
        $leads = $this->leadsSummary($this->everything());

        $this->assertSame([], $leads['leads_by_source']);
        $this->assertSame(0, $leads['all_leads']);
        $this->assertSame(['other_sources' => 0, 'leads_in_those' => 0], $leads['leads_by_source_left_out']);
    }

    public function test_a_host_whose_leads_have_no_source_column_says_so_instead_of_inventing_a_breakdown(): void
    {
        $this->lead(self::T, 'Has', 'Source', ['lead_source' => 'Web Site']);
        Schema::table('crm_leads', fn ($t) => $t->dropColumn('lead_source'));
        AskRecords::forgetSchema();

        $leads = $this->leadsSummary($this->everything());

        $this->assertArrayNotHasKey('leads_by_source', $leads);
        $this->assertArrayNotHasKey('leads_by_source_left_out', $leads);
        $this->assertStringContainsString('does not keep a source on its leads', $leads['about_these_counts']);
        $this->assertSame(1, $leads['open_leads'], 'the rest of the summary still works');
    }
}
