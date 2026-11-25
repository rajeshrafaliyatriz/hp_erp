<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * S20, the funnel: Signal -> PROSPECTING (the target account and its people) -> a PERSON qualified as a LEAD -> Convert Lead.
 *
 *   - POST /signals/opportunities/{id}/send-to-prospecting puts the signal's company in Prospecting, under the signal's own product, customer type and place.
 *   - POST /gtm/accounts/{id}/qualify-as-lead makes ONE lead that names the person, moves the account to Engaged, and is idempotent per person.
 *   - POST /signals/opportunities/{id}/promote-to-crm no longer makes a lead for anyone.
 */
class ProspectingFunnelTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->buildSignalsWorld();
        foreach (['2026_12_13_100000_add_product_scope_to_gtm_accounts', '2026_12_20_100000_add_region_to_gtm_accounts', '2026_12_27_090000_add_prospecting_links_to_crm_leads', '2026_12_27_090100_add_source_signal_to_crm_opportunities'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        if (! Schema::hasColumn('gtm_accounts', 'crm_organization_id')) {
            (require base_path('database/migrations/2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table.php'))->up();
        }
        $this->admin = $this->adminWithLeads();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $over */
    private function account(array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('gtm_accounts')->insertGetId($over + ['sub_institute_id' => $tenant, 'name' => 'Greenfield School', 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $over */
    private function contact(int $account, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('gtm_contacts')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'account_id' => $account, 'full_name' => 'Rahul Verma', 'title' => 'Principal', 'email' => 'rahul@greenfield.example', 'phone' => '+91 98250 00000',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function qualify(int $account, int $contact, array $extra = [], ?array $as = null)
    {
        return $this->postJson("/api/gtm/accounts/{$account}/qualify-as-lead", ['contactId' => $contact] + $extra, ($as ?? $this->admin)['h']);
    }

    private function send(int $signalId, ?array $as = null)
    {
        return $this->postJson("/api/signals/opportunities/{$signalId}/send-to-prospecting", [], ($as ?? $this->admin)['h']);
    }

    private function lead(int $id): object
    {
        return DB::table('crm_leads')->where('id', $id)->first();
    }

    /** A second signal of the same company as $first. @param array<string, mixed> $over */
    private function sameCompanySignal(CompanyOpportunity $first, array $over): CompanyOpportunity
    {
        $copy = $first->replicate();
        $copy->fill($over + ['fingerprint' => uniqid(), 'title' => 'T ' . uniqid()]);
        $copy->save();

        return $copy;
    }

    // ── send to Prospecting ──────────────────────────────────────────────────

    public function test_a_signal_is_sent_to_prospecting_under_its_own_product_customer_type_and_place(): void
    {
        $signal = $this->makeSignal(self::T, [
            'product_key' => 'scholarclone', 'customer_model' => 'b2c', 'region_scope' => json_encode([['country' => 'India', 'state' => 'Gujarat', 'city' => null]]),
        ], ['name' => 'TVRLS', 'normalized_name' => 'tvrls', 'website' => 'https://www.tvrls.example', 'industry' => 'Education']);

        $res = $this->send($signal->id)->assertCreated();

        $account = DB::table('gtm_accounts')->sole();
        $this->assertSame(['status' => 1, 'data' => ['account_id' => (int) $account->id, 'created' => true]], $res->json());
        $this->assertSame(['TVRLS', (int) $signal->company_id, 'scholarclone', 'b2c', 'India', 'Gujarat', 'tvrls.example', 'target', 'research'], [
            $account->name, (int) $account->company_id, $account->product_key, $account->customer_model, $account->region_country, $account->region_state, $account->domain, $account->stage, $account->source,
        ]);
        $this->assertSame(0, DB::table('crm_leads')->count(), 'sending a company to Prospecting makes no lead');
    }

    public function test_a_signal_for_several_places_or_none_files_the_account_without_a_place(): void
    {
        $several = $this->makeSignal(self::T, ['product_key' => 'scholarclone', 'customer_model' => 'b2b', 'region_scope' => json_encode([
            ['country' => 'India', 'state' => 'Gujarat', 'city' => null], ['country' => 'India', 'state' => 'Kerala', 'city' => null],
        ])]);
        $none = $this->makeSignal(self::T);

        $this->send($several->id)->assertCreated();
        $this->send($none->id)->assertCreated();

        $a = DB::table('gtm_accounts')->where('company_id', $several->company_id)->first();
        $b = DB::table('gtm_accounts')->where('company_id', $none->company_id)->first();
        $this->assertSame([null, ''], [$a->region_country, $a->region_key]);
        $this->assertSame([null, null], [$b->product_key, $b->customer_model]);
    }

    public function test_sending_again_answers_the_same_account_with_created_false(): void
    {
        $signal = $this->makeSignal(self::T, ['product_key' => 'scholarclone', 'customer_model' => 'b2c']);
        $first = $this->send($signal->id)->assertCreated();

        $again = $this->send($signal->id)->assertOk();

        $this->assertSame($first->json('data.account_id'), $again->json('data.account_id'));
        $this->assertFalse($again->json('data.created'));
        $this->assertSame(1, DB::table('gtm_accounts')->count());
        $this->assertSame(1, DB::table('gtm_activities')->where('subject', 'Added from research signals')->count());
    }

    public function test_a_company_already_in_prospecting_from_the_research_tab_is_answered_not_duplicated(): void
    {
        $signal = $this->makeSignal(self::T, ['product_key' => 'scholarclone', 'customer_model' => 'b2c']);
        $this->postJson("/api/gtm/accounts/from-company/{$signal->company_id}", ['product' => 'scholarclone', 'customer_model' => 'b2c'], $this->admin['h'])->assertCreated();

        $this->send($signal->id)->assertOk()->assertJsonPath('data.created', false);

        $this->assertSame(1, DB::table('gtm_accounts')->count(), 'the same logic as the Research tab: one account per company and scope');
    }

    public function test_another_organisations_signal_is_a_404_and_writes_nothing(): void
    {
        $theirs = $this->makeSignal(self::T2);

        $this->send($theirs->id)->assertNotFound();
        $this->send(987654)->assertNotFound();

        $this->assertSame(0, DB::table('gtm_accounts')->count());
            }

    public function test_send_to_prospecting_needs_the_signals_tier_and_a_prospecting_account_to_be_addable(): void
    {
        $signal = $this->makeSignal(self::T);
        $employee = $this->user([], self::T);

        $this->send($signal->id, $employee)->assertForbidden();
        $this->postJson("/api/signals/opportunities/{$signal->id}/send-to-prospecting")->assertUnauthorized();

        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods(), true) && $r->uri() === 'api/signals/opportunities/{id}/send-to-prospecting');
        $this->assertNotNull($route);
        $this->assertContains('profile:admin,hr,manager', $route->gatherMiddleware(), 'the Signals tier that may act on a signal');
        $this->assertContains('profile:admin', $route->gatherMiddleware(), 'and the Prospecting add right (gtm.permissions.use_menu_rights off: admin)');
        $this->assertSame(0, DB::table('gtm_accounts')->count());
    }

    // ── automatic Prospecting: a saved qualified signal puts its company in ───────

    /** A hand-added signal, which goes through the one importer every source uses. */
    private function saveManualSignal(string $company, string $url = 'https://news.example.com/a', ?array $as = null)
    {
        return $this->postJson('/api/signals/opportunities/manual', [
            'product' => 'scholarclone', 'customer_model' => 'b2c', 'company_name' => $company, 'summary' => 'They announced a new campus and will hire staff.', 'source_url' => $url,
        ], ($as ?? $this->admin)['h']);
    }

    public function test_a_saved_qualified_signal_puts_its_company_into_prospecting_by_itself_and_only_once(): void
    {
        $this->saveManualSignal('Greenfield School')->assertCreated();

        $account = DB::table('gtm_accounts')->sole();
        $this->assertSame(['Greenfield School', 'target', 'scholarclone', 'b2c', 'research'], [$account->name, $account->stage, $account->product_key, $account->customer_model, $account->source]);
        $this->assertSame(1, DB::table('gtm_activities')->where('account_id', $account->id)->where('subject', 'Added from research signals')->count());

        // A second signal of the same company leaves the account exactly as it is, even when somebody has moved it on since.
        DB::table('gtm_accounts')->where('id', $account->id)->update(['stage' => 'engaged', 'notes' => 'called them']);
        $first = CompanyOpportunity::sole();
        $second = $this->sameCompanySignal($first, ['product_key' => 'scholarclone', 'customer_model' => 'b2c']);
        $this->assertSame($first->company_id, $second->company_id);
        $this->assertNotNull(\App\Domain\Gtm\ProspectAccounts::autoAdd(self::T, $second), 'the account that is there is answered');

        $this->assertSame(1, DB::table('gtm_accounts')->count());
        $this->assertSame(['engaged', 'called them'], [DB::table('gtm_accounts')->value('stage'), DB::table('gtm_accounts')->value('notes')], 'an existing account is never edited');
    }

    public function test_dismissed_sample_and_expired_signals_do_not_put_their_company_in_and_neither_does_the_off_switch(): void
    {
        foreach ([['review_status' => 'Dismissed'], ['is_sample' => true], ['expires_at' => now()->subDay()->toDateString()]] as $over) {
            $this->assertNull(\App\Domain\Gtm\ProspectAccounts::autoAdd(self::T, $this->makeSignal(self::T, $over)), json_encode($over));
        }
        $this->assertSame(0, DB::table('gtm_accounts')->count());

        $ok = $this->makeSignal(self::T, ['expires_at' => now()->addDay()->toDateString()]);
        config(['gtm.auto_prospecting' => false]);
        $this->assertNull(\App\Domain\Gtm\ProspectAccounts::autoAdd(self::T, $ok));
        $this->assertSame(0, DB::table('gtm_accounts')->count());

        config(['gtm.auto_prospecting' => true]);
        $this->assertNotNull(\App\Domain\Gtm\ProspectAccounts::autoAdd(self::T, $ok), 'an unexpired, live signal does');
        $this->assertSame(1, DB::table('gtm_accounts')->count());
    }

    public function test_an_account_that_was_removed_on_purpose_is_not_brought_back_by_a_new_signal(): void
    {
        $this->saveManualSignal('Greenfield School')->assertCreated();
        DB::table('gtm_accounts')->update(['deleted_at' => now()]);
        $second = $this->sameCompanySignal(CompanyOpportunity::sole(), ['product_key' => 'scholarclone', 'customer_model' => 'b2c']);

        $this->assertNull(\App\Domain\Gtm\ProspectAccounts::autoAdd(self::T, $second));

        $this->assertSame(1, DB::table('gtm_accounts')->count(), 'still only the removed one');
        $this->assertSame(0, DB::table('gtm_accounts')->whereNull('deleted_at')->count());
    }

    public function test_the_backfill_command_is_a_dry_run_by_default_inserts_only_and_leaves_existing_and_removed_accounts_alone(): void
    {
        $new = $this->makeSignal(self::T, [], ['name' => 'New Co', 'normalized_name' => 'new co']);
        $existing = $this->makeSignal(self::T, [], ['name' => 'Existing Co', 'normalized_name' => 'existing co']);
        $removed = $this->makeSignal(self::T, [], ['name' => 'Removed Co', 'normalized_name' => 'removed co']);
        $this->makeSignal(self::T, ['review_status' => 'Dismissed'], ['name' => 'Dismissed Co', 'normalized_name' => 'dismissed co']);
        $this->makeSignal(self::T2, [], ['name' => 'Other Org Co', 'normalized_name' => 'other org co']);
        $keep = $this->account(['company_id' => $existing->company_id, 'name' => 'Existing Co', 'stage' => 'customer', 'notes' => 'mine']);
        $gone = $this->account(['company_id' => $removed->company_id, 'name' => 'Removed Co', 'deleted_at' => now()]);

        $this->artisan('signals:send-to-prospecting', ['--tenant' => self::T])->expectsOutputToContain('DRY RUN')->expectsOutputToContain('would add 1 account(s); 1 already in Prospecting; 1 removed on purpose')->assertSuccessful();
        $this->assertSame(2, DB::table('gtm_accounts')->count(), 'a dry run writes nothing');

        $this->artisan('signals:send-to-prospecting', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('added 1 account(s)')->assertSuccessful();
        $this->artisan('signals:send-to-prospecting', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('added 0 account(s)')->assertSuccessful();

        $this->assertSame(3, DB::table('gtm_accounts')->count());
        $made = DB::table('gtm_accounts')->where('company_id', $new->company_id)->sole();
        $this->assertSame(['New Co', 'target'], [$made->name, $made->stage]);
        $this->assertSame(['customer', 'mine'], [DB::table('gtm_accounts')->where('id', $keep)->value('stage'), DB::table('gtm_accounts')->where('id', $keep)->value('notes')], 'an existing account is never edited');
        $this->assertNotNull(DB::table('gtm_accounts')->where('id', $gone)->value('deleted_at'), 'a removed account stays removed');
        $this->assertSame(0, DB::table('gtm_accounts')->where('sub_institute_id', self::T2)->count(), 'only the organisation asked for');
    }

    // ── Convert to lead (promote-to-crm), with an optional person ─────────────

    public function test_convert_to_lead_without_a_person_is_the_lead_it_always_was_and_now_puts_the_company_in_prospecting(): void
    {
        $signal = $this->makeSignal(self::T, ['product_key' => 'scholarclone', 'customer_model' => 'b2c'], ['name' => 'Tapovan Vidya Vihar', 'normalized_name' => 'tapovan']);

        $res = $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", [], $this->admin['h'])->assertCreated();

        $lead = $this->lead((int) $res->json('data.lead_id'));
        $this->assertSame(['status' => 1, 'data' => ['lead_id' => (string) $lead->id, 'created' => true]], $res->json());
        $this->assertSame([null, 'Tapovan Vidya Vihar', 'Tapovan Vidya Vihar', 'Signals', (int) $signal->id], [$lead->first_name, $lead->last_name, $lead->company, $lead->lead_source, (int) $lead->source_company_opportunity_id]);
        $account = DB::table('gtm_accounts')->sole();
        $this->assertSame([(int) $signal->company_id, 'engaged', (int) $account->id, null], [(int) $account->company_id, $account->stage, (int) $lead->gtm_account_id, $lead->gtm_contact_id], 'the account was made, linked, and moved to engaged');

        $again = $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", [], $this->admin['h'])->assertOk();
        $this->assertSame([(string) $lead->id, false], [$again->json('data.lead_id'), $again->json('data.created')]);
        $this->assertSame(1, DB::table('crm_leads')->count());
    }

    public function test_convert_to_lead_with_a_person_names_the_person_and_refuses_those_who_cannot_be_contacted(): void
    {
        $signal = $this->makeSignal(self::T, [], ['name' => 'Greenfield School', 'normalized_name' => 'greenfield']);
        $account = $this->account(['company_id' => $signal->company_id, 'stage' => 'target']);
        $rahul = $this->contact($account);
        $dnc = $this->contact($account, ['full_name' => 'Do Not', 'email' => 'dnc@x.example', 'status' => 'do_not_contact']);

        $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", ['contactId' => $dnc], $this->admin['h'])->assertStatus(422)
            ->assertJsonPath('message', 'This person asked not to be contacted, so a lead cannot be made for them.');
        $this->assertSame(0, DB::table('crm_leads')->count());

        $res = $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", ['contactId' => $rahul], $this->admin['h'])->assertCreated();

        $lead = $this->lead((int) $res->json('data.lead_id'));
        $this->assertSame(['Rahul', 'Verma', 'Greenfield School', 'Principal', 'rahul@greenfield.example', (int) $signal->id, $account, $rahul], [
            $lead->first_name, $lead->last_name, $lead->company, $lead->designation, $lead->email, (int) $lead->source_company_opportunity_id, (int) $lead->gtm_account_id, (int) $lead->gtm_contact_id,
        ]);
        $this->assertSame('engaged', DB::table('gtm_accounts')->where('id', $account)->value('stage'));
        $this->assertSame(1, DB::table('crm_lead_comments')->where('lead_id', $lead->id)->count(), 'the evidence note, once');

        $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", ['contactId' => $rahul], $this->admin['h'])->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame(1, DB::table('crm_leads')->count(), 'one lead per signal');
    }

    public function test_convert_to_lead_answers_404_for_a_person_who_is_not_of_this_companys_account_or_this_organisation(): void
    {
        $signal = $this->makeSignal(self::T);
        $this->account(['company_id' => $signal->company_id]);
        $elsewhere = $this->contact($this->account(['name' => 'Another School']));
        $theirs = $this->contact($this->account([], self::T2), [], self::T2);

        $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", ['contactId' => $elsewhere], $this->admin['h'])->assertNotFound();
        $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", ['contactId' => $theirs], $this->admin['h'])->assertNotFound();
        $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", ['contactId' => 'x'], $this->admin['h'])->assertStatus(422);

        $this->assertSame(0, DB::table('crm_leads')->count());
    }

    public function test_the_account_stage_moves_forward_only_when_a_lead_is_converted(): void
    {
        foreach (['opportunity', 'customer', 'churned'] as $stage) {
            $signal = $this->makeSignal(self::T);
            $account = $this->account(['company_id' => $signal->company_id, 'stage' => $stage, 'name' => "At {$stage}"]);

            $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", [], $this->admin['h'])->assertCreated();

            $this->assertSame($stage, DB::table('gtm_accounts')->where('id', $account)->value('stage'), "a {$stage} account stays {$stage}");
        }
    }

    // ── qualify as lead ──────────────────────────────────────────────────────

    public function test_the_lead_names_the_person_and_carries_what_the_account_and_the_strongest_signal_say(): void
    {
        $product = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'kind' => 'product', 'product_key' => 'scholarclone', 'signal_key' => 'scholar', 'portfolio_name' => 'ScholarClone', 'name' => 'ScholarClone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $weak = $this->makeSignal(self::T, ['product_key' => 'scholarclone', 'customer_model' => 'b2c', 'priority' => 'Medium', 'title' => 'Weak', 'observed_event' => 'Minor news.'],
            ['name' => 'Greenfield School', 'normalized_name' => 'greenfield', 'website' => 'https://greenfield.example', 'industry' => 'Education']);
        $strong = $this->sameCompanySignal($weak, ['priority' => 'High', 'title' => 'Greenfield opens a campus', 'observed_event' => 'They opened a campus.', 'why_indicates_need' => 'They need software.', 'recommended_action' => 'Call the principal.',
            'sources' => [['url' => 'https://news.example.com/greenfield', 'title' => 'News', 'excerpt' => 'Greenfield will open a new campus in Surat.']]]);
        $this->sameCompanySignal($weak, ['priority' => 'High', 'title' => 'Dismissed one', 'review_status' => 'Dismissed', 'observed_event' => 'Dismissed text.']);
        $this->sameCompanySignal($weak, ['priority' => 'High', 'title' => 'Another product', 'product_key' => 'g2g', 'observed_event' => 'Other product text.']);
        $identity = (int) DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Greenfield', 'normalized_name' => 'greenfield', 'lifecycle' => 'discovered', 'g2g_company_id' => $weak->company_id, 'created_at' => now(), 'updated_at' => now()]);
        $account = $this->account(['company_id' => $weak->company_id, 'product_key' => 'scholarclone', 'customer_model' => 'b2c', 'website' => 'https://greenfield.example', 'industry' => 'Education']);
        $contact = $this->contact($account);

        $res = $this->qualify($account, $contact)->assertCreated();

        $lead = $this->lead((int) $res->json('data.lead_id'));
        $this->assertSame(['Rahul', 'Verma', 'Greenfield School', 'Principal', 'rahul@greenfield.example', '+91 98250 00000', 'Prospecting', 'New'], [
            $lead->first_name, $lead->last_name, $lead->company, $lead->designation, $lead->email, $lead->phone, $lead->lead_source, $lead->lead_status,
        ]);
        $this->assertSame(['https://greenfield.example', 'Education', $this->admin['id']], [$lead->website, $lead->industry, (int) $lead->assigned_to]);
        $this->assertSame('They opened a campus. They need software. Call the principal.', $lead->description, 'the strongest live signal of the account\'s own product and customer type');
        $this->assertSame([(int) $strong->id, $product, $identity, $account, $contact], [(int) $lead->source_company_opportunity_id, (int) $lead->product_master_id, (int) $lead->org_account_id, (int) $lead->gtm_account_id, (int) $lead->gtm_contact_id]);
        $this->assertSame($lead->lead_no, $res->json('data.lead_no'));
        $this->assertMatchesRegularExpression('/^LD-\d{5,}$/', (string) $lead->lead_no);
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'crm.lead.created')->where('entity_id', $lead->id)->count(), 'the lead\'s own event');
    }

    public function test_the_evidence_note_is_written_once_and_the_signals_history_says_a_lead_was_made(): void
    {
        $signal = $this->makeSignal(self::T, ['title' => 'Keystone opens', 'business_fit' => 'scholar', 'sources' => [['url' => 'https://news.example.com/k', 'title' => 'K', 'excerpt' => 'Keystone will hire.']]]);
        $account = $this->account(['company_id' => $signal->company_id]);
        $contact = $this->contact($account);

        $leadId = (int) $this->qualify($account, $contact)->assertCreated()->json('data.lead_id');
        $this->qualify($account, $contact)->assertOk();

        $notes = DB::table('crm_lead_comments')->where('lead_id', $leadId)->get();
        $this->assertCount(1, $notes, 'one structured note, however many times it is pressed');
        $this->assertStringContainsString('From a signal: Keystone opens', $notes[0]->comment);
        $this->assertStringContainsString('Quote: "Keystone will hire."', $notes[0]->comment);
        $history = DB::table('g2g_signal_reviews')->where('opportunity_id', $signal->id)->where('decision', 'promoted')->get();
        $this->assertCount(1, $history);
        $this->assertSame("Lead #{$leadId} created", $history[0]->note);
    }

    public function test_a_second_press_for_the_same_person_answers_the_same_lead_and_makes_nothing(): void
    {
        $signal = $this->makeSignal(self::T);
        $account = $this->account(['company_id' => $signal->company_id]);
        $contact = $this->contact($account);
        $first = $this->qualify($account, $contact)->assertCreated();
        $before = [DB::table('crm_leads')->count(), DB::table('crm_lead_comments')->count(), DB::table('gtm_activities')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('g2g_event')->count()];

        $again = $this->qualify($account, $contact)->assertOk();

        $this->assertSame($first->json('data.lead_id'), $again->json('data.lead_id'));
        $this->assertFalse($again->json('data.created'));
        $this->assertSame($before, [DB::table('crm_leads')->count(), DB::table('crm_lead_comments')->count(), DB::table('gtm_activities')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('g2g_event')->count()]);
    }

    public function test_two_people_at_the_same_account_are_two_leads_and_both_are_listed_on_the_account(): void
    {
        $account = $this->account();
        $asha = $this->contact($account, ['full_name' => 'Asha Maria Rao', 'email' => 'asha@greenfield.example']);
        $dev = $this->contact($account, ['full_name' => 'Dev', 'email' => 'dev@greenfield.example']);

        $a = (int) $this->qualify($account, $asha)->assertCreated()->json('data.lead_id');
        $d = (int) $this->qualify($account, $dev)->assertCreated()->json('data.lead_id');

        $this->assertNotSame($a, $d);
        $this->assertSame(['Asha Maria', 'Rao'], [$this->lead($a)->first_name, $this->lead($a)->last_name], 'the name is split on the LAST space');
        $this->assertSame([null, 'Dev'], [$this->lead($d)->first_name, $this->lead($d)->last_name], 'one word is a last name alone');
        $leads = $this->getJson("/api/gtm/accounts/{$account}", $this->admin['h'])->assertOk()->json('data.leads');
        $this->assertSame([
            ['id' => (string) $a, 'leadNo' => $this->lead($a)->lead_no, 'name' => 'Asha Maria Rao', 'status' => 'New', 'contactId' => $asha],
            ['id' => (string) $d, 'leadNo' => $this->lead($d)->lead_no, 'name' => 'Dev', 'status' => 'New', 'contactId' => $dev],
        ], $leads);
    }

    public function test_an_account_with_no_company_makes_a_lead_with_no_signal_and_no_note(): void
    {
        $account = $this->account(['website' => 'https://greenfield.example', 'industry' => 'Education']);
        $contact = $this->contact($account);

        $leadId = (int) $this->qualify($account, $contact)->assertCreated()->json('data.lead_id');

        $lead = $this->lead($leadId);
        $this->assertNull($lead->source_company_opportunity_id);
        $this->assertSame('Qualified in Prospecting from the account Greenfield School.', $lead->description);
        $this->assertSame(0, DB::table('crm_lead_comments')->count());
        $this->assertSame(0, DB::table('g2g_signal_reviews')->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unreachablePeople(): array
    {
        return [
            'do not contact' => ['do_not_contact', 'This person asked not to be contacted, so a lead cannot be made for them.'],
            'unsubscribed' => ['unsubscribed', 'This person has unsubscribed, so a lead cannot be made for them.'],
            'bounced' => ['bounced', 'Email to this person bounced, so a lead cannot be made for them until the address is fixed.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreachablePeople')]
    public function test_a_person_who_cannot_be_contacted_is_refused_with_a_plain_sentence_and_nothing_is_written(string $status, string $sentence): void
    {
        $signal = $this->makeSignal(self::T);
        $account = $this->account(['company_id' => $signal->company_id]);
        $contact = $this->contact($account, ['status' => $status]);

        $res = $this->qualify($account, $contact)->assertStatus(422);

        $this->assertSame($sentence, $res->json('message'));
        $this->assertSame(0, DB::table('crm_leads')->count());
        $this->assertSame(0, DB::table('crm_lead_comments')->count());
        $this->assertSame(0, DB::table('g2g_signal_reviews')->count());
        $this->assertSame('target', DB::table('gtm_accounts')->where('id', $account)->value('stage'), 'the account has not moved');
    }

    public function test_the_contact_must_be_a_live_contact_of_this_account_and_organisation(): void
    {
        $account = $this->account();
        $otherAccount = $this->account(['name' => 'Another School']);
        $theirAccount = $this->account(['name' => 'Their School'], self::T2);
        $removed = $this->contact($account);
        DB::table('gtm_contacts')->where('id', $removed)->update(['deleted_at' => now()]);

        $this->qualify($account, $this->contact($otherAccount))->assertNotFound();          // a person of another account of the same organisation
        $this->qualify($account, $this->contact($theirAccount, [], self::T2))->assertNotFound();   // a person of another organisation
        $this->qualify($account, $this->contact($account, ['email' => 'x@other.example'], self::T2))->assertNotFound();   // a row that points at THIS account but belongs to another organisation
        $this->qualify($account, $removed)->assertNotFound();                                // a removed person
        $this->qualify($account, 987654)->assertNotFound();
        $this->qualify($theirAccount, $this->contact($theirAccount, [], self::T2))->assertNotFound();   // another organisation's account, with its own person
        $this->qualify(987654, $this->contact($account))->assertNotFound();

        $this->assertSame(0, DB::table('crm_leads')->count());
        $this->assertSame(0, DB::table('gtm_activities')->count());
    }

    public function test_the_account_moves_from_target_to_engaged_and_never_goes_back_or_down(): void
    {
        $fresh = $this->account();
        $this->qualify($fresh, $this->contact($fresh))->assertCreated()->assertJsonPath('data.stage', 'engaged');
        $this->assertSame('engaged', DB::table('gtm_accounts')->where('id', $fresh)->value('stage'));
        $subjects = DB::table('gtm_activities')->where('account_id', $fresh)->orderBy('id')->pluck('subject')->all();
        $this->assertSame(['Qualified Rahul Verma as a lead (' . DB::table('crm_leads')->value('lead_no') . ')', 'Stage changed: target → engaged'], $subjects);

        foreach (['opportunity', 'customer', 'churned', 'disqualified', 'engaged'] as $stage) {
            $account = $this->account(['stage' => $stage, 'name' => "At {$stage}"]);
            $this->qualify($account, $this->contact($account))->assertCreated();
            $this->assertSame($stage, DB::table('gtm_accounts')->where('id', $account)->value('stage'), "a {$stage} account stays {$stage}");
            $this->assertSame(0, DB::table('gtm_activities')->where('account_id', $account)->where('subject', 'like', 'Stage changed%')->count());
        }
    }

    public function test_the_lead_goes_to_the_caller_by_default_and_to_a_user_of_the_organisation_when_asked(): void
    {
        $account = $this->account();
        $a = $this->contact($account, ['full_name' => 'A One', 'email' => 'a@x.example']);
        $b = $this->contact($account, ['full_name' => 'B Two', 'email' => 'b@x.example']);
        $c = $this->contact($account, ['full_name' => 'C Three', 'email' => 'c@x.example']);
        $colleague = $this->user([], self::T);
        $stranger = $this->user([], self::T2);

        $mine = (int) $this->qualify($account, $a)->assertCreated()->json('data.lead_id');
        $theirs = (int) $this->qualify($account, $b, ['assignedTo' => $colleague['id']])->assertCreated()->json('data.lead_id');
        $this->qualify($account, $c, ['assignedTo' => $stranger['id']])->assertStatus(422);
        $this->qualify($account, $c, ['assignedTo' => 987654])->assertStatus(422);

        $this->assertSame($this->admin['id'], (int) $this->lead($mine)->assigned_to);
        $this->assertSame($colleague['id'], (int) $this->lead($theirs)->assigned_to);
        $this->assertSame(2, DB::table('crm_leads')->count(), 'a refused assignee makes no lead');
    }

    public function test_a_missing_person_is_a_422_before_anything_is_looked_up(): void
    {
        $account = $this->account();

        $this->postJson("/api/gtm/accounts/{$account}/qualify-as-lead", [], $this->admin['h'])->assertStatus(422)->assertJsonPath('message', 'Choose the person this lead is for.');
        $this->postJson("/api/gtm/accounts/{$account}/qualify-as-lead", ['contactId' => 'x'], $this->admin['h'])->assertStatus(422);
    }

    public function test_qualifying_needs_the_leads_add_right_on_top_of_the_prospecting_edit_tier(): void
    {
        $account = $this->account();
        $contact = $this->contact($account);
        $viewOnly = $this->adminWithLeads(self::T, ['view']);
        $employee = $this->user([self::LEADS_LINK => ['view', 'add', 'edit']], self::T);

        $this->qualify($account, $contact, [], $viewOnly)->assertForbidden();   // the Prospecting tier, but no CRM Leads add
        $this->qualify($account, $contact, [], $employee)->assertForbidden();   // CRM Leads add, but not the Prospecting tier
        $this->postJson("/api/gtm/accounts/{$account}/qualify-as-lead", ['contactId' => $contact])->assertUnauthorized();
        $this->assertSame(0, DB::table('crm_leads')->count());

        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods(), true) && $r->uri() === 'api/gtm/accounts/{id}/qualify-as-lead');
        $this->assertNotNull($route);
        $this->assertContains('profile:admin', $route->gatherMiddleware(), 'Prospecting edit (gtm.permissions.use_menu_rights off: admin)');
        $this->assertContains('menuright:201,add', $route->gatherMiddleware(), 'CRM Leads add, the same menu id every /crm/leads route uses');
        $this->qualify($account, $contact)->assertCreated();
    }

    public function test_the_lead_its_note_and_the_account_move_stand_together_or_not_at_all(): void
    {
        $signal = $this->makeSignal(self::T);
        $account = $this->account(['company_id' => $signal->company_id]);
        $contact = $this->contact($account);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert into "crm_lead_comments"')) {
                throw new \RuntimeException('the note could not be written');
            }
        });

        $this->qualify($account, $contact)->assertStatus(500);

        $this->assertSame([0, 0, 0, 'target'], [DB::table('crm_leads')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('gtm_activities')->count(), DB::table('gtm_accounts')->where('id', $account)->value('stage')]);
    }

    public function test_a_server_without_the_link_columns_refuses_plainly_and_the_account_lists_no_leads(): void
    {
        (require base_path('database/migrations/2026_12_27_090000_add_prospecting_links_to_crm_leads.php'))->down();
        $account = $this->account();
        $contact = $this->contact($account);

        $this->qualify($account, $contact)->assertStatus(503)->assertJsonPath('code', 'not_ready');
        $this->getJson("/api/gtm/accounts/{$account}", $this->admin['h'])->assertOk()->assertJsonPath('data.leads', []);

        $this->assertSame(0, DB::table('crm_leads')->count());
    }

    public function test_another_organisations_leads_are_never_listed_on_an_account(): void
    {
        $account = $this->account();
        $theirContact = $this->contact($this->account([], self::T2), [], self::T2);
        $this->makeLead(self::T2, ['gtm_account_id' => $account, 'gtm_contact_id' => $theirContact]);

        $this->getJson("/api/gtm/accounts/{$account}", $this->admin['h'])->assertOk()->assertJsonPath('data.leads', []);
    }
}
