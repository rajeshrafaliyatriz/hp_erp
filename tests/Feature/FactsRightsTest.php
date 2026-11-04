<?php

namespace Tests\Feature;

use App\Domain\Facts\FactWriter;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The rights matrix of the facts and consent API (slice 4). There is no menu row of its own: the right of a record's facts is the right of the screen the
 * record belongs to (contacts, organisations, leads; the support rows count for contacts and organisations), view reads and edit decides, and there is no
 * administrator bypass. Every case below is a person with exactly the rights named, calling the real routes.
 */
class FactsRightsTest extends TestCase
{
    use BuildsFactsWorld;

    /** @var array<string, int> kind => a record */
    private array $record = [];

    /** @var array<string, int> kind => a waiting fact of that record */
    private array $fact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
        $this->record = ['contact' => $this->makeContact(), 'organization' => $this->makeOrganization(), 'lead' => $this->makeLead()];
        $writer = app(FactWriter::class);
        foreach ([['contact', 'phone', '+91 98765 43210'], ['organization', 'website', 'school.example'], ['lead', 'title', 'Principal']] as [$type, $field, $value]) {
            $this->fact[$type] = (int) $writer->propose(self::T, $type, $this->record[$type], $field, $value, $this->evidence('site.own-page'), 'm', 'agent:x')['fact']->id;
        }
    }

    /**
     * Who holds which rights, and which kinds of record each may therefore view and edit.
     *
     * @return array<string, array{0: array<string, list<string>>, 1: list<string>, 2: list<string>}>
     */
    private function people(): array
    {
        return [
            'nothing at all' => [[], [], []],
            'contacts: view' => [[self::CONTACTS_LINK => ['view']], ['contact'], []],
            'contacts: view and edit' => [[self::CONTACTS_LINK => ['view', 'edit']], ['contact'], ['contact']],
            'contacts (support row): view and edit' => [[self::CONTACTS_SUPPORT => ['view', 'edit']], ['contact'], ['contact']],
            'organizations: view and edit' => [[self::ORGS_LINK => ['view', 'edit']], ['organization'], ['organization']],
            'organizations (support row): view' => [[self::ORGS_SUPPORT => ['view']], ['organization'], []],
            'leads: view and edit' => [[self::LEADS_LINK => ['view', 'edit']], ['lead'], ['lead']],
            'contacts view, organizations edit only' => [[self::CONTACTS_LINK => ['view'], self::ORGS_LINK => ['edit']], ['contact'], ['organization']],
            'add and delete but not view or edit' => [[self::CONTACTS_LINK => ['add', 'delete'], self::ORGS_LINK => ['add', 'delete'], self::LEADS_LINK => ['add', 'delete']], [], []],
        ];
    }

    public function test_reading_a_records_facts_and_consent_needs_view_on_the_screen_of_that_kind_of_record_and_nothing_else_helps(): void
    {
        foreach ($this->people() as $name => [$rights, $view]) {
            $user = $this->user($rights);
            foreach (['contact', 'organization', 'lead'] as $type) {
                foreach (["/api/facts/entity/{$type}/{$this->record[$type]}", "/api/consent/{$type}/{$this->record[$type]}"] as $url) {
                    $status = $this->getJson($url, $user['h'])->getStatusCode();
                    $this->assertSame(in_array($type, $view, true) ? 200 : 403, $status, "{$name}: GET {$url}");
                }
            }
        }
    }

    public function test_deciding_a_fact_needs_edit_on_the_screen_of_the_kind_of_record_it_is_about(): void
    {
        foreach ($this->people() as $name => [$rights, , $edit]) {
            foreach (['contact', 'organization', 'lead'] as $type) {
                foreach (['dismiss', 'accept'] as $verb) {
                    $user = $this->user($rights);
                    $id = $this->fact[$type];
                    $status = $this->postJson("/api/facts/{$id}/{$verb}", [], $user['h'])->getStatusCode();

                    if (in_array($type, $edit, true)) {
                        $this->assertSame(200, $status, "{$name}: {$verb} a {$type} fact");
                        $this->assertNotSame('proposed', DB::table('g2g_entity_facts')->where('id', $id)->value('status'));
                        // put it back, so the next person starts from the same waiting fact
                        DB::table('g2g_entity_facts')->where('id', $id)->update(['status' => 'proposed', 'decided_by' => null, 'decided_at' => null, 'column_written' => 0]);
                        DB::table('crm_contacts')->update(['phone' => null]);
                        DB::table('crm_organizations')->update(['website' => null]);
                        DB::table('crm_leads')->update(['designation' => null]);
                    } else {
                        $this->assertSame(403, $status, "{$name}: {$verb} a {$type} fact");
                        $this->assertSame('proposed', DB::table('g2g_entity_facts')->where('id', $id)->value('status'), "{$name}: a refused {$verb} changed nothing");
                    }
                }
            }
        }
    }

    public function test_a_person_who_may_edit_one_kind_is_refused_a_fact_of_another_with_a_clear_code_and_nothing_changes(): void
    {
        $user = $this->user([self::CONTACTS_LINK => ['view'], self::ORGS_LINK => ['edit']]);
        $before = (array) DB::table('g2g_entity_facts')->where('id', $this->fact['contact'])->first();

        $res = $this->postJson("/api/facts/{$this->fact['contact']}/accept", [], $user['h'])->assertStatus(403);

        $res->assertJsonPath('status', 0)->assertJsonPath('code', 'no_right');
        $this->assertSame($before, (array) DB::table('g2g_entity_facts')->where('id', $this->fact['contact'])->first());
        $this->assertNull(DB::table('crm_contacts')->where('id', $this->record['contact'])->value('phone'));
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'fact.decided')->count());
    }

    public function test_the_queue_shows_only_the_kinds_the_caller_may_view_and_refuses_a_kind_they_may_not(): void
    {
        $contactsOnly = $this->user([self::CONTACTS_LINK => ['view']]);
        $contactsAndLeads = $this->user([self::CONTACTS_SUPPORT => ['view'], self::LEADS_LINK => ['view']]);
        $nothing = $this->user([]);

        $this->assertSame([$this->fact['contact']], array_column($this->getJson('/api/facts/queue', $contactsOnly['h'])->assertOk()->json('data.rows'), 'id'));
        $this->assertEqualsCanonicalizing([$this->fact['contact'], $this->fact['lead']], array_column($this->getJson('/api/facts/queue', $contactsAndLeads['h'])->assertOk()->json('data.rows'), 'id'));
        $this->assertSame(1, $this->getJson('/api/facts/queue', $contactsOnly['h'])->json('data.pagination.total'), 'the total counts only what the caller may see');
        $this->getJson('/api/facts/queue?type=organization', $contactsOnly['h'])->assertStatus(403)->assertJsonPath('code', 'no_right');
        $this->getJson('/api/facts/queue?type=contact', $contactsOnly['h'])->assertOk();
        $this->getJson('/api/facts/queue', $nothing['h'])->assertStatus(403);
    }

    public function test_the_screen_is_told_whether_the_caller_may_decide_so_it_never_offers_a_button_that_cannot_work(): void
    {
        $viewOnly = $this->user([self::CONTACTS_LINK => ['view'], self::ORGS_LINK => ['view']]);
        $mixed = $this->user([self::CONTACTS_LINK => ['view', 'edit'], self::ORGS_LINK => ['view'], self::LEADS_LINK => ['view']]);
        $editNotViewable = $this->user([self::CONTACTS_LINK => ['view'], self::LEADS_LINK => ['edit']]);

        $this->assertFalse($this->getJson("/api/facts/entity/contact/{$this->record['contact']}", $viewOnly['h'])->json('data.can.decide'));
        $this->assertTrue($this->getJson("/api/facts/entity/contact/{$this->record['contact']}", $mixed['h'])->json('data.can.decide'));
        $this->assertFalse($this->getJson("/api/facts/entity/organization/{$this->record['organization']}", $mixed['h'])->json('data.can.decide'), 'it is per kind of record');

        $this->assertSame(['decide' => false, 'decide_types' => []], $this->getJson('/api/facts/queue', $viewOnly['h'])->json('data.can'));
        $this->assertSame(['decide' => true, 'decide_types' => ['contact']], $this->getJson('/api/facts/queue', $mixed['h'])->json('data.can'));
        $this->assertSame(['decide' => false, 'decide_types' => []], $this->getJson('/api/facts/queue', $editNotViewable['h'])->json('data.can'), 'a kind the caller may edit but not view is not in their queue, so it is not decidable there');
    }

    public function test_an_edit_right_without_view_cannot_read_the_queue_or_a_record(): void
    {
        $editOnly = $this->user([self::CONTACTS_LINK => ['edit']]);

        $this->getJson('/api/facts/queue', $editOnly['h'])->assertStatus(403);
        $this->getJson("/api/facts/entity/contact/{$this->record['contact']}", $editOnly['h'])->assertStatus(403);
        $this->postJson("/api/facts/{$this->fact['contact']}/dismiss", [], $editOnly['h'])->assertOk();
    }

    public function test_an_individual_denial_beats_the_group_grant(): void
    {
        $user = $this->user([self::CONTACTS_LINK => ['view', 'edit']]);
        $profile = DB::table('tbluser')->where('id', $user['id'])->value('user_profile_id');
        DB::table('tblgroupwise_rights_g2g')->where('profile_id', $profile)->update(['right_edit' => 'deny']);

        $this->getJson("/api/facts/entity/contact/{$this->record['contact']}", $user['h'])->assertOk();
        $this->postJson("/api/facts/{$this->fact['contact']}/accept", [], $user['h'])->assertStatus(403);
    }

    public function test_there_is_no_administrator_bypass_a_profile_needs_a_rights_row(): void
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Administrator', 'role_key' => 'admin', 'sub_institute_id' => self::T]);
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'boss', 'first_name' => 'Boss', 'last_name' => 'Admin', 'sub_institute_id' => self::T, 'user_profile_id' => $profile, 'status' => 1]);
        $token = \App\Models\auth\tbluserModel::find($id)->createToken('t')->plainTextToken;

        $this->getJson("/api/facts/entity/contact/{$this->record['contact']}", ['Authorization' => "Bearer {$token}"])->assertStatus(403);
        $this->getJson('/api/facts/queue', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }
}
