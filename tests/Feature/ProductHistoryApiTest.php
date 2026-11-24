<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * `GET /api/product-master/{id}/history`: one list of the audit lines of the three stores of a record, newest first (slice 15, P2-E1, brief section 4; audit finding G-2).
 *
 * The stores and the lines the one-record writer files (ProductWriteAudit): the Portfolio offer (`portfolio.offer.*` under g2g_product_offers, the entity id is the master row's
 * offer_row_id), the CRM catalogue row (`crm.product.*` under crm_products, its crm_product_id) and the master row (`product.master.*` under g2g_product_master). The lines are
 * rows of g2g_audit_log, the projection every audit line lands in. The rule for who sees what: About and record lines are open to every signed-in user of the organisation;
 * the Sell store (a catalogue row's history carries price, cost and commission) and any line that touches a price field are for a caller who may view the Sell side of the
 * record's type only. Every line is the caller's own organisation's.
 */
class ProductHistoryApiTest extends TestCase
{
    use BuildsProductReadWorld;

    /** @var array{master: int, offer: int, crm: int} */
    private array $rec;

    private int $eventSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        $w = $this->scholarWorld();
        $sub = $this->subProduct('SCH-09', 'Scholar Nine');
        $crm = (int) DB::table('crm_products')->insertGetId([
            'sub_institute_id' => self::T, 'product_no' => 'PRD-NINE', 'item_type' => 'product', 'name' => 'Scholar Nine', 'unit_price' => 999, 'is_active' => 1, 'product_master_id' => $w['product'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('g2g_product_master')->where('id', $sub['master'])->update(['crm_product_id' => $crm]);
        $this->rec = ['master' => $sub['master'], 'offer' => $sub['offer'], 'crm' => $crm];
        DB::table('tbluser')->insert([
            ['id' => 501, 'user_name' => 'asha', 'first_name' => 'Asha', 'last_name' => 'Rao', 'sub_institute_id' => self::T, 'user_profile_id' => 1],
            ['id' => 502, 'user_name' => 'nameless', 'first_name' => null, 'last_name' => null, 'sub_institute_id' => self::T, 'user_profile_id' => 1],
        ]);
    }

    /** One line of the audit projection, the way the event store projects it. @param list<array{field: string, oldValue: mixed, newValue: mixed}> $changes */
    private function line(string $type, string $entityType, int $entityId, array $changes, string $at, ?int $actor = 501, int $tenant = self::T): int
    {
        DB::table('g2g_audit_log')->insert([
            'event_id' => ++$this->eventSeq, 'sub_institute_id' => $tenant, 'type' => $type, 'entity_type' => $entityType, 'entity_id' => $entityId, 'actor_id' => $actor,
            'detail' => json_encode(['changes' => $changes]), 'occurred_at' => $at,
        ]);

        return $this->eventSeq;
    }

    private function change(string $field, mixed $old, mixed $new): array
    {
        return ['field' => $field, 'oldValue' => $old, 'newValue' => $new];
    }

    /** Four lines, one in each of three stores and a created line, at known times. */
    private function seedLines(): void
    {
        $this->line('portfolio.offer.updated', 'g2g_product_offers', $this->rec['offer'], [$this->change('what_it_does', 'Old text', 'New text')], '2026-11-10 09:00:00.000');
        $this->line('crm.product.updated', 'crm_products', $this->rec['crm'], [$this->change('unit_price', '900.00', '999.00'), $this->change('cost_price', '500.00', '600.00')], '2026-11-11 09:00:00.000');
        $this->line('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('name', 'Scholar Eight', 'Scholar Nine')], '2026-11-12 09:00:00.000', 502);
        $this->line('crm.product.created', 'crm_products', $this->rec['crm'], [$this->change('name', null, 'Scholar Nine')], '2026-11-09 09:00:00.000', null);
    }

    private function history(int $profile = self::ADMIN, string $query = '', ?int $id = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/product-master/' . ($id ?? $this->rec['master']) . '/history' . ($query !== '' ? '?' . $query : ''), $this->as($profile));
    }

    // ================================================================== the three stores in one list

    public function test_the_three_stores_come_in_one_list_newest_first_with_who_what_and_the_changes(): void
    {
        $this->seedLines();

        $res = $this->history()->assertOk();
        $rows = $res->json('data');

        $this->assertSame(1, $res->json('status'));
        $this->assertSame(['record', 'sell', 'about', 'sell'], array_column($rows, 'store'), 'newest first, one list for three stores');
        $this->assertSame(['updated', 'updated', 'updated', 'created'], array_column($rows, 'event'));
        $this->assertSame(['product.master.updated', 'crm.product.updated', 'portfolio.offer.updated', 'crm.product.created'], array_column($rows, 'type'));
        $this->assertSame(['2026-11-12 09:00:00.000', '2026-11-11 09:00:00.000', '2026-11-10 09:00:00.000', '2026-11-09 09:00:00.000'], array_column($rows, 'at'));
        $this->assertSame([['field' => 'name', 'old' => 'Scholar Eight', 'new' => 'Scholar Nine']], $rows[0]['changes']);
        $this->assertSame([['field' => 'unit_price', 'old' => '900.00', 'new' => '999.00'], ['field' => 'cost_price', 'old' => '500.00', 'new' => '600.00']], $rows[1]['changes']);
        $this->assertSame([['field' => 'what_it_does', 'old' => 'Old text', 'new' => 'New text']], $rows[2]['changes']);
        $this->assertSame([['field' => 'name', 'old' => null, 'new' => 'Scholar Nine']], $rows[3]['changes']);
        $this->assertSame(['meta' => ['total' => 4, 'page' => 1, 'perPage' => 20, 'lastPage' => 1]], ['meta' => array_intersect_key($res->json('meta'), array_flip(['total', 'page', 'perPage', 'lastPage']))]);
    }

    public function test_the_person_is_named_by_name_then_login_and_is_null_for_the_system(): void
    {
        $this->seedLines();
        $rows = $this->history()->json('data');

        $this->assertSame(['nameless', 'Asha Rao', 'Asha Rao', null], array_column($rows, 'by'), 'first and last name; the login when there is no name; null for the system');
        $this->assertSame([502, 501, 501, null], array_column($rows, 'byId'));
    }

    public function test_only_the_lines_of_this_record_in_this_organisation_are_shown(): void
    {
        $this->seedLines();
        $other = $this->subProduct('SCH-10', 'Scholar Ten');
        $this->line('portfolio.offer.updated', 'g2g_product_offers', $other['offer'], [$this->change('what_it_does', 'a', 'b')], '2026-11-13 09:00:00.000');            // another record
        $this->line('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('name', 'x', 'y')], '2026-11-13 10:00:00.000', 501, self::T2);   // same ids, another organisation
        $this->line('product.master.updated', 'crm_leads', $this->rec['master'], [$this->change('name', 'x', 'y')], '2026-11-13 11:00:00.000');                            // same id, another entity
        $this->line('product.merged', 'g2g_product_master', $this->rec['master'], [], '2026-11-13 12:00:00.000');                                                        // another family of event

        $this->assertSame(4, $this->history()->assertOk()->json('meta.total'));
        $this->history(self::ADMIN2, '', $other['master'])->assertStatus(404);
        $this->assertSame(1, $this->history(self::ADMIN, '', $other['master'])->json('meta.total'));
    }

    // ================================================================== who sees the Sell lines (G5, G6)

    public function test_hr_sees_the_about_and_the_record_lines_and_never_a_sell_line(): void
    {
        $this->seedLines();

        $res = $this->history(self::HR)->assertOk();

        $this->assertSame(['record', 'about'], array_column($res->json('data'), 'store'), 'no line of the sell store: not the update, not the creation');
        $this->assertSame(2, $res->json('meta.total'), 'the total counts what HR may see, so a page is never short');
        foreach (['unit_price', 'cost_price', 'commission_rate', '999', '600', '900'] as $secret) {
            $this->assertFalse(str_contains((string) $res->getContent(), $secret), "{$secret} must not be in what HR receives");
        }
        $this->assertSame(['record', 'about'], array_column($this->history(self::NOBODY)->json('data'), 'store'));
    }

    public function test_a_line_of_any_store_that_touches_a_price_field_is_hidden_from_a_caller_who_may_not_view_sell(): void
    {
        $this->seedLines();
        $this->line('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('commission_rate', '5', '9')], '2026-11-13 09:00:00.000');
        $this->line('portfolio.offer.updated', 'g2g_product_offers', $this->rec['offer'], [$this->change('what_it_does', 'a', 'b'), $this->change('unit_price', '1', '2')], '2026-11-13 10:00:00.000');
        $this->line('portfolio.offer.updated', 'g2g_product_offers', $this->rec['offer'], [$this->change('cost_price', '1', '2')], '2026-11-13 11:00:00.000');
        $this->line('portfolio.offer.updated', 'g2g_product_offers', $this->rec['offer'], [$this->change('notes', 'a', 'b')], '2026-11-13 12:00:00.000');   // an About line with no price in it

        $hr = $this->history(self::HR)->json('data');
        $this->assertSame(['about', 'record', 'about'], array_column($hr, 'store'), 'the plain About line and the two older lines; the three that touch a price are gone');
        $this->assertSame([['field' => 'notes', 'old' => 'a', 'new' => 'b']], $hr[0]['changes']);
        $this->assertSame(8, $this->history(self::ADMIN)->json('meta.total'), 'a Sell viewer sees all 8');
        $this->assertSame(3, $this->history(self::HR)->json('meta.total'));
    }

    public function test_the_sell_lines_follow_the_type_of_the_record(): void
    {
        $this->seedLines();
        $service = $this->items($this->scholarServiceIds());
        $this->line('crm.product.updated', 'crm_products', $service['crm'], [$this->change('unit_price', '1', '2')], '2026-11-13 09:00:00.000');

        $this->assertSame(4, $this->history(self::PRODUCTS_VIEWER)->json('meta.total'), 'a Products viewer reads the history of a product');
        $this->assertSame(2, $this->history(self::SERVICES_VIEWER)->json('meta.total'), 'a Services viewer does not see a product\'s Sell lines');
        $this->assertSame(4, $this->history(self::SALES)->json('meta.total'));
        $this->assertSame(1, $this->history(self::SERVICES_VIEWER, '', $service['master'])->json('meta.total'), 'a Services viewer reads a service\'s history');
        $this->assertSame(0, $this->history(self::PRODUCTS_VIEWER, '', $service['master'])->json('meta.total'), 'a Products viewer does not');
        $this->assertSame(0, $this->history(self::HR, '', $service['master'])->json('meta.total'));
    }

    /** @return array{master: int, crm: int} the Implementation service of the scholar world */
    private function items(int $crm): array
    {
        return ['crm' => $crm, 'master' => (int) DB::table('g2g_product_master')->where('crm_product_id', $crm)->value('id')];
    }

    private function scholarServiceIds(): int
    {
        return (int) DB::table('crm_products')->where('sku', 'SCH-SRV-IMP')->value('id');
    }

    // ================================================================== paging and the lines of a record with fewer stores

    public function test_the_history_is_paged_newest_first_and_per_is_held_to_5_to_100(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->line('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('name', "n{$i}", 'n' . ($i + 1))], sprintf('2026-11-01 %02d:00:00.000', $i));
        }

        $first = $this->history(self::ADMIN, 'per=5&page=1');
        $this->assertSame([12, 1, 5, 3], [$first->json('meta.total'), $first->json('meta.page'), $first->json('meta.perPage'), $first->json('meta.lastPage')]);
        $this->assertSame(['n12', 'n11', 'n10', 'n9', 'n8'], array_map(fn ($r) => $r['changes'][0]['old'], $first->json('data')));
        $this->assertSame(['n7', 'n6', 'n5', 'n4', 'n3'], array_map(fn ($r) => $r['changes'][0]['old'], $this->history(self::ADMIN, 'per=5&page=2')->json('data')));
        $this->assertCount(2, $this->history(self::ADMIN, 'per=5&page=3')->json('data'));
        $this->assertSame([], $this->history(self::ADMIN, 'per=5&page=4')->json('data'));
        $this->assertSame(100, $this->history(self::ADMIN, 'per=900')->json('meta.perPage'));
        $this->assertSame(5, $this->history(self::ADMIN, 'per=1')->json('meta.perPage'));
        $this->assertSame(20, $this->history(self::ADMIN)->json('meta.perPage'));
        $this->assertCount(12, $this->history(self::ADMIN, 'per=900')->json('data'));
    }

    public function test_lines_of_one_instant_keep_a_stable_order_and_a_record_with_only_a_master_row_has_only_those_lines(): void
    {
        $item = $this->item('Only an item');
        $this->line('product.master.created', 'g2g_product_master', $item['master'], [$this->change('name', null, 'Only an item')], '2026-11-01 08:00:00.000');
        $this->line('product.master.updated', 'g2g_product_master', $item['master'], [$this->change('name', 'a', 'b')], '2026-11-01 08:00:00.000');

        $rows = $this->history(self::ADMIN, '', $item['master'])->json('data');
        $this->assertSame(['updated', 'created'], array_column($rows, 'event'), 'same time: the later line first');
        $this->assertSame(['record', 'record'], array_column($rows, 'store'));
        $this->assertSame([], $this->history(self::ADMIN, '', $this->sharedId('scholarclone'))->json('data'), 'a Product with no lines');
    }

    public function test_the_history_needs_a_token_and_a_record_of_the_organisation(): void
    {
        $this->seedLines();

        $this->getJson('/api/product-master/' . $this->rec['master'] . '/history')->assertStatus(401);
        $this->history(self::ADMIN, '', 999999)->assertStatus(404);
        $this->history(self::ADMIN2)->assertStatus(404);
    }

    // ================================================================== what the writer really files

    public function test_what_the_one_record_writer_files_for_an_update_is_found_in_the_right_stores(): void
    {
        $admin = $this->h(self::T, self::ADMIN);
        DB::table('tbluser')->where('id', $admin['_user'])->update(['first_name' => 'Wanda', 'last_name' => 'Writer']);
        $this->events = [];

        $this->putJson('/api/product-master/' . $this->rec['master'], ['name' => 'Scholar Nine Plus', 'about' => ['whatItDoes' => 'Does more'], 'sell' => ['unitPrice' => 1200, 'costPrice' => 700]], $this->headers($admin))->assertOk();

        $this->assertNotSame([], $this->events, 'the writer recorded audit lines');
        $at = 0;
        foreach ($this->events as $event) {
            $this->line($event['type'], $event['entityType'], (int) $event['entityId'], $event['payload']['changes'], sprintf('2026-11-14 10:00:%02d.000', $at++), $event['actor']);
        }
        $stores = array_column($this->history(self::ADMIN)->assertOk()->json('data'), 'store');
        $this->assertEqualsCanonicalizing(['about', 'sell', 'record'], $stores, 'the writer files one line per store it changed, and the history reads all three');
        $sell = collect($this->history(self::ADMIN)->json('data'))->firstWhere('store', 'sell');
        $this->assertContains('unit_price', array_column($sell['changes'], 'field'));
        $this->assertSame('Wanda Writer', $sell['by']);

        $hr = $this->history(self::HR)->json('data');
        $this->assertEqualsCanonicalizing(['about', 'record'], array_column($hr, 'store'), 'HR reads the About and the record lines of the same write, not the Sell one');
        $this->assertFalse(str_contains(json_encode($hr), 'unit_price'));
        $this->assertFalse(str_contains(json_encode($hr), '1200'));
    }

    // ================================================================== the event store is the source (24 Nov 2026)

    /** One event of the event store, the way the writer records it. @param list<array{field: string, oldValue: mixed, newValue: mixed}> $changes */
    private function event(string $type, string $entityType, int $entityId, array $changes, string $at, ?int $actor = 501): void
    {
        DB::table('g2g_event')->insert([
            'event_uuid' => (string) Str::uuid(), 'type' => $type, 'sub_institute_id' => self::T, 'entity_type' => $entityType, 'entity_id' => $entityId, 'actor_id' => $actor,
            'payload' => json_encode(['changes' => $changes]), 'occurred_at' => $at, 'recorded_at' => $at,
        ]);
    }

    private function withEventStore(): void
    {
        if (! Schema::hasTable('g2g_event')) {
            (require base_path('database/migrations/2026_08_10_140000_create_g2g_event_store.php'))->up();
        }
    }

    public function test_the_history_is_read_from_the_event_store_so_a_scheduler_that_has_not_run_does_not_empty_it(): void
    {
        // Found by the real-browser check of 24 Nov 2026: a record edited, archived and restored showed NO history on dev, because the History read the audit projection
        // that `events:project` fills on a schedule (dev: last run 21 Oct) while the eight events of the record were already recorded.
        $this->withEventStore();
        $this->assertSame(0, DB::table('g2g_audit_log')->count(), 'the projection holds nothing: the scheduler has not run');
        $this->event('product.master.created', 'g2g_product_master', $this->rec['master'], [$this->change('name', null, 'Scholar Nine')], '2026-11-20 09:00:00.000');
        $this->event('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('name', 'Scholar Nine', 'Scholar Nine v2')], '2026-11-20 09:05:00.000');
        $this->event('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('status', 'active', 'archived')], '2026-11-20 09:10:00.000', 502);
        $this->event('portfolio.offer.updated', 'g2g_product_offers', $this->rec['offer'], [$this->change('what_it_does', 'Old text', 'New text')], '2026-11-20 09:06:00.000');

        $rows = $this->history()->assertOk()->json('data');

        $this->assertCount(4, $rows, 'every recorded event of the record is a line');
        $this->assertSame(['archived', 'New text', 'Scholar Nine v2', 'Scholar Nine'], array_map(fn ($r) => $r['changes'][0]['new'], [$rows[0], $rows[1], $rows[2], $rows[3]]), 'newest first, across the stores');
        $this->assertSame('Asha Rao', $rows[3]['by'], 'the person is named');
        $this->assertSame(['record', 'about', 'record', 'record'], array_column($rows, 'store'));
        $this->assertSame(4, $this->history()->json('meta.total'));
    }

    public function test_a_move_of_a_sub_product_to_another_product_is_a_line_of_its_history(): void
    {
        // Track B of the S15 follow-up (24 Nov 2026) records `product.moved` against the master row; the History read keeps only the prefixes it knows, and this one was missing.
        $this->withEventStore();
        $this->event('product.moved', 'g2g_product_master', $this->rec['master'], [$this->change('product', 'ScholarClone', 'Enterprise Brain')], '2026-11-24 09:00:00.000');
        $this->event('product.removed', 'g2g_product_master', $this->rec['master'], [], '2026-11-24 09:01:00.000');

        $rows = $this->history()->assertOk()->json('data');

        $this->assertCount(1, $rows, 'the move is a line; a removal is not a line of a record that is still there');
        $this->assertSame('moved', $rows[0]['event']);
        $this->assertSame(['ScholarClone', 'Enterprise Brain'], [$rows[0]['changes'][0]['old'], $rows[0]['changes'][0]['new']]);
        $this->assertSame('record', $rows[0]['store']);
    }

    public function test_the_event_store_lines_keep_the_same_rules_about_prices_and_organisations(): void
    {
        $this->withEventStore();
        $this->event('crm.product.updated', 'crm_products', $this->rec['crm'], [$this->change('unit_price', '900.00', '999.00')], '2026-11-20 10:00:00.000');
        $this->event('product.master.updated', 'g2g_product_master', $this->rec['master'], [$this->change('name', 'Scholar Eight', 'Scholar Nine')], '2026-11-20 10:01:00.000');
        DB::table('g2g_event')->insert([
            'event_uuid' => (string) Str::uuid(), 'type' => 'product.master.updated', 'sub_institute_id' => self::T2, 'entity_type' => 'g2g_product_master', 'entity_id' => $this->rec['master'], 'actor_id' => 501,
            'payload' => json_encode(['changes' => [$this->change('name', 'x', 'Another organisation')]]), 'occurred_at' => '2026-11-20 10:02:00.000', 'recorded_at' => '2026-11-20 10:02:00.000',
        ]);

        $admin = $this->history(self::ADMIN)->assertOk()->json('data');
        $this->assertSame(2, count($admin), 'an administrator reads the price line and the name line, and nothing of another organisation');
        $hr = $this->history(self::HR)->assertOk()->json('data');
        $this->assertCount(1, $hr, 'HR reads the name line only');
        $this->assertFalse(str_contains(json_encode($hr), 'unit_price'));
        $this->assertFalse(str_contains(json_encode($hr), '999'));
    }

    public function test_a_host_without_the_event_store_keeps_reading_the_projection(): void
    {
        $this->assertFalse(Schema::hasTable('g2g_event'), 'this world has no event store');
        $this->seedLines();

        $this->assertNotSame([], $this->history()->assertOk()->json('data'));
    }
}
