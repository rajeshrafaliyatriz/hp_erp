<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductLines;
use App\Domain\ProductMaster\ProductMaster;
use App\Domain\Signals\Products\CustomProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * One door for creating a Product (slice 15, track WPA1, item 1; audit decision D-5, chosen by the owner on 23 Nov 2026).
 *
 * `POST /api/signals/icp/products` used to write the custom row through ProductCatalog::createCustom and then rely on ProductMasterSync::throughCustomProduct to make the
 * master row: two writers of one Product. It now goes through ProductLines::create (the one place a Product is written: both rows, in one transaction), which carries the
 * four fields main stores (description, category, logo, status) into the custom row. The answers stay exactly as they were. The old guarded path (the custom row, then the
 * sync) is used ONLY where the master table does not exist or the master write fails with a database error ("a failing master write never changes the answer").
 */
class ProductOneDoorTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    private function create(array $body, ?array $h = null)
    {
        return $this->postJson('/api/signals/icp/products', $body, $this->headers($h ?? $this->h()));
    }

    /** @return array{user:object, user_id:int, sub_institute_id:int} */
    private function identity(int $tenant = self::T, int $profile = 1): array
    {
        $h = $this->h($tenant, $profile);

        return ['user' => (object) ['user_profile_id' => $profile], 'user_id' => (int) $h['_user'], 'sub_institute_id' => $tenant];
    }

    private function customRows(string $key, int $tenant = self::T): array
    {
        return DB::table('g2g_signal_products')->where('sub_institute_id', $tenant)->where('key', $key)->get()->all();
    }

    private function masterRows(string $key, int $tenant = self::T): array
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('product_key', $key)->get()->all();
    }

    private function breakTheMaster(): void
    {
        DB::unprepared("CREATE TRIGGER pm_no_insert BEFORE INSERT ON g2g_product_master BEGIN SELECT RAISE(ABORT, 'the master is broken'); END");
    }

    // ---------------------------------------------------------------- one create, two rows

    public function test_one_create_writes_one_custom_row_and_one_master_row_that_agree_and_carry_the_four_fields(): void
    {
        $h = $this->h();
        $res = $this->create(['key' => 'payroll_cloud', 'label' => 'Payroll Cloud', 'description' => '  Pays people  ', 'category' => 'HR tech', 'logo' => 'logos/pc.png', 'status' => 'active'], $h)
            ->assertCreated();

        // The answer is the shape it always was.
        $res->assertJsonPath('status', 1)->assertJsonPath('message', 'Product created.')
            ->assertJsonPath('data.product.key', 'payroll_cloud')->assertJsonPath('data.product.label', 'Payroll Cloud')->assertJsonPath('data.product.custom', true)
            ->assertJsonPath('data.product.status', 'active')->assertJsonPath('data.product.description', 'Pays people')
            ->assertJsonPath('data.product.category', 'HR tech')->assertJsonPath('data.product.logo', 'logos/pc.png');

        $custom = $this->customRows('payroll_cloud');
        $master = $this->masterRows('payroll_cloud');
        $this->assertCount(1, $custom, 'one custom row');
        $this->assertCount(1, $master, 'and one master row');
        $this->assertSame('Payroll Cloud', $custom[0]->label);
        $this->assertSame($custom[0]->label, $master[0]->name, 'the same label');
        $this->assertSame($custom[0]->key, $master[0]->product_key, 'the same key');
        $this->assertSame((int) $h['_user'], (int) $custom[0]->created_by, 'the creator, custom row');
        $this->assertSame((int) $h['_user'], (int) $master[0]->created_by, 'the creator, master row');
        $this->assertSame('active', $custom[0]->status);
        $this->assertSame('active', $master[0]->status);
        $this->assertSame(ProductMaster::KIND_PRODUCT, $master[0]->kind);
        $this->assertSame('custom', $master[0]->origin);
        $this->assertSame('payroll_cloud', $master[0]->signal_key);

        // The four fields main stores are in the custom row exactly where main keeps them (the __meta__ entry of portfolio_names; the label stays the first name).
        $names = json_decode((string) $custom[0]->portfolio_names, true);
        $this->assertSame('Payroll Cloud', $names[0]);
        $this->assertSame(['description' => 'Pays people', 'category' => 'HR tech', 'logo' => 'logos/pc.png'], $names['__meta__']);
    }

    public function test_without_the_optional_fields_the_answer_has_nulls_and_the_product_is_active_as_before(): void
    {
        $this->create(['key' => 'plain_one', 'label' => 'Plain One'])->assertCreated()
            ->assertJsonPath('data.product.status', 'active')->assertJsonPath('data.product.description', null)
            ->assertJsonPath('data.product.category', null)->assertJsonPath('data.product.logo', null);

        $this->assertCount(1, $this->customRows('plain_one'));
        $this->assertCount(1, $this->masterRows('plain_one'));
    }

    public function test_both_rows_are_written_by_ProductLines_create_in_one_transaction(): void
    {
        // The write-through (ProductMasterSync) writes with the query builder, so it never fires a model event; ProductLines::create writes both rows with the models, inside
        // one DB::transaction. Seeing both events at the same, deeper transaction level is the proof that this door was used and that it is one transaction.
        $base = DB::transactionLevel();
        $seen = [];
        CustomProduct::creating(function () use (&$seen) {
            $seen[] = ['custom', DB::transactionLevel()];
        });
        ProductMaster::creating(function () use (&$seen) {
            $seen[] = ['master', DB::transactionLevel()];
        });

        $this->create(['key' => 'door_one', 'label' => 'Door One'])->assertCreated();

        $this->assertSame([['custom', $base + 1], ['master', $base + 1]], $seen, 'the custom row, then the master row, in the same transaction');
    }

    public function test_the_master_row_belongs_to_the_callers_organisation_only(): void
    {
        $this->create(['key' => 'mine_only', 'label' => 'Mine Only'], $this->h(self::T, 1))->assertCreated();
        $this->create(['key' => 'mine_only', 'label' => 'Mine Only'], $this->h(self::T2, 3))->assertCreated();   // the same key is free in another organisation

        $this->assertCount(1, $this->masterRows('mine_only', self::T));
        $this->assertCount(1, $this->masterRows('mine_only', self::T2));
        $this->assertCount(1, $this->customRows('mine_only', self::T2));
    }

    public function test_a_person_who_is_not_an_administrator_is_refused_and_nothing_is_written(): void
    {
        $this->create(['key' => 'not_mine', 'label' => 'Not Mine'], $this->h(self::T, 2))->assertStatus(403);

        $this->assertSame([], $this->customRows('not_mine'));
        $this->assertSame([], $this->masterRows('not_mine'));
    }

    public function test_if_the_route_gate_were_ever_lifted_the_door_itself_still_refuses_a_non_administrator_with_a_403(): void
    {
        // The route group asks for the administrator profile and so does ProductLines::create: two locks on one door. With the first one taken off, the second holds and its refusal
        // is a JSON 403 (not a 500), and nothing is written.
        $this->withoutMiddleware(\App\Http\Middleware\RequireProfile::class);

        $res = $this->create(['key' => 'second_lock', 'label' => 'Second Lock'], $this->h(self::T, 2))->assertStatus(403);

        $res->assertJsonPath('status', 0)->assertJsonPath('message', 'Only an administrator can create a product.');
        $this->assertSame([], $this->customRows('second_lock'));
        $this->assertSame([], $this->masterRows('second_lock'));
    }

    // ---------------------------------------------------------------- the status field

    public function test_an_inactive_product_is_stored_inactive_with_an_archived_master_row_and_comes_back_when_it_is_activated(): void
    {
        $this->create(['key' => 'draft_one', 'label' => 'Draft One', 'status' => 'inactive'])->assertCreated()->assertJsonPath('data.product.status', 'inactive');

        $this->assertSame('inactive', $this->customRows('draft_one')[0]->status);
        $this->assertSame(ProductMaster::STATUS_ARCHIVED, $this->masterRows('draft_one')[0]->status, 'what ProductMasterSync::customProduct does for a source that is not active');

        $this->putJson('/api/signals/icp/products/draft_one', ['status' => 'active'], $this->headers($this->h()))->assertOk();

        $this->assertSame('active', $this->customRows('draft_one')[0]->status);
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $this->masterRows('draft_one')[0]->status, 'activating the product brings its master row back');
    }

    public function test_a_status_that_is_neither_active_nor_inactive_is_refused_by_the_validation_as_before(): void
    {
        $this->create(['key' => 'odd_one', 'label' => 'Odd One', 'status' => 'deleted'])->assertStatus(422)->assertJsonPath('message', 'Validation failed');

        $this->assertSame([], $this->customRows('odd_one'));
    }

    // ---------------------------------------------------------------- the refusals keep their words

    /** @return list<array{0: array<string, string>, 1: ?array<string, string>}> a body, and (optionally) a body made first so the second is a duplicate */
    public static function refusedBodies(): array
    {
        return [
            'a built-in key' => [['key' => 'g2g', 'label' => 'Some product'], null],
            'a reserved word' => [['key' => 'All', 'label' => 'Some product'], null],
            'a built-in name' => [['key' => 'fresh_one', 'label' => 'ScholarClone'], null],
            'a key and a name that are both wrong' => [['key' => 'g2g', 'label' => 'ScholarClone'], null],
            'a key the organisation has already' => [['key' => 'dupe_one', 'label' => 'Dupe Two'], ['key' => 'dupe_one', 'label' => 'Dupe One']],
            'a name the organisation has already' => [['key' => 'other_one', 'label' => 'Dupe One'], ['key' => 'dupe_one', 'label' => 'Dupe One']],
            'a name that is one letter' => [['key' => 'short_one', 'label' => 'x'], null],
        ];
    }

    #[DataProvider('refusedBodies')]
    public function test_a_refusal_has_exactly_the_shape_the_old_path_gave(array $body, ?array $first): void
    {
        if ($first !== null) {
            $this->create($first)->assertCreated();
        }
        $before = [DB::table('g2g_signal_products')->count(), DB::table('g2g_product_master')->count()];

        $now = $this->create($body);
        $now->assertStatus(422)->assertJsonPath('status', 0)->assertJsonPath('code', 'product_invalid')->assertJsonPath('message', 'This product cannot be created');
        $this->assertNotSame([], $now->json('problems'));
        $this->assertSame($before, [DB::table('g2g_signal_products')->count(), DB::table('g2g_product_master')->count()], 'a refusal writes nothing');

        // The same request on a host with no master table goes the old way (ProductCatalog::createCustom); its answer must be the same, byte for byte.
        Schema::drop('g2g_product_master');
        $old = $this->create($body);
        $old->assertStatus(422);

        $this->assertSame($old->json(), $now->json(), 'the answer of the one door equals the answer of the old path');
    }

    public function test_an_invalid_key_is_repaired_the_same_way_as_before_for_the_one_door(): void
    {
        // keyFrom() turns a short or loosely spelled key into a valid one; the door gets the repaired key.
        $res = $this->create(['key' => 'P3', 'label' => 'Third product'])->assertCreated();

        $key = $res->json('data.product.key');
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{2,19}$/', $key);
        $this->assertCount(1, $this->customRows($key));
        $this->assertCount(1, $this->masterRows($key));
    }

    // ---------------------------------------------------------------- where the old guarded path is still used

    public function test_a_failing_master_write_never_changes_the_answer_and_leaves_no_half_product(): void
    {
        $this->breakTheMaster();
        Log::spy();

        $res = $this->create(['key' => 'late_one', 'label' => 'Late One', 'description' => 'kept', 'status' => 'active'])->assertCreated();

        $res->assertJsonPath('message', 'Product created.')->assertJsonPath('data.product.label', 'Late One')->assertJsonPath('data.product.description', 'kept');
        $this->assertCount(1, $this->customRows('late_one'), 'the custom row exists once (the failed attempt of the door was rolled back)');
        $this->assertSame([], $this->masterRows('late_one'), 'the master could not follow');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_starts_with((string) $message, 'Product Master could not follow'))->times(1);
    }

    public function test_a_key_that_only_the_master_holds_is_answered_as_it_always_was_with_a_created_product(): void
    {
        // The door finds the key taken (409) only because of a master row with no custom row behind it. The old path never refused that: it made the custom row and the write-through
        // took the master row over. The answer is that of the old path, one custom row and one master row, and nothing is logged.
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'orphan_one', 'kind' => 'product', 'name' => 'Old name', 'status' => 'active', 'origin' => 'manual']);
        Log::spy();

        $this->create(['key' => 'orphan_one', 'label' => 'Orphan One'])->assertCreated()->assertJsonPath('data.product.label', 'Orphan One');

        $this->assertCount(1, $this->customRows('orphan_one'));
        $this->assertCount(1, $this->masterRows('orphan_one'));
        $this->assertSame('Orphan One', $this->masterRows('orphan_one')[0]->name, 'the write-through took the row over');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_without_a_master_table_the_old_path_still_creates_the_product(): void
    {
        Schema::drop('g2g_product_master');

        $this->create(['key' => 'no_master', 'label' => 'No Master', 'description' => 'kept', 'category' => 'c', 'logo' => 'l', 'status' => 'inactive'])->assertCreated()
            ->assertJsonPath('data.product.status', 'inactive')->assertJsonPath('data.product.description', 'kept');

        $row = $this->customRows('no_master')[0];
        $this->assertSame('inactive', $row->status);
        $this->assertSame(['description' => 'kept', 'category' => 'c', 'logo' => 'l'], json_decode((string) $row->portfolio_names, true)['__meta__']);
    }

    // ---------------------------------------------------------------- ProductLines::create itself

    public function test_create_without_meta_stores_exactly_what_the_writer_always_stored(): void
    {
        // ProductWriter::createProduct (POST /api/product-master, kind product) calls create() with no meta: its row must not change.
        $row = ProductLines::create($this->identity(), 'writer_one', 'Writer One');

        $this->assertSame('Writer One', $row->name);
        $custom = $this->customRows('writer_one')[0];
        $this->assertSame(['Writer One'], json_decode((string) $custom->portfolio_names, true), 'no __meta__ entry, a plain list with the label');
        $this->assertSame('active', $custom->status);
    }

    public function test_create_with_meta_writes_the_four_fields_and_an_inactive_status_archives_the_master_row(): void
    {
        $row = ProductLines::create($this->identity(), 'meta_one', 'Meta One', ['description' => 'd', 'category' => 'c', 'logo' => 'l', 'status' => 'inactive']);

        $this->assertSame(ProductMaster::STATUS_ARCHIVED, $row->status);
        $custom = $this->customRows('meta_one')[0];
        $this->assertSame('inactive', $custom->status);
        $this->assertSame(['description' => 'd', 'category' => 'c', 'logo' => 'l'], json_decode((string) $custom->portfolio_names, true)['__meta__']);

        $other = ProductLines::create($this->identity(), 'meta_two', 'Meta Two', ['status' => 'whatever']);
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $other->status, 'an unknown status is the default one, as createCustom has always treated it');
        $this->assertSame('active', $this->customRows('meta_two')[0]->status);
    }
}
