<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * First-touch attribution (slice 9): `crm_leads.campaign_id` is the campaign a lead first came from. It is set when the lead is made (manual form, CSV import,
 * public web form, or LeadFactory directly), only to one of THE ORGANISATION'S OWN live campaigns (else 422), and it is never replaced by a later request. The lead
 * presenter shows it as `campaign: {id, name}`.
 */
class LeadCampaignAttributionTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    /** The keys a lead had before slice 9 (the nine of slice 6 are the first thirty-three of these plus the three it added): they must all still be there, in this order. */
    private const LEGACY_KEYS = [
        'id', 'leadNo', 'salutation', 'firstName', 'lastName', 'company', 'designation', 'email', 'secondaryEmail', 'phone', 'mobile', 'fax', 'website', 'industry', 'leadSource',
        'leadStatus', 'rating', 'leadScore', 'leadScoreBasis', 'annualRevenue', 'numberOfEmp', 'emailOptOut', 'lifecycleStatus', 'nextAction', 'followUpDueDate', 'consentStatus',
        'suppressedAt', 'campaignText', 'street', 'city', 'state', 'country', 'postalCode', 'poBox', 'converted', 'convertedOrganizationId', 'convertedContactId',
        'convertedOpportunityId', 'sourceCompanyOpportunityId', 'description', 'assignedTo', 'createdBy', 'createdAt', 'updatedAt', 'missingFields', 'hasRealName', 'enrichmentStatus',
        'isQualifiedForOutreach', 'product', 'organisation_account', 'source_signal_id',
    ];

    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->user = $this->campaignsUser();
    }

    /** @param array<string, mixed> $over */
    private function body(array $over = []): array
    {
        return $over + ['lastName' => 'Verma', 'company' => 'Greenfield School', 'leadStatus' => 'New', 'assignedTo' => $this->user['id']];
    }

    private function campaignOf(int $leadId): ?int
    {
        $value = DB::table('crm_leads')->where('id', $leadId)->value('campaign_id');

        return $value === null ? null : (int) $value;
    }

    // ── the presenter ─────────────────────────────────────────────────────────

    public function test_every_old_key_is_still_there_and_campaign_is_the_one_new_key_null_for_a_lead_with_none(): void
    {
        $id = $this->makeLead(self::T, ['assigned_to' => $this->user['id']]);

        $item = $this->getJson('/api/crm/leads', $this->user['h'])->assertOk()->json('data.items.0');
        $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');

        $this->assertSame(self::LEGACY_KEYS, array_slice(array_keys($item), 0, count(self::LEGACY_KEYS)), 'nothing that was there is gone, renamed or moved');
        $this->assertSame(['campaign'], array_slice(array_keys($item), count(self::LEGACY_KEYS)), 'the list adds exactly this one');
        $this->assertSame(['campaign', 'source_signal', 'consent'], array_slice(array_keys($detail), count(self::LEGACY_KEYS)), 'the detail keeps its two extras after it');
        $this->assertNull($item['campaign']);
        $this->assertNull($detail['campaign']);
        $this->assertNull($item['campaignText'], 'the old free-text field is untouched');
    }

    public function test_a_lead_shows_the_campaign_that_first_touched_it_by_its_current_name(): void
    {
        $campaign = $this->makeCampaign(self::T, ['name' => 'Diwali outreach']);
        $id = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $campaign, 'campaign_text' => 'diwali old text']);

        $item = $this->getJson('/api/crm/leads', $this->user['h'])->assertOk()->json('data.items.0');
        $this->assertSame(['id' => $campaign, 'name' => 'Diwali outreach'], $item['campaign']);
        $this->assertSame('diwali old text', $item['campaignText']);

        DB::table('crm_campaigns')->where('id', $campaign)->update(['name' => 'Diwali outreach 2026']);
        $this->assertSame(['id' => $campaign, 'name' => 'Diwali outreach 2026'], $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->json('data.campaign'));
    }

    public function test_a_campaign_that_is_not_this_organisations_live_one_shows_nothing(): void
    {
        $theirs = $this->makeCampaign(self::T2, ['name' => 'Their Secret Campaign']);
        $binned = $this->makeCampaign(self::T, ['name' => 'Binned Campaign', 'deleted_at' => now()]);
        $planted = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $theirs]);
        $inBin = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $binned]);
        $gone = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => 9_999_999]);

        foreach ([$planted, $inBin, $gone] as $id) {
            $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');
            $this->assertNull($detail['campaign']);
            $this->assertStringNotContainsString('Secret', json_encode($detail));
        }
        $this->assertSame([null, null, null], array_column($this->getJson('/api/crm/leads?sort_by=created_at', $this->user['h'])->assertOk()->json('data.items'), 'campaign'));
    }

    public function test_a_list_names_the_campaigns_of_its_page_with_one_query(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $this->makeCampaign(self::T, ['name' => "C{$i}"])]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/crm/leads', $this->user['h'])->assertOk();
        $campaignQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "crm_campaigns"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $campaignQueries, 'five leads with five campaigns are named with one query, not five');
    }

    // ── creation: the manual form ─────────────────────────────────────────────

    public function test_a_new_lead_can_name_the_campaign_it_came_from(): void
    {
        $campaign = $this->makeCampaign(self::T, ['name' => 'Diwali outreach']);

        $created = $this->postJson('/api/crm/leads', $this->body(['campaign_id' => $campaign]), $this->user['h'])->assertCreated()->json('data');

        $this->assertSame(['id' => $campaign, 'name' => 'Diwali outreach'], $created['campaign']);
        $this->assertSame($campaign, $this->campaignOf((int) $created['id']));
    }

    public function test_the_camel_case_spelling_is_accepted_and_the_snake_case_one_wins_if_both_are_sent(): void
    {
        $a = $this->makeCampaign();
        $b = $this->makeCampaign();

        $camel = $this->postJson('/api/crm/leads', $this->body(['campaignId' => $a]), $this->user['h'])->assertCreated()->json('data.id');
        $both = $this->postJson('/api/crm/leads', $this->body(['campaign_id' => $b, 'campaignId' => $a]), $this->user['h'])->assertCreated()->json('data.id');

        $this->assertSame($a, $this->campaignOf((int) $camel));
        $this->assertSame($b, $this->campaignOf((int) $both));
    }

    public function test_a_lead_made_with_a_campaign_of_another_organisation_is_422_and_no_lead_is_made(): void
    {
        $theirs = $this->makeCampaign(self::T2, ['name' => 'Their Secret Campaign']);
        $binned = $this->makeCampaign(self::T, ['deleted_at' => now()]);
        $before = DB::table('crm_leads')->count();

        $other = $this->postJson('/api/crm/leads', $this->body(['campaign_id' => $theirs]), $this->user['h']);
        $missing = $this->postJson('/api/crm/leads', $this->body(['campaign_id' => 9_999_999]), $this->user['h']);
        $inBin = $this->postJson('/api/crm/leads', $this->body(['campaign_id' => $binned]), $this->user['h']);

        $other->assertStatus(422)->assertJsonPath('code', 'campaign_not_found')->assertJsonPath('message', 'Campaign not found.');
        $this->assertSame($other->json('message'), $missing->assertStatus(422)->json('message'), 'another organisation\'s campaign and a missing one are answered alike');
        $inBin->assertStatus(422);
        $this->assertStringNotContainsString('Secret', $other->getContent());
        $this->assertSame($before, DB::table('crm_leads')->count(), 'refused: nothing was made');
    }

    public function test_a_malformed_campaign_is_422_and_an_empty_one_means_none(): void
    {
        foreach (['abc', 0, -3, 1.5, [1], true] as $bad) {
            $this->postJson('/api/crm/leads', $this->body(['campaign_id' => $bad]), $this->user['h'])->assertStatus(422)->assertJsonPath('code', 'campaign_invalid');
        }
        $this->assertSame(0, DB::table('crm_leads')->count());

        foreach ([null, ''] as $empty) {
            $id = $this->postJson('/api/crm/leads', $this->body(['campaign_id' => $empty]), $this->user['h'])->assertCreated()->json('data.id');
            $this->assertNull($this->campaignOf((int) $id));
        }
    }

    // ── creation: the CSV import and the public form ─────────────────────────

    public function test_the_import_links_each_good_row_and_rejects_a_row_with_another_organisations_campaign(): void
    {
        $mine = $this->makeCampaign();
        $theirs = $this->makeCampaign(self::T2);

        $res = $this->postJson('/api/crm/leads/import', ['rows' => [
            $this->body(['lastName' => 'One', 'campaign_id' => $mine]),
            $this->body(['lastName' => 'Two', 'campaign_id' => $theirs]),
            $this->body(['lastName' => 'Three']),
        ]], $this->user['h'])->assertOk();

        $this->assertSame(2, $res->json('data.created'));
        $this->assertSame([true, false, true], array_column($res->json('data.results'), 'ok'));
        $this->assertSame('Campaign not found.', $res->json('data.results.1.reason'));
        $this->assertSame($mine, $this->campaignOf((int) DB::table('crm_leads')->where('last_name', 'One')->value('id')));
        $this->assertNull($this->campaignOf((int) DB::table('crm_leads')->where('last_name', 'Three')->value('id')));
        $this->assertSame(0, DB::table('crm_leads')->where('last_name', 'Two')->count());
    }

    private function openForm(string $key = 'pk_s9_key', int $tenant = self::T): void
    {
        DB::table('crm_lead_capture_settings')->insert(['sub_institute_id' => $tenant, 'public_key' => $key, 'is_enabled' => 1, 'default_lead_source' => 'Website', 'default_assigned_to' => $this->user['id'], 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_public_form_links_the_campaign_of_its_own_organisation_only(): void
    {
        $this->openForm();
        $mine = $this->makeCampaign(self::T);
        $theirs = $this->makeCampaign(self::T2, ['name' => 'Their Secret Campaign']);

        $this->postJson('/api/public/lead-capture/pk_s9_key', ['lastName' => 'Visitor', 'email' => 'v@school.example', 'campaign_id' => $mine])->assertCreated();
        $bad = $this->postJson('/api/public/lead-capture/pk_s9_key', ['lastName' => 'Intruder', 'email' => 'i@school.example', 'campaign_id' => $theirs]);

        $this->assertSame($mine, $this->campaignOf((int) DB::table('crm_leads')->where('last_name', 'Visitor')->value('id')));
        $bad->assertStatus(422)->assertJsonPath('message', 'Campaign not found.');
        $this->assertStringNotContainsString('Secret', $bad->getContent());
        $this->assertSame(0, DB::table('crm_leads')->where('last_name', 'Intruder')->count());
        $this->assertSame(1, (int) DB::table('crm_lead_capture_settings')->where('public_key', 'pk_s9_key')->value('submissions_count'), 'a refused submission is not counted');
    }

    public function test_the_public_form_checks_the_campaign_before_the_duplicate_shortcut_and_a_form_without_one_works_as_before(): void
    {
        $this->openForm();
        $theirs = $this->makeCampaign(self::T2);
        $this->postJson('/api/public/lead-capture/pk_s9_key', ['lastName' => 'First', 'email' => 'same@school.example'])->assertCreated();

        // Same email within the day: the form answers "Thanks" without a second lead, EXCEPT that a bad campaign is still refused.
        $this->postJson('/api/public/lead-capture/pk_s9_key', ['lastName' => 'Again', 'email' => 'same@school.example', 'campaign_id' => $theirs])->assertStatus(422);
        $this->postJson('/api/public/lead-capture/pk_s9_key', ['lastName' => 'Again', 'email' => 'same@school.example'])->assertOk();

        $this->assertSame(1, DB::table('crm_leads')->where('email', 'same@school.example')->count());
        $this->assertNull($this->campaignOf((int) DB::table('crm_leads')->where('last_name', 'First')->value('id')));
    }

    public function test_the_factory_stores_only_a_campaign_the_organisation_may_link(): void
    {
        $mine = $this->makeCampaign(self::T);
        $theirs = $this->makeCampaign(self::T2);
        $binned = $this->makeCampaign(self::T, ['deleted_at' => now()]);
        $make = fn ($campaign) => LeadFactory::create(['last_name' => 'F' . uniqid(), 'assigned_to' => $this->user['id'], 'campaign_id' => $campaign], self::T, $this->user['id']);

        $this->assertSame($mine, $this->campaignOf($make($mine)));
        $this->assertNull($this->campaignOf($make($theirs)), 'another organisation\'s campaign is dropped, never stored');
        $this->assertNull($this->campaignOf($make($binned)));
        $this->assertNull($this->campaignOf($make(0)));
        $this->assertNull($this->campaignOf(LeadFactory::create(['last_name' => 'NoCampaign', 'assigned_to' => $this->user['id']], self::T, $this->user['id'])));
    }

    // ── first touch: never overwritten ────────────────────────────────────────

    public function test_a_later_request_that_names_a_different_campaign_never_replaces_the_first(): void
    {
        $first = $this->makeCampaign(self::T, ['name' => 'First']);
        $second = $this->makeCampaign(self::T, ['name' => 'Second']);
        $id = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $first]);

        $snake = $this->putJson("/api/crm/leads/{$id}", ['company' => 'Changed Co', 'campaign_id' => $second], $this->user['h'])->assertOk();
        $camel = $this->putJson("/api/crm/leads/{$id}", ['campaignId' => $second], $this->user['h'])->assertOk();

        $this->assertSame($first, $this->campaignOf($id));
        $this->assertSame('First', $snake->json('data.campaign.name'));
        $this->assertSame('First', $camel->json('data.campaign.name'));
        $this->assertSame('Changed Co', DB::table('crm_leads')->where('id', $id)->value('company'), 'the rest of the update still applied');
    }

    public function test_an_update_cannot_set_a_campaign_on_a_lead_that_had_none(): void
    {
        $campaign = $this->makeCampaign();
        $id = $this->makeLead(self::T, ['assigned_to' => $this->user['id']]);

        $this->putJson("/api/crm/leads/{$id}", ['campaign_id' => $campaign, 'company' => 'Changed Co'], $this->user['h'])->assertOk()->assertJsonPath('data.campaign', null);

        $this->assertNull($this->campaignOf($id), 'first touch is recorded when the lead is made (or by the backfill), not by a later edit');
    }

    public function test_a_merge_keeps_the_survivors_campaign_and_fills_a_blank_one_from_the_duplicate(): void
    {
        $a = $this->makeCampaign(self::T, ['name' => 'A']);
        $b = $this->makeCampaign(self::T, ['name' => 'B']);
        $keeps = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $a, 'email' => 'k@school.example']);
        $dupOfKeeps = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $b, 'email' => 'k@school.example']);
        $blank = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'email' => 'b@school.example']);
        $dupOfBlank = $this->makeLead(self::T, ['assigned_to' => $this->user['id'], 'campaign_id' => $b, 'email' => 'b@school.example']);

        $this->postJson('/api/crm/leads/merge', ['survivorId' => $keeps, 'duplicateIds' => [$dupOfKeeps]], $this->user['h'])->assertOk();
        $this->postJson('/api/crm/leads/merge', ['survivorId' => $blank, 'duplicateIds' => [$dupOfBlank]], $this->user['h'])->assertOk();

        $this->assertSame($a, $this->campaignOf($keeps), 'the survivor\'s first touch stands');
        $this->assertSame($b, $this->campaignOf($blank), 'a blank first touch is filled from the duplicate, never over a value');
    }
}
