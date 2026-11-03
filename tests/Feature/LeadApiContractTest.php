<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadConsentFilter;
use App\Domain\Facts\ConsentGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * What the Leads screen reads (slice 6, the CONTRACT): every lead gains `product`, `organisation_account` and `source_signal_id`; the detail also gains
 * `source_signal` and `consent`; the list gains the filters `product_id`, `has_signal` and `consent`. Nothing that was there changes.
 */
class LeadApiContractTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    /** The keys a lead had before this slice: they must all still be there, with the same names. */
    private const LEGACY_KEYS = [
        'id', 'leadNo', 'salutation', 'firstName', 'lastName', 'company', 'designation', 'email', 'secondaryEmail', 'phone', 'mobile', 'fax', 'website', 'industry', 'leadSource',
        'leadStatus', 'rating', 'leadScore', 'leadScoreBasis', 'annualRevenue', 'numberOfEmp', 'emailOptOut', 'lifecycleStatus', 'nextAction', 'followUpDueDate', 'consentStatus',
        'suppressedAt', 'campaignText', 'street', 'city', 'state', 'country', 'postalCode', 'poBox', 'converted', 'convertedOrganizationId', 'convertedContactId',
        'convertedOpportunityId', 'sourceCompanyOpportunityId', 'description', 'assignedTo', 'createdBy', 'createdAt', 'updatedAt', 'missingFields', 'hasRealName', 'enrichmentStatus',
        'isQualifiedForOutreach',
    ];

    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        $this->user = $this->leadsUser();
    }

    private function product(int $tenant, string $name, ?string $key = null): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => $tenant, 'kind' => 'product', 'product_key' => $key ?? strtolower($name), 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function identity(int $tenant, string $name, int $companyId = 0): int
    {
        return (int) DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'lifecycle' => 'discovered', 'g2g_company_id' => $companyId ?: null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $over */
    private function lead(array $over = [], int $tenant = self::T): int
    {
        return $this->makeLead($tenant, $over + ['assigned_to' => $this->user['id'], 'email' => 'p' . uniqid() . '@school.example']);
    }

    /** @return list<int> the ids a list call returned, in order */
    private function ids(string $query = ''): array
    {
        return array_map('intval', array_column($this->getJson('/api/crm/leads' . $query, $this->user['h'])->assertOk()->json('data.items'), 'id'));
    }

    // ── the presenter ─────────────────────────────────────────────────────────

    public function test_every_old_key_is_still_there_and_the_three_new_ones_are_null_for_a_lead_with_no_links(): void
    {
        $id = $this->lead();

        $item = $this->getJson('/api/crm/leads', $this->user['h'])->assertOk()->json('data.items.0');
        $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');

        foreach ([$item, $detail] as $lead) {
            $this->assertSame([], array_values(array_diff(self::LEGACY_KEYS, array_keys($lead))), 'no key that was there is gone or renamed');
            $this->assertNull($lead['product']);
            $this->assertNull($lead['organisation_account']);
            $this->assertNull($lead['source_signal_id']);
        }
        $this->assertSame(['product', 'organisation_account', 'source_signal_id'], array_slice(array_keys($item), count(self::LEGACY_KEYS)), 'the list adds exactly these three');
        $this->assertSame(['source_signal', 'consent'], array_slice(array_keys($detail), count(self::LEGACY_KEYS) + 3), 'the detail adds exactly these two more');
        $this->assertNull($detail['source_signal']);
    }

    public function test_a_lead_shows_its_product_its_organisation_account_and_its_signal(): void
    {
        $product = $this->product(0, 'ScholarClone', 'scholarclone');
        $account = $this->identity(self::T, 'Greenfield School');
        $signal = $this->makeSignal(self::T, ['title' => 'Greenfield opens a campus', 'business_fit' => 'scholar', 'event_date' => '2026-09-25', 'source_published_at' => '2026-09-26',
            'source_url' => 'https://news.example.com/greenfield', 'sources' => [['url' => 'https://news.example.com/greenfield', 'excerpt' => 'Greenfield will open a campus in Pune.']]]);
        $id = $this->lead(['product_master_id' => $product, 'org_account_id' => $account, 'source_company_opportunity_id' => $signal->id]);

        $item = $this->getJson('/api/crm/leads', $this->user['h'])->assertOk()->json('data.items.0');
        $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');

        foreach ([$item, $detail] as $lead) {
            $this->assertSame(['id' => $product, 'key' => 'scholarclone', 'name' => 'ScholarClone'], $lead['product']);
            $this->assertSame(['id' => $account, 'name' => 'Greenfield School'], $lead['organisation_account']);
            $this->assertSame($signal->id, $lead['source_signal_id']);
        }
        $this->assertSame([
            'id' => $signal->id, 'title' => 'Greenfield opens a campus', 'source_url' => 'https://news.example.com/greenfield', 'event_date' => '2026-09-25', 'business_fit' => 'scholar',
            'source_published_at' => '2026-09-26', 'quote' => 'Greenfield will open a campus in Pune.', 'quote_kind' => 'excerpt',
        ], $detail['source_signal']);
    }

    public function test_a_pointer_that_is_not_this_organisations_to_show_shows_nothing(): void
    {
        $theirs = $this->product(self::T2, 'Their Product');
        $theirAccount = $this->identity(self::T2, 'Their Secret School');
        $theirSignal = $this->makeSignal(self::T2, ['title' => 'Their private signal']);
        $removed = $this->identity(self::T, 'Removed Identity');
        DB::table('g2g_org_accounts')->where('id', $removed)->update(['deleted_at' => now()]);
        $id = $this->lead(['product_master_id' => $theirs, 'org_account_id' => $theirAccount, 'source_company_opportunity_id' => $theirSignal->id]);

        $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');
        $this->assertNull($detail['product']);
        $this->assertNull($detail['organisation_account']);
        $this->assertNull($detail['source_signal'], 'another organisation\'s signal is not read through a stray pointer');
        $this->assertSame($theirSignal->id, $detail['source_signal_id'], 'the id itself is this lead\'s own column and says nothing of the other organisation');
        $this->assertStringNotContainsString('Their', json_encode($detail));

        DB::table('crm_leads')->where('id', $id)->update(['product_master_id' => null, 'org_account_id' => $removed]);
        $this->assertNull($this->getJson("/api/crm/leads/{$id}", $this->user['h'])->json('data.organisation_account'), 'a removed identity shows nothing');
    }

    public function test_a_shared_product_is_shown_to_every_organisation(): void
    {
        $shared = $this->product(0, 'Enterprise Brain', 'enterprise_brain');
        $this->lead(['product_master_id' => $shared]);

        $this->assertSame('Enterprise Brain', $this->getJson('/api/crm/leads', $this->user['h'])->json('data.items.0.product.name'));
    }

    public function test_the_detail_carries_the_consent_gates_answer_for_email_and_sms(): void
    {
        $open = $this->lead(['email' => 'open@school.example', 'mobile' => '+91 98765 43210']);
        $out = $this->lead(['email' => 'out@school.example', 'email_opt_out' => 1]);

        $a = $this->getJson("/api/crm/leads/{$open}", $this->user['h'])->assertOk()->json('data.consent');
        $b = $this->getJson("/api/crm/leads/{$out}", $this->user['h'])->assertOk()->json('data.consent');

        $this->assertSame(['allowed' => true, 'reason' => null], $a['email']);
        $this->assertFalse($a['sms']['allowed'], 'sms needs an opt-in first');
        $this->assertStringContainsString('opt-in', $a['sms']['reason']);
        $this->assertSame(['email', 'sms'], array_keys($a));
        $this->assertSame(['allowed', 'reason'], array_keys($a['email']));
        $this->assertFalse($b['email']['allowed']);
        $this->assertSame('Opted out of email (recorded on the record).', $b['email']['reason']);
    }

    public function test_the_detail_still_loads_when_the_consent_gate_cannot_answer(): void
    {
        $id = $this->lead();
        $this->app->bind(ConsentGate::class, fn () => throw new \RuntimeException('the gate is broken'));
        $this->withoutExceptionHandling();

        $detail = $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk()->json('data');

        $this->assertNull($detail['consent']);
        $this->assertSame((string) $id, $detail['id']);
    }

    public function test_a_host_without_the_new_columns_still_lists_and_shows_leads(): void
    {
        $id = $this->lead();
        (require base_path('database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php'))->down();
        Schema::drop('g2g_company_opportunities');

        $item = $this->getJson('/api/crm/leads', $this->user['h'])->assertOk()->json('data.items.0');
        $this->getJson("/api/crm/leads/{$id}", $this->user['h'])->assertOk();

        $this->assertNull($item['product']);
        $this->assertNull($item['organisation_account']);
        $this->getJson('/api/crm/leads?product_id=3', $this->user['h'])->assertStatus(422)->assertJsonPath('code', 'product_filter_unavailable');
        $this->assertCount(1, $this->ids('?product_id=0'), 'no product filter, no complaint');
    }

    public function test_the_list_reads_its_products_and_accounts_in_a_fixed_number_of_queries(): void
    {
        // Twelve DIFFERENT products and accounts: reading each one on its own would cost a query apiece, reading them together costs two for the page.
        $products = $accounts = [];
        for ($i = 0; $i < 12; $i++) {
            $products[] = $this->product(0, "Product {$i}");
            $accounts[] = $this->identity(self::T, "Account {$i}");
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/crm/leads?per_page=100', $this->user['h'])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        for ($i = 0; $i < 2; $i++) {
            $this->lead(['product_master_id' => $products[$i], 'org_account_id' => $accounts[$i]]);
        }
        $count();                                                          // the first call warms the rights cache; only the next ones are compared
        $few = $count();
        for ($i = 2; $i < 12; $i++) {
            $this->lead(['product_master_id' => $products[$i], 'org_account_id' => $accounts[$i]]);
        }
        for ($i = 0; $i < 30; $i++) {
            $this->lead(['product_master_id' => $products[$i % 12], 'org_account_id' => $accounts[$i % 12]]);
        }

        $this->assertSame($few, $count(), 'forty more leads over ten more products and accounts: the same number of queries');
    }

    // ── the filters ───────────────────────────────────────────────────────────

    public function test_the_product_filter_is_exact_and_zero_or_empty_means_every_product(): void
    {
        $one = $this->product(0, 'One');
        $two = $this->product(0, 'Two');
        $a = $this->lead(['product_master_id' => $one]);
        $b = $this->lead(['product_master_id' => $two]);
        $c = $this->lead();

        $this->assertSame([$a], $this->ids("?product_id={$one}"));
        $this->assertSame([$b], $this->ids("?product_id={$two}"));
        $this->assertEqualsCanonicalizing([$a, $b, $c], $this->ids('?product_id=0'));
        $this->assertEqualsCanonicalizing([$a, $b, $c], $this->ids('?product_id='));
        $this->assertEqualsCanonicalizing([$a, $b, $c], $this->ids('?product_id=abc'), 'not a number: no filter');
        $this->assertSame([], $this->ids('?product_id=999999'));
        $this->assertSame(1, $this->getJson("/api/crm/leads?product_id={$one}", $this->user['h'])->json('data.pagination.total'));
    }

    public function test_the_product_filter_never_shows_another_organisations_leads(): void
    {
        $shared = $this->product(0, 'Shared');
        $mine = $this->lead(['product_master_id' => $shared]);
        $this->lead(['product_master_id' => $shared], self::T2);

        $this->assertSame([$mine], $this->ids("?product_id={$shared}"));
        $this->assertSame([], $this->ids('?product_id=' . $this->product(self::T2, 'Theirs')), 'a product only the other organisation has finds nothing');
    }

    public function test_the_has_signal_filter_shows_only_leads_that_came_from_a_signal(): void
    {
        $from = $this->lead(['source_company_opportunity_id' => 41]);
        $this->lead();
        $this->lead(['source_company_opportunity_id' => 0]);
        $this->lead(['source_company_opportunity_id' => 42], self::T2);

        $this->assertSame([$from], $this->ids('?has_signal=1'));
        $this->assertCount(3, $this->ids('?has_signal=0'));
        $this->assertCount(3, $this->ids(''));
    }

    public function test_filters_combine_and_the_page_total_counts_the_filtered_leads(): void
    {
        $product = $this->product(0, 'One');
        for ($i = 0; $i < 5; $i++) {
            $this->lead(['product_master_id' => $product, 'source_company_opportunity_id' => 7, 'lead_status' => $i < 3 ? 'New' : 'Contacted']);
        }
        $this->lead(['product_master_id' => $product]);
        $this->lead(['source_company_opportunity_id' => 7]);

        $res = $this->getJson("/api/crm/leads?product_id={$product}&has_signal=1&lead_status=New&per_page=2&page=2", $this->user['h'])->assertOk();

        $this->assertSame(3, $res->json('data.pagination.total'));
        $this->assertSame(2, $res->json('data.pagination.last_page'));
        $this->assertCount(1, $res->json('data.items'));
    }

    public function test_the_consent_filter_splits_the_list_by_the_gates_answer_for_email(): void
    {
        $clean = $this->lead(['email' => 'clean@school.example']);
        $optOut = $this->lead(['email' => 'optout@school.example', 'email_opt_out' => 1]);
        $noAddress = $this->lead(['email' => null]);
        $suppressed = $this->lead(['email' => 'supp@school.example', 'consent_status' => 'suppressed']);

        $this->assertEqualsCanonicalizing([$clean], $this->ids('?consent=allowed'));
        $this->assertEqualsCanonicalizing([$optOut, $noAddress, $suppressed], $this->ids('?consent=blocked'));
        $this->assertCount(4, $this->ids('?consent='), 'empty: no filter');
        $this->assertCount(4, $this->ids('?consent=maybe'), 'not a value it knows: no filter');
        $res = $this->getJson('/api/crm/leads?consent=blocked&per_page=2', $this->user['h'])->assertOk();
        $this->assertSame(3, $res->json('data.pagination.total'), 'the page total counts the filtered leads');
        $this->assertCount(2, $res->json('data.items'));
    }

    /** Every way the gate says no to email, and the ways it says yes: the filter must agree with the gate, lead by lead. */
    public function test_the_consent_filter_agrees_with_the_gate_for_every_source(): void
    {
        $cases = [];
        $cases['clean'] = $this->lead(['email' => 'clean@school.example']);
        $cases['flag'] = $this->lead(['email' => 'flag@school.example', 'email_opt_out' => 1]);
        $cases['status opted_out'] = $this->lead(['email' => 'so@school.example', 'consent_status' => 'opted_out']);
        $cases['status suppressed'] = $this->lead(['email' => 'ss@school.example', 'consent_status' => 'Suppressed']);
        $cases['status opted_in'] = $this->lead(['email' => 'si@school.example', 'consent_status' => 'opted_in']);
        $cases['suppressed_at'] = $this->lead(['email' => 'sa@school.example', 'suppressed_at' => '2026-10-01 08:00:00']);
        $cases['blank address'] = $this->lead(['email' => '  ']);
        $cases['no address'] = $this->lead(['email' => null]);
        $cases['preference opted out'] = $this->lead(['email' => 'po@school.example']);
        $cases['preference opted in'] = $this->lead(['email' => 'pi@school.example']);
        $cases['sms preference only'] = $this->lead(['email' => 'smsout@school.example']);
        $cases['other organisation preference'] = $this->lead(['email' => 'otherpref@school.example']);
        $cases['null-organisation preference'] = $this->lead(['email' => 'nullpref@school.example']);
        $cases['suppressed address'] = $this->lead(['email' => 'Bounced@School.example']);
        $cases['suppressed domain'] = $this->lead(['email' => 'anyone@blocked-domain.example']);
        $cases['other organisation suppression'] = $this->lead(['email' => 'theirs@elsewhere.example']);
        $cases['outreach bounced'] = $this->lead(['email' => 'ob@school.example']);
        $cases['outreach unsubscribed'] = $this->lead(['email' => 'ou@school.example']);
        $cases['outreach active'] = $this->lead(['email' => 'oa@school.example']);
        $cases['outreach removed'] = $this->lead(['email' => 'or@school.example']);
        $cases['other organisation outreach'] = $this->lead(['email' => 'oo@school.example']);

        $pref = fn (int $lead, string $channel, string $status, ?int $tenant = self::T) => DB::table('crm_consent_preferences')->insert(['entity_type' => 'lead', 'entity_id' => $lead, 'channel' => $channel, 'status' => $status, 'sub_institute_id' => $tenant]);
        $pref($cases['preference opted out'], 'email', 'opted_out');
        $pref($cases['preference opted in'], 'email', 'opted_in');
        $pref($cases['sms preference only'], 'sms', 'opted_out');
        $pref($cases['other organisation preference'], 'email', 'opted_out', self::T2);
        $pref($cases['null-organisation preference'], 'email', 'opted_out', null);
        $suppress = fn (int $tenant, string $kind, string $value) => DB::table('gtm_suppressions')->insert(['sub_institute_id' => $tenant, 'kind' => $kind, 'value' => $value, 'reason' => 'bounced', 'created_at' => now()]);
        $suppress(self::T, 'email', 'bounced@school.example');
        $suppress(self::T, 'domain', 'blocked-domain.example');
        $suppress(self::T2, 'email', 'theirs@elsewhere.example');
        $outreach = fn (int $tenant, string $email, string $status, ?string $deleted = null) => DB::table('gtm_contacts')->insert(['sub_institute_id' => $tenant, 'account_id' => 1, 'full_name' => 'P', 'email' => $email, 'status' => $status, 'deleted_at' => $deleted, 'created_at' => now(), 'updated_at' => now()]);
        $outreach(self::T, 'ob@school.example', 'bounced');
        $outreach(self::T, 'OU@school.example', 'unsubscribed');
        $outreach(self::T, 'oa@school.example', 'active');
        $outreach(self::T, 'or@school.example', 'bounced', '2026-10-01 00:00:00');
        $outreach(self::T2, 'oo@school.example', 'do_not_contact');

        $gate = app(ConsentGate::class);
        $expectedBlocked = [];
        foreach ($cases as $label => $id) {
            if (! $gate->allows('lead', $id, 'email', self::T)['allowed']) {
                $expectedBlocked[$label] = $id;
            }
        }
        $blocked = LeadConsentFilter::blockedIds(DB::table('crm_leads')->where('sub_institute_id', self::T)->whereNull('deleted_at'), self::T);
        $byLabel = array_flip($cases);

        $this->assertEqualsCanonicalizing(array_values($expectedBlocked), $blocked, 'blocked by the filter but not by the gate: ' . json_encode(array_map(fn ($id) => $byLabel[$id], array_diff($blocked, $expectedBlocked))) . '; blocked by the gate but not by the filter: ' . json_encode(array_map(fn ($id) => $byLabel[$id], array_diff($expectedBlocked, $blocked))));
        $this->assertGreaterThan(10, count($expectedBlocked), 'the matrix really exercises the blocking sources');
        $this->assertGreaterThan(5, count($cases) - count($expectedBlocked), 'and the allowing ones');
        $this->assertEqualsCanonicalizing(array_values($expectedBlocked), $this->ids('?consent=blocked&per_page=100'));
        $this->assertEqualsCanonicalizing(array_values(array_diff($cases, $expectedBlocked)), $this->ids('?consent=allowed&per_page=100'));
    }

    public function test_the_consent_filter_never_reads_another_organisations_rows(): void
    {
        $mine = $this->lead(['email' => 'mine@school.example']);
        $theirs = $this->lead(['email' => 'theirs@school.example', 'email_opt_out' => 1], self::T2);
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T2, 'kind' => 'domain', 'value' => 'school.example', 'reason' => 'bounced', 'created_at' => now()]);

        $this->assertSame([$mine], $this->ids('?consent=allowed'), 'the other organisation\'s suppression of the same domain does not block mine');
        $this->assertSame([], $this->ids('?consent=blocked'));
        $this->assertNotContains($theirs, $this->ids('?consent=blocked'));
    }

    public function test_the_consent_filter_works_with_the_other_filters_and_leaves_converted_leads_out(): void
    {
        $product = $this->product(0, 'One');
        $ok = $this->lead(['email' => 'ok@school.example', 'product_master_id' => $product]);
        $this->lead(['email' => 'no@school.example', 'product_master_id' => $product, 'email_opt_out' => 1]);
        $this->lead(['email' => 'other@school.example']);
        $this->lead(['email' => 'conv@school.example', 'product_master_id' => $product, 'converted' => 1]);

        $this->assertSame([$ok], $this->ids("?consent=allowed&product_id={$product}"));
        $this->assertCount(1, $this->ids("?consent=blocked&product_id={$product}"));
    }
}
