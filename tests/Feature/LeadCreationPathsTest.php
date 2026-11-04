<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadNumber;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * Every way a lead is born goes through ONE factory (slice 6, acceptance 2): the manual form, the CSV import, the public web form, a signal turned into a
 * lead on the Signals screen and a signal promoted from the old Signal Inbox. Each must leave a number that cannot collide, a score and a crm.lead.created
 * event. The Signal Inbox used to insert the lead itself (a lead with no number, no score, no event) and its opportunity insert named columns the table
 * does not have; both are fixed here, and a scan of the source fails the build if a direct insert comes back.
 */
class LeadCreationPathsTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        // One scoring rule that every path's lead meets (each path files its lead as "New"): the score is 10 or the path did not score.
        DB::table('crm_lead_scoring_rules')->insert(['sub_institute_id' => self::T, 'field_key' => 'lead_status', 'operator' => 'equals', 'value' => 'New', 'points' => 10, 'is_active' => 1]);
    }

    /** @return object the one lead a path made, with the checks every path must pass already made */
    private function assertBornThroughTheFactory(int $leadId, int $tenant = self::T): object
    {
        $lead = DB::table('crm_leads')->where('id', $leadId)->first();
        $this->assertNotNull($lead, 'the lead exists');
        $this->assertMatchesRegularExpression('/^LD-\d{5,}$/', (string) $lead->lead_no, 'a number in the one format');
        $this->assertSame(1, DB::table('crm_leads')->where('lead_no', $lead->lead_no)->count(), 'a number nobody else holds');
        $this->assertSame(10, (int) $lead->lead_score, 'a score from the tenant\'s rules');
        $this->assertSame($tenant, (int) $lead->sub_institute_id);

        $events = DB::table('g2g_event')->where('type', 'crm.lead.created')->where('entity_id', $leadId)->get();
        $this->assertCount(1, $events, 'exactly one crm.lead.created event');
        $this->assertSame($tenant, (int) $events[0]->sub_institute_id);
        $this->assertSame('crm_leads', $events[0]->entity_type);

        return $lead;
    }

    public function test_the_manual_form_creates_a_lead_through_the_factory(): void
    {
        $user = $this->leadsUser();

        $res = $this->postJson('/api/crm/leads', ['lastName' => 'Verma', 'company' => 'Greenfield', 'leadStatus' => 'New', 'assignedTo' => $user['id']], $user['h'])->assertCreated();

        $lead = $this->assertBornThroughTheFactory((int) $res->json('data.id'));
        $this->assertSame($lead->lead_no, $res->json('data.leadNo'));
    }

    public function test_the_csv_import_creates_every_row_through_the_factory(): void
    {
        $user = $this->leadsUser();

        $res = $this->postJson('/api/crm/leads/import', ['rows' => [
            ['lastName' => 'One', 'leadStatus' => 'New', 'assignedTo' => $user['id']],
            ['lastName' => 'Two', 'leadStatus' => 'New', 'assignedTo' => $user['id']],
            ['lastName' => 'Three', 'leadStatus' => 'New', 'assignedTo' => $user['id']],
        ]], $user['h'])->assertOk();

        $this->assertSame(3, $res->json('data.created'));
        $ids = DB::table('crm_leads')->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $ids);
        $numbers = [];
        foreach ($ids as $id) {
            $numbers[] = $this->assertBornThroughTheFactory((int) $id)->lead_no;
        }
        $this->assertSame(['LD-00001', 'LD-00002', 'LD-00003'], $numbers);
    }

    public function test_the_public_web_form_creates_a_lead_through_the_factory(): void
    {
        $owner = $this->leadsUser();
        DB::table('crm_lead_capture_settings')->insert(['sub_institute_id' => self::T, 'public_key' => 'pk_test_key', 'is_enabled' => 1, 'default_lead_source' => 'Website', 'default_assigned_to' => $owner['id'], 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/public/lead-capture/pk_test_key', ['lastName' => 'Visitor', 'email' => 'visitor@school.example', 'message' => 'Please call me'])->assertCreated();

        $lead = $this->assertBornThroughTheFactory((int) DB::table('crm_leads')->where('last_name', 'Visitor')->value('id'));
        $this->assertSame('Website', $lead->lead_source);
        $this->assertSame('Please call me', $lead->description);
    }

    public function test_a_signal_turned_into_a_lead_on_the_signals_screen_goes_through_the_factory(): void
    {
        $admin = $this->signalsAdmin();
        $signal = $this->makeSignal();

        $res = $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", [], $admin['h'])->assertCreated();

        $lead = $this->assertBornThroughTheFactory((int) $res->json('data.lead_id'));
        $this->assertSame($signal->id, (int) $lead->source_company_opportunity_id);
    }

    public function test_a_signal_promoted_from_the_old_signal_inbox_goes_through_the_factory_and_the_direct_insert_is_gone(): void
    {
        $user = $this->leadsUser();
        $organisation = $this->makeOrganization(self::T, ['name' => 'Greenfield School']);
        $signal = $this->makeInboxSignal(self::T, ['linked_account_id' => $organisation, 'title' => 'Greenfield opens a new campus', 'body' => 'They plan to hire.']);

        $res = $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertOk();

        $this->assertSame('lead', $res->json('data.promoted_to'));
        $lead = $this->assertBornThroughTheFactory((int) $res->json('data.promoted_id'));
        $this->assertSame('Greenfield School', $lead->company);
        $this->assertSame('signal_inbox', $lead->lead_source);
        $this->assertSame("Greenfield opens a new campus\n\nThey plan to hire.", $lead->description);
        $inbox = DB::table('crm_signal_inbox')->where('id', $signal)->first();
        $this->assertSame(['promoted', 'lead', (int) $lead->id], [$inbox->status, $inbox->promoted_to, (int) $inbox->promoted_id]);
    }

    public function test_no_code_outside_the_factory_inserts_into_crm_leads(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with(str_replace('\\', '/', $file->getPathname()), 'app/Domain/Crm/Leads/LeadFactory.php')) {
                continue;
            }
            if (preg_match('/table\(\s*[\'"]crm_leads[\'"]\s*\)\s*->\s*(insert|insertGetId|insertOrIgnore|upsert)\b/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'a lead made outside LeadFactory has no safe number, no score and no event');
    }

    // ── the Signal Inbox's other fixes ───────────────────────────────────────

    public function test_a_signal_inbox_opportunity_is_made_with_the_columns_the_table_really_has(): void
    {
        $user = $this->leadsUser();
        $organisation = $this->makeOrganization(self::T, ['name' => 'Greenfield School']);
        $signal = $this->makeInboxSignal(self::T, ['linked_account_id' => $organisation, 'title' => 'Greenfield opens a new campus', 'body' => 'They plan to hire.']);

        $res = $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'opportunity'], $user['h'])->assertOk();

        $opp = DB::table('crm_opportunities')->where('id', $res->json('data.promoted_id'))->first();
        $this->assertNotNull($opp);
        $this->assertMatchesRegularExpression('/^OPP-[A-Z0-9]{8}$/', $opp->opportunity_no);
        $this->assertSame('Greenfield opens a new campus', $opp->name);
        $this->assertSame($organisation, (int) $opp->organization_id);
        $this->assertSame(['signal_inbox', 'Prospecting', 'They plan to hire.', $user['id'], self::T], [$opp->lead_source, $opp->sales_stage, $opp->description, (int) $opp->assigned_to, (int) $opp->sub_institute_id]);
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'crm.opportunity.created')->where('entity_id', $opp->id)->count(), 'the workflows are told, as for every opportunity');
        $this->assertSame('promoted', DB::table('crm_signal_inbox')->where('id', $signal)->value('status'));
    }

    public function test_promoting_the_same_inbox_signal_twice_makes_one_lead(): void
    {
        $user = $this->leadsUser();
        $signal = $this->makeInboxSignal(self::T, ['title' => 'A signal']);

        $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertOk();
        $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertStatus(422)->assertJsonPath('message', 'Signal already promoted.');
        $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'opportunity'], $user['h'])->assertStatus(422);

        $this->assertSame(1, DB::table('crm_leads')->count());
        $this->assertSame(0, DB::table('crm_opportunities')->count());
    }

    /**
     * The lead and the signal's new status stand together. A failure while the signal is being marked promoted (made here by throwing from a listener the
     * moment that update has run) must take the lead with it: otherwise the signal is still "pending", the next press makes a second lead, and the first
     * stays behind.
     */
    public function test_a_failure_while_marking_the_inbox_signal_promoted_leaves_no_lead_behind(): void
    {
        $user = $this->leadsUser();
        $signal = $this->makeInboxSignal(self::T, ['title' => 'A signal']);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'update "crm_signal_inbox"')) {
                throw new \RuntimeException('the status update failed');
            }
        });

        $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertStatus(500);

        $this->assertSame(0, DB::table('crm_leads')->count(), 'no lead without the signal being marked');
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'crm.lead.created')->count());
        $this->assertSame('pending', DB::table('crm_signal_inbox')->where('id', $signal)->value('status'));
    }

    public function test_an_inbox_signal_linked_to_another_organisations_account_leaks_nothing_and_links_nothing(): void
    {
        $user = $this->leadsUser(self::T);
        $theirs = $this->makeOrganization(self::T2, ['name' => 'Their Secret School']);
        $signal = $this->makeInboxSignal(self::T, ['linked_account_id' => $theirs, 'title' => 'Signal']);

        $lead = $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertOk();
        $this->assertSame('Unknown', DB::table('crm_leads')->where('id', $lead->json('data.promoted_id'))->value('company'));

        $second = $this->makeInboxSignal(self::T, ['linked_account_id' => $theirs, 'title' => 'Signal two']);
        $opp = $this->postJson("/api/crm/signal-inbox/{$second}/promote", ['promoted_to' => 'opportunity'], $user['h'])->assertOk();
        $this->assertNull(DB::table('crm_opportunities')->where('id', $opp->json('data.promoted_id'))->value('organization_id'));
    }

    public function test_another_organisations_inbox_signal_cannot_be_promoted(): void
    {
        $user = $this->leadsUser(self::T);
        $theirs = $this->makeInboxSignal(self::T2, ['title' => 'Theirs']);

        $this->postJson("/api/crm/signal-inbox/{$theirs}/promote", ['promoted_to' => 'lead'], $user['h'])->assertNotFound();

        $this->assertSame(0, DB::table('crm_leads')->count());
        $this->assertSame('pending', DB::table('crm_signal_inbox')->where('id', $theirs)->value('status'));
    }

    public function test_a_long_description_is_kept_up_to_two_thousand_characters_and_cut_by_us_not_the_database(): void
    {
        $user = $this->leadsUser();
        $signal = $this->makeInboxSignal(self::T, ['title' => 'T', 'body' => str_repeat('é', 3000)]);

        $lead = $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertOk();

        $description = (string) DB::table('crm_leads')->where('id', $lead->json('data.promoted_id'))->value('description');
        $this->assertSame(2000, mb_strlen($description));
        $this->assertTrue(mb_check_encoding($description, 'UTF-8'));
    }

    public function test_the_form_accepts_two_thousand_characters_and_refuses_more(): void
    {
        $user = $this->leadsUser();
        $make = fn (int $length) => $this->postJson('/api/crm/leads', ['lastName' => 'Verma', 'description' => str_repeat('x', $length), 'assignedTo' => $user['id']], $user['h']);

        $ok = $make(2000)->assertCreated();
        $this->assertSame(2000, mb_strlen((string) DB::table('crm_leads')->where('id', $ok->json('data.id'))->value('description')));
        $make(2001)->assertStatus(422);

        $id = (int) $ok->json('data.id');
        $this->putJson("/api/crm/leads/{$id}", ['description' => str_repeat('y', 2001)], $user['h'])->assertStatus(422);
        $this->putJson("/api/crm/leads/{$id}", ['description' => str_repeat('y', 1500)], $user['h'])->assertOk();
        $this->assertSame(1500, mb_strlen((string) DB::table('crm_leads')->where('id', $id)->value('description')));
        $this->assertSame(0, DB::table('crm_leads')->where('last_name', 'Verma')->count() - 1, 'the refused create made no lead');
    }

    public function test_an_import_row_with_too_long_a_description_is_refused_and_the_others_are_made(): void
    {
        $user = $this->leadsUser();

        $res = $this->postJson('/api/crm/leads/import', ['rows' => [
            ['lastName' => 'Fine', 'description' => str_repeat('x', 2000), 'assignedTo' => $user['id']],
            ['lastName' => 'TooLong', 'description' => str_repeat('x', 2001), 'assignedTo' => $user['id']],
        ]], $user['h'])->assertOk();

        $this->assertSame(1, $res->json('data.created'));
        $this->assertFalse($res->json('data.results.1.ok'));
        $this->assertSame(['Fine'], DB::table('crm_leads')->pluck('last_name')->all());
    }

    /** @param array<string, mixed> $over */
    private function makeInboxSignal(int $tenant, array $over = []): int
    {
        return (int) DB::table('crm_signal_inbox')->insertGetId($over + [
            'signal_key' => 'k' . uniqid(), 'title' => 'A signal', 'status' => 'pending', 'classification' => 'unclassified',
            'ingested_at' => now(), 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
