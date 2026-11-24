<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductIsolator;
use App\Domain\ProductMaster\ProductPointers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductUnifyWorld;
use Tests\TestCase;

/**
 * `php artisan product-master:isolate-tenants` (S15 follow-up, 24 Nov 2026): the one-off data command that gives every shared product row to the organisation it belongs to
 * (contract Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md, section 3). On the SHAPE of the dev data before the change, on in-memory sqlite:
 *
 *   - three shared Products (organisation 0): ScholarClone, Enterprise Brain, Higher Education;
 *   - organisation 1 (not the owner) with three Sub-products under the shared Products, a catalogue item under ScholarClone, and a row in EVERY table that points at a master
 *     row (the ten of ProductPointers::toMaster()) holding ScholarClone;
 *   - organisation 6 (the owner) with a Sub-product under ScholarClone and one row in every pointer table holding Enterprise Brain;
 *   - organisation 2, which points at nothing shared;
 *   - an About with no organisation, with its shared Sub-product row.
 *
 * What it proves: the dry run writes nothing (the same tables, row by row, and only SELECT statements); `--apply` gives the shared Products to the owner (same ids), makes organisation 1
 * its own copies (same key, name, signal key, Portfolio name, active) and moves EVERY registered pointer of organisation 1 to them, leaves organisation 6's and organisation 2's
 * rows alone, gives the About with no organisation to the owner, saves the before-image BEFORE the first write, and leaves the checker's `not_isolated` at 0; a second run does nothing;
 * `--rollback` puts every row back exactly (the Products it made are gone), and refuses when a row changed since or when a record now points at a Product the run made; the owner's
 * own Product wins when it has the same key; an organisation's own Product of that key is used instead of a copy; what cannot be placed is listed and left alone; one organisation
 * failing leaves the others done; the owner must be one of the configured organisations; a host that is not the dev host is refused unless named.
 */
class ProductIsolateTenantsCommandTest extends TestCase
{
    use BuildsProductUnifyWorld;

    private const OWNER = 6;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUnifyWorld();
        config(['product_isolation.builtin_owner_tenants' => [self::OWNER]]);
        $this->dir = sys_get_temp_dir() . '/pm-isolate-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['product_isolation.folder' => $this->dir . '/out']);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/out/*') ?: [], glob($this->dir . '/*') ?: []) as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir . '/out');
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ the world

    /**
     * The data before the change. Every organisation's rows are made by hand so the ids are known.
     *
     * @return array<string, mixed>
     */
    private function legacyWorld(): array
    {
        // Nothing the Backfill made for the test world: only the shared rows of the old sharing exist.
        DB::table('g2g_product_master')->delete();
        DB::table('g2g_product_offers')->delete();
        DB::table('crm_products')->delete();

        $w = [];
        $w['sc'] = $this->master(['sub_institute_id' => 0, 'product_key' => 'scholarclone', 'kind' => 'product', 'name' => 'ScholarClone', 'origin' => 'builtin', 'signal_key' => 'scholar', 'portfolio_name' => 'Scholar K-12']);
        $w['eb'] = $this->master(['sub_institute_id' => 0, 'product_key' => 'enterprise_brain', 'kind' => 'product', 'name' => 'Enterprise Brain', 'origin' => 'builtin', 'signal_key' => 'eb', 'portfolio_name' => 'EB']);
        $w['he'] = $this->master(['sub_institute_id' => 0, 'product_key' => 'he', 'kind' => 'product', 'name' => 'Higher Education', 'origin' => 'portfolio', 'portfolio_name' => 'HE']);

        // Organisation 1: three Sub-products, one catalogue item, a row in every pointer table.
        $w['o1a'] = $this->offerRow(self::T, 'P1', 'Scholar One', 'Scholar K-12');
        $w['o1b'] = $this->offerRow(self::T, 'P2', 'Scholar Two', 'Scholar K-12');
        $w['o1c'] = $this->offerRow(self::T, 'E1', 'Brain One', 'EB');
        $w['s1a'] = $this->master(['sub_institute_id' => self::T, 'kind' => 'sub_product', 'product_key' => 'scholarclone_p1', 'name' => 'Scholar One', 'parent_id' => $w['sc'], 'offer_row_id' => $w['o1a'], 'origin' => 'portfolio']);
        $w['s1b'] = $this->master(['sub_institute_id' => self::T, 'kind' => 'sub_product', 'product_key' => 'scholarclone_p2', 'name' => 'Scholar Two', 'parent_id' => $w['sc'], 'offer_row_id' => $w['o1b'], 'origin' => 'portfolio']);
        $w['s1c'] = $this->master(['sub_institute_id' => self::T, 'kind' => 'sub_product', 'product_key' => 'enterprise_brain_e1', 'name' => 'Brain One', 'parent_id' => $w['eb'], 'offer_row_id' => $w['o1c'], 'origin' => 'portfolio']);
        $w['c1'] = $this->crm(self::T, 'Scholar item', $w['sc']);
        $w['i1'] = $this->master(['sub_institute_id' => self::T, 'kind' => 'item', 'product_key' => 'crm/c1', 'name' => 'Scholar item', 'parent_id' => $w['sc'], 'crm_product_id' => $w['c1'], 'origin' => 'crm']);
        $w['p1'] = $this->pointAtMaster($w['sc'], self::T);

        // Organisation 6, the owner: a Sub-product under ScholarClone, a row in every pointer table holding Enterprise Brain.
        $w['o6'] = $this->offerRow(self::OWNER, 'P1', 'Owner One', 'Scholar K-12');
        $w['s6'] = $this->master(['sub_institute_id' => self::OWNER, 'kind' => 'sub_product', 'product_key' => 'scholarclone_p1', 'name' => 'Owner One', 'parent_id' => $w['sc'], 'offer_row_id' => $w['o6'], 'origin' => 'portfolio']);
        $w['p6'] = $this->pointAtMaster($w['eb'], self::OWNER);

        // Organisation 2 points at nothing shared. An About with no organisation, with its shared Sub-product row, is the owner's.
        $w['o2'] = $this->offerRow(self::T2, 'Z1', 'Two One', 'Payroll');
        $w['null'] = $this->offerRow(null, 'N1', 'Foundation One', 'Scholar K-12');
        $w['nullRow'] = $this->master(['sub_institute_id' => 0, 'kind' => 'sub_product', 'product_key' => 'scholarclone_n1', 'name' => 'Foundation One', 'parent_id' => $w['sc'], 'offer_row_id' => $w['null'], 'origin' => 'portfolio']);

        return $w;
    }

    /** An About row of the real table (its NOT NULL columns filled); a tenant of null is an About with no organisation. */
    private function offerRow(?int $tenant, string $offerId, string $name, string $group): int
    {
        return (int) DB::table('g2g_product_offers')->insertGetId([
            'sub_institute_id' => $tenant, 'offer_id' => $offerId, 'name' => $name, 'parent_product' => $group, 'readiness_status' => 'Prototype', 'deployment_model' => 'SaaS (multi-tenant)',
            'typical_deal_band' => 'To fill', 'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> every row of every table the command can touch, as a plain array, so two states can be compared */
    private function dump(): array
    {
        $out = [];
        $tables = array_merge(ProductPointers::tables(), [ProductPointers::MASTER, ProductPointers::OFFERS]);
        foreach (array_unique($tables) as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    /** @param array<string, mixed> $options @return array{0: int, 1: string} */
    private function isolate(array $options = []): array
    {
        // a rollback takes no owner (the file knows it)
        $code = Artisan::call('product-master:isolate-tenants', isset($options['--rollback']) ? $options : $options + ['--owner' => self::OWNER]);

        return [$code, Artisan::output()];
    }

    /** @return list<string> */
    private function outFiles(string $prefix = ''): array
    {
        return array_values(array_filter(glob($this->dir . '/out/' . $prefix . '*') ?: [], 'is_file'));
    }

    private function row(int $id): object
    {
        return DB::table('g2g_product_master')->where('id', $id)->first();
    }

    private function own(int $tenant, string $key): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('product_key', $key)->first();
    }

    // ------------------------------------------------------------------ the dry run

    public function test_the_dry_run_writes_nothing_and_says_what_it_would_do(): void
    {
        $w = $this->legacyWorld();
        $before = $this->dump();
        DB::enableQueryLog();

        [$code, $out] = $this->isolate();

        $log = DB::getQueryLog();
        $this->assertSame(0, $code, $out);
        $this->assertSame($before, $this->dump(), 'a dry run writes nothing: every row of every table is as it was, updated_at included');
        $notReads = array_values(array_filter(array_map(fn (array $q): string => (string) $q['query'], $log), fn (string $sql): bool => preg_match('/^\s*(select|pragma|show)\b/i', $sql) !== 1));
        $this->assertSame([], $notReads, 'every statement the dry run sent was a SELECT (or a read-only PRAGMA)');

        $this->assertStringContainsString('DRY RUN (nothing is written to any table)', $out);
        $this->assertStringContainsString('Shared rows found: 3 Product(s) and 1 other row(s) of organisation 0; 1 About row(s) with no organisation. Owner organisation: 6.', $out);
        $this->assertStringContainsString("would make its own Product 'ScholarClone' (key scholarclone) from shared Product {$w['sc']}", $out);
        $this->assertStringContainsString("would make its own Product 'Enterprise Brain' (key enterprise_brain) from shared Product {$w['eb']}", $out);
        $this->assertStringContainsString("would take shared Product {$w['sc']} 'ScholarClone' (key scholarclone): it becomes organisation 6's row, the id stays", $out);
        $this->assertStringContainsString('would take 1 About row(s) that have no organisation', $out);
        $this->assertStringContainsString('Organisation 1', $out);
        $this->assertStringNotContainsString('Organisation 2', $out, 'an organisation that points at nothing shared is not in the plan');
        $this->assertStringContainsString('Nothing was written. Add --apply to write them.', $out);
        $this->assertSame([], $this->outFiles('isolation-'), 'a dry run saves no before-image');
    }

    public function test_the_dry_run_sets_no_before_image_and_a_summary_is_saved_outside_the_database(): void
    {
        $this->legacyWorld();
        [, $out] = $this->isolate();

        $this->assertCount(1, $this->outFiles('dry-run-'), 'the printed text is saved in the folder, outside the database');
        $this->assertStringContainsString('Summary saved to', $out);
    }

    // ------------------------------------------------------------------ the apply

    public function test_apply_gives_the_shared_products_to_the_owner_and_each_other_organisation_its_own_copy_and_moves_every_registered_pointer(): void
    {
        $w = $this->legacyWorld();
        $dev = $this->dump();

        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('RESULT: OK', $out);

        // the owner takes the shared rows: the SAME ids, so nothing that points at them changes
        foreach (['sc', 'eb', 'he'] as $k) {
            $this->assertSame(self::OWNER, (int) $this->row($w[$k])->sub_institute_id, "shared Product {$k} is organisation 6's now");
        }
        $this->assertSame(self::OWNER, (int) $this->row($w['nullRow'])->sub_institute_id, 'the shared Sub-product of the About with no organisation is the owner\'s');
        $this->assertSame(self::OWNER, (int) DB::table('g2g_product_offers')->where('id', $w['null'])->value('sub_institute_id'), 'the About with no organisation is the owner\'s');

        // organisation 1 has its OWN copies of the two Products it pointed at, with the same key, name, signal key and Portfolio name, active; and none of Higher Education
        $sc1 = $this->own(self::T, 'scholarclone');
        $eb1 = $this->own(self::T, 'enterprise_brain');
        $this->assertNotNull($sc1);
        $this->assertNotNull($eb1);
        $this->assertNull($this->own(self::T, 'he'), 'it never pointed at Higher Education, so it gets no copy');
        $this->assertSame(['ScholarClone', 'scholar', 'Scholar K-12', 'product', 'active', 'builtin'], [$sc1->name, $sc1->signal_key, $sc1->portfolio_name, $sc1->kind, $sc1->status, $sc1->origin]);
        $this->assertSame(['Enterprise Brain', 'eb', 'EB'], [$eb1->name, $eb1->signal_key, $eb1->portfolio_name]);
        $this->assertNotSame($w['sc'], (int) $sc1->id);

        // its Sub-products and its catalogue item sit under its own Products now
        foreach (['s1a', 's1b', 'i1'] as $k) {
            $this->assertSame((int) $sc1->id, (int) $this->row($w[$k])->parent_id, "{$k} sits under organisation 1's own ScholarClone");
        }
        $this->assertSame((int) $eb1->id, (int) $this->row($w['s1c'])->parent_id);
        $this->assertSame((int) $sc1->id, (int) DB::table('crm_products')->where('id', $w['c1'])->value('product_master_id'), 'the Sell item of organisation 1 is under its own ScholarClone');

        // EVERY registered pointer of organisation 1 moved (no table is named by hand in the command: the registry is the list)
        foreach (ProductPointers::toMaster() as $e) {
            if ($e['table'] === ProductPointers::CRM_PRODUCTS) {
                continue;
            }
            $key = $e['table'] . '.' . $e['column'];
            $this->assertSame((int) $sc1->id, (int) DB::table($e['table'])->where('id', $w['p1'][$key])->value($e['column']), "{$key} of organisation 1 points at its own ScholarClone");
        }
        // nothing of organisation 1 points at a shared row any more, anywhere
        foreach (ProductPointers::toMaster() as $e) {
            $this->assertSame(0, DB::table($e['table'])->where($e['scope'], self::T)->whereIn($e['column'], [$w['sc'], $w['eb'], $w['he']])->count(), "{$e['table']}.{$e['column']}: nothing of organisation 1 points at a shared Product");
        }

        // the owner's and organisation 2's rows are exactly as they were
        $after = $this->dump();
        foreach ($w['p6'] as $key => $id) {
            [$table] = explode('.', $key);
            $this->assertSame($dev[$table][array_search($id, array_column($dev[$table], 'id'), true)], $after[$table][array_search($id, array_column($after[$table], 'id'), true)], "{$key} of the owner is untouched");
        }
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->count(), 'organisation 2 pointed at nothing shared: no Product was made for it');
        $this->assertSame(1, DB::table('g2g_product_offers')->where('sub_institute_id', self::T2)->count());

        // the checker: nothing is shared any more
        $check = (new ProductIntegrity)->check();
        $this->assertSame(0, $check['counts']['not_isolated']);
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', 0)->count());
        $this->assertSame(0, DB::table('g2g_product_offers')->whereNull('sub_institute_id')->count());
    }

    public function test_the_before_image_is_saved_before_the_first_write_and_holds_every_row_the_run_touched_and_the_products_it_made(): void
    {
        $w = $this->legacyWorld();
        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $files = $this->outFiles('isolation-');
        $this->assertCount(1, $files);
        $image = json_decode((string) file_get_contents($files[0]), true);

        $this->assertSame(ProductIsolator::FORMAT, $image['format']);
        $this->assertSame(self::OWNER, $image['owner']);
        // the shared rows, as they were (tenant 0), in full
        foreach (['sc', 'eb', 'he', 'nullRow'] as $k) {
            $this->assertSame(0, (int) $image['tables']['g2g_product_master'][$w[$k]]['sub_institute_id'], "{$k} is saved as it was: organisation 0");
        }
        $this->assertNull($image['tables']['g2g_product_offers'][$w['null']]['sub_institute_id'], 'the About with no organisation is saved as it was: NULL');
        // organisation 1's Sub-products with their old parent, and the pointer rows with their old value
        $this->assertSame($w['sc'], (int) $image['tables']['g2g_product_master'][$w['s1a']]['parent_id']);
        foreach ($w['p1'] as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($w['sc'], (int) $image['tables'][$table][$id][$column], "{$key} is saved with the shared id it held");
            $this->assertContains($column, $image['columns'][$table][$id], "{$key}: the column the run changes is named");
        }
        // the owner's own rows were not touched, so they are not in the file
        $this->assertArrayNotHasKey($w['s6'], $image['tables']['g2g_product_master']);
        // the two Products it made, with the organisation, and every change with its old and new value
        $this->assertCount(2, $image['created']);
        $made = array_column($image['created'], 'key', 'id');
        $this->assertEqualsCanonicalizing(['scholarclone', 'enterprise_brain'], array_values($made));
        $this->assertSame('applied', $image['orgs'][self::T]['state']);
        $this->assertSame('applied', $image['orgs'][self::OWNER]['state']);
        $sc1 = $this->own(self::T, 'scholarclone');
        $this->assertSame(['from' => $w['sc'], 'to' => (int) $sc1->id], $image['changes']['g2g_product_master'][$w['s1a']]['parent_id']);
        // the order of events: the file was written BEFORE the first write (the summary of the command says so before it says DONE)
        $this->assertLessThan(strpos($out, 'DONE   organisation 1'), strpos($out, 'Before-image of the'));
    }

    public function test_a_second_run_does_nothing(): void
    {
        $this->legacyWorld();
        $this->isolate(['--apply' => true]);
        $dump = $this->dump();
        $files = $this->outFiles('isolation-');

        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Nothing to do: every product row already belongs to one organisation.', $out);
        $this->assertSame($dump, $this->dump(), 'a second run changes nothing');
        $this->assertSame($files, $this->outFiles('isolation-'), 'and saves no new before-image');
    }

    public function test_every_pointer_of_the_registry_is_moved_so_a_new_one_is_moved_without_anybody_remembering_it(): void
    {
        $w = $this->legacyWorld();
        $this->isolate(['--apply' => true]);
        $sc1 = (int) $this->own(self::T, 'scholarclone')->id;

        $checked = [];
        foreach (ProductPointers::toMaster() as $e) {
            $key = $e['table'] . '.' . $e['column'];
            $checked[] = $key;
            $held = $e['table'] === ProductPointers::CRM_PRODUCTS
                ? DB::table('crm_products')->where('id', $w['c1'])->value('product_master_id')
                : DB::table($e['table'])->where('id', $w['p1'][$key])->value($e['column']);
            $this->assertSame($sc1, (int) $held, "{$key} was moved to the organisation's own Product");
        }
        $this->assertGreaterThanOrEqual(10, count($checked), 'all ten registered pointers at a master row were looked at');
        $this->assertSame((int) $this->own(self::T, 'scholarclone')->id, (int) $this->row($w['s1a'])->parent_id, 'and so was the master row\'s own parent_id');
    }

    public function test_the_owner_wins_when_it_already_has_a_product_with_the_same_key(): void
    {
        $w = $this->legacyWorld();
        // the owner made its own ScholarClone before: its Sub-product and its leads sit under THAT one
        $own = $this->master(['sub_institute_id' => self::OWNER, 'product_key' => 'scholarclone', 'kind' => 'product', 'name' => 'ScholarClone (owner)', 'origin' => 'custom', 'signal_key' => 'scholar', 'portfolio_name' => 'Scholar K-12']);
        $pointers = $this->pointAtMaster($w['sc'], self::OWNER);
        DB::table('g2g_product_master')->where('id', $w['s6'])->update(['parent_id' => $w['sc']]);
        $before = $this->dump();

        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("let its own Product {$own} win over shared Product {$w['sc']}", $out);
        // the owner's rows moved to its own Product, the shared row is gone, the owner's own row kept its name
        $this->assertSame($own, (int) $this->row($w['s6'])->parent_id, 'the owner\'s Sub-product is under its own Product');
        foreach ($pointers as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($own, (int) DB::table($table)->where('id', $id)->value($column), "{$key} of the owner points at its own Product");
        }
        $this->assertNull(DB::table('g2g_product_master')->where('id', $w['sc'])->first(), 'the shared row was deleted: nothing points at it any more');
        $this->assertSame('ScholarClone (owner)', $this->row($own)->name, 'the owner\'s row wins: it is not renamed');
        // organisation 1 got its own copy from the shared row's data, before it was deleted
        $this->assertNotNull($this->own(self::T, 'scholarclone'));
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', 0)->count());
        $this->assertSame(0, (new ProductIntegrity)->check()['counts']['not_isolated']);

        // and the way back brings the shared row back with its id, and every row as it was
        [$rc, $rout] = $this->isolate(['--rollback' => $this->outFiles('isolation-')[0]]);
        $this->assertSame(0, $rc, $rout);
        $this->assertSame($before, $this->dump());
    }

    public function test_an_organisation_with_its_own_product_of_that_key_uses_it_instead_of_a_copy(): void
    {
        $w = $this->legacyWorld();
        $mine = $this->master(['sub_institute_id' => self::T, 'product_key' => 'scholarclone', 'kind' => 'product', 'name' => 'Our ScholarClone', 'origin' => 'custom', 'signal_key' => 'scholar', 'portfolio_name' => 'Scholar K-12']);

        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("will use its own Product {$mine}", $out);
        $this->assertSame(1, DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('product_key', 'scholarclone')->count(), 'no second ScholarClone was made');
        $this->assertSame($mine, (int) $this->row($w['s1a'])->parent_id);
        $this->assertSame('Our ScholarClone', $this->row($mine)->name, 'its own row is not renamed');
    }

    // ------------------------------------------------------------------ what it cannot place

    public function test_what_cannot_be_placed_is_listed_and_left_exactly_as_it_is(): void
    {
        $w = $this->legacyWorld();
        // organisation 1 already holds ANOTHER row with ScholarClone's signal key: a copy would break the unique key
        $this->master(['sub_institute_id' => self::T, 'product_key' => 'old_scholar', 'kind' => 'product', 'name' => 'Old', 'signal_key' => 'scholar', 'portfolio_name' => 'Old']);
        // a lead of no organisation points at a shared Product
        $lost = DB::table('crm_leads')->insertGetId(['product_master_id' => $w['he'], 'sub_institute_id' => null, 'updated_at' => self::STAMP]);
        $before = $this->dump();

        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(1, $code, 'something could not be placed: the exit code says so');
        $this->assertStringContainsString("the organisation already holds row", $out);
        $this->assertStringContainsString("with the signal key 'scholar'", $out);
        $this->assertStringContainsString('1 row(s) with no organisation point at a shared row', $out);
        // organisation 1's ScholarClone references are untouched, and the shared ScholarClone stays shared (organisation 1 still points at it)
        $this->assertSame($w['sc'], (int) $this->row($w['s1a'])->parent_id);
        $this->assertSame(0, (int) $this->row($w['sc'])->sub_institute_id, 'a shared Product another organisation still points at is not given away');
        $this->assertStringContainsString('KEPT', $out);
        $this->assertSame($w['he'], (int) DB::table('crm_leads')->where('id', $lost)->value('product_master_id'), 'the lead of no organisation is untouched');
        // what could be placed was: Enterprise Brain for organisation 1, Higher Education for the owner
        $this->assertNotNull($this->own(self::T, 'enterprise_brain'));
        $this->assertNotSame($before, $this->dump());
        // the checker still says so
        $this->assertGreaterThan(0, (new ProductIntegrity)->check()['counts']['not_isolated']);
    }

    public function test_an_organisation_that_points_at_a_shared_sub_product_blocks_that_row_and_nothing_else(): void
    {
        $w = $this->legacyWorld();
        DB::table('crm_leads')->insert(['product_master_id' => $w['nullRow'], 'sub_institute_id' => self::T, 'updated_at' => self::STAMP]);

        [$code, $out] = $this->isolate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("points at the shared sub_product {$w['nullRow']}", $out);
        $this->assertSame(0, (int) $this->row($w['nullRow'])->sub_institute_id, 'the shared Sub-product stays where it is');
        $this->assertNull(DB::table('g2g_product_offers')->where('id', $w['null'])->value('sub_institute_id'), 'and so does its About');
        $this->assertSame(self::OWNER, (int) $this->row($w['he'])->sub_institute_id, 'the rest was done');
    }

    public function test_one_organisation_failing_leaves_the_others_done_and_writes_nothing_of_its_own(): void
    {
        $w = $this->legacyWorld();
        // organisation 2 also points at the two Products; its second action will fail because the plan goes stale
        DB::table('crm_leads')->insert(['product_master_id' => $w['sc'], 'sub_institute_id' => self::T2, 'updated_at' => self::STAMP]);
        DB::table('crm_leads')->insert(['product_master_id' => $w['eb'], 'sub_institute_id' => self::T2, 'updated_at' => self::STAMP]);
        $isolator = new ProductIsolator;
        $plan = $isolator->plan(self::OWNER);
        $image = $isolator->beforeImage($plan, ['test' => true]);
        // the shared Enterprise Brain is deleted by somebody between the plan and the apply
        DB::table('g2g_product_master')->where('id', $w['eb'])->delete();

        $result = $isolator->apply($plan, $image, $this->dir . '/image.json');

        $this->assertArrayHasKey(self::T, $result['failed'], 'organisation 1 failed on the stale plan');
        $this->assertArrayHasKey(self::T2, $result['failed']);
        $this->assertNull($this->own(self::T, 'scholarclone'), 'and nothing of its transaction survived: not even the copy it made first');
        $this->assertNull($this->own(self::T2, 'scholarclone'));
        $this->assertSame($w['sc'], (int) $this->row($w['s1a'])->parent_id);
        $this->assertStringContainsString('failed', (string) json_decode((string) file_get_contents($this->dir . '/image.json'), true)['orgs'][self::T]['state']);
    }

    // ------------------------------------------------------------------ the way back

    public function test_rollback_puts_every_row_back_exactly_and_removes_the_products_it_made(): void
    {
        $this->legacyWorld();
        $before = $this->dump();
        $this->isolate(['--apply' => true]);
        $this->assertNotSame($before, $this->dump());
        $file = $this->outFiles('isolation-')[0];

        [$code, $out] = $this->isolate(['--rollback' => $file]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('UNDONE:', $out);
        $this->assertSame($before, $this->dump(), 'every row of every table is as it was, byte for byte');
        $this->assertNull($this->own(self::T, 'scholarclone'), 'the copies the run made are gone');
        $this->assertGreaterThan(0, (new ProductIntegrity)->check()['counts']['not_isolated'], 'the shared rows are shared again');
    }

    public function test_rollback_refuses_when_a_row_changed_since_and_writes_nothing(): void
    {
        $w = $this->legacyWorld();
        $this->isolate(['--apply' => true]);
        $file = $this->outFiles('isolation-')[0];
        // somebody moved a lead of organisation 1 to another Product after the run
        $key = 'crm_leads.product_master_id';
        DB::table('crm_leads')->where('id', $w['p1'][$key])->update(['product_master_id' => $w['he']]);
        $dump = $this->dump();

        [$code, $out] = $this->isolate(['--rollback' => $file]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertStringContainsString('crm_leads row ' . $w['p1'][$key], $out);
        $this->assertStringContainsString('Nothing was undone.', $out);
        $this->assertSame($dump, $this->dump());
    }

    public function test_rollback_refuses_when_a_new_record_points_at_a_product_the_run_made(): void
    {
        $this->legacyWorld();
        $this->isolate(['--apply' => true]);
        $file = $this->outFiles('isolation-')[0];
        $sc1 = (int) $this->own(self::T, 'scholarclone')->id;
        DB::table('crm_leads')->insert(['product_master_id' => $sc1, 'sub_institute_id' => self::T, 'updated_at' => self::STAMP]);
        $dump = $this->dump();

        [$code, $out] = $this->isolate(['--rollback' => $file]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("point at the Product {$sc1} the run made", $out);
        $this->assertSame($dump, $this->dump(), 'the transaction was taken back: nothing was undone');
    }

    // ------------------------------------------------------------------ the census: the proof that a run, and its rollback, did exactly what they say

    /** @return list<string> the lines of one section of the printed text, from its title to the next blank line */
    private function section(string $out, string $title): array
    {
        $lines = explode("\n", $out);
        $from = null;
        foreach ($lines as $i => $line) {
            if ($from === null && str_starts_with($line, $title)) {
                $from = $i;
            }
            if ($from !== null && $i > $from && trim($line) === '') {
                return array_slice($lines, $from, $i - $from);
            }
        }

        return $from === null ? [] : array_slice($lines, $from);
    }

    public function test_the_dry_run_prints_the_census_and_the_plan_by_table_and_organisation_and_the_checksum_plan(): void
    {
        $this->legacyWorld();

        [, $out] = $this->isolate();

        $census = $this->section($out, 'CENSUS BEFORE');
        $this->assertNotEmpty($census);
        foreach ([ProductPointers::MASTER, ProductPointers::OFFERS, ...array_map(fn (array $e): string => $e['table'], ProductPointers::toMaster())] as $table) {
            $this->assertNotEmpty(array_filter($census, fn (string $l): bool => str_contains($l, $table . ' ')), "{$table} is in the census");
        }
        $this->assertMatchesRegularExpression('/g2g_product_master\s+rows\s+\d+\s+md5 [0-9a-f]{32}\s+by organisation 0:4/', implode("\n", $census));
        $changes = implode("\n", $this->section($out, 'WHAT THE APPLY WOULD CHANGE'));
        $this->assertStringContainsString('sub_institute_id changes (organisation 0 -> owner 6)', $changes);
        $this->assertStringContainsString('parent_id changes, organisation 1:', $changes);
        $this->assertStringContainsString('crm_leads', $changes);
        $this->assertStringContainsString('product_master_id changes: organisation 1: 1 row(s)', $changes, 'one row of organisation 1 in every pointer table');
        $this->assertStringContainsString('sub_institute_id changes (no organisation -> owner 6): 1 row(s)', $changes);
        $plan = implode("\n", $this->section($out, 'CHECKSUM PLAN'));
        $this->assertStringContainsString('after --rollback: EVERY table has the row count and the md5 of the CENSUS BEFORE', $plan);
    }

    public function test_the_apply_proves_that_the_rows_it_did_not_touch_are_the_ones_from_before_and_the_rollback_proves_the_whole_database_is(): void
    {
        $this->legacyWorld();
        [, $dryBefore] = $this->isolate();

        [$code, $applied] = $this->isolate(['--apply' => true]);
        $this->assertSame(0, $code, $applied);
        $this->assertStringContainsString('CENSUS AFTER THE APPLY', $applied);
        $this->assertStringContainsString('UNTOUCHED ROWS: identical in all', $applied);

        [$code, $undone] = $this->isolate(['--rollback' => $this->outFiles('isolation-')[0]]);
        $this->assertSame(0, $code, $undone);
        $this->assertStringContainsString('CENSUS AFTER THE ROLLBACK', $undone);
        $this->assertStringContainsString('IDENTICAL: every one of', $undone);

        // and a dry run after the rollback prints the very same census as the one before the run
        [, $dryAfter] = $this->isolate();
        $this->assertSame($this->section($dryBefore, 'CENSUS BEFORE'), $this->section($dryAfter, 'CENSUS BEFORE'), 'the census after the rollback is the census before the run, line for line, md5 for md5');
    }

    public function test_the_rollback_says_so_when_the_database_is_not_what_it_was_even_if_the_rollback_itself_worked(): void
    {
        $w = $this->legacyWorld();
        $this->isolate(['--apply' => true]);
        // somebody wrote a column the run never wrote, in a row the run changed: the rollback may restore the pointer, but the table is no longer the table of before
        DB::table('crm_leads')->where('id', $w['p1']['crm_leads.product_master_id'])->update(['updated_at' => '2027-01-01 00:00:00']);

        [$code, $out] = $this->isolate(['--rollback' => $this->outFiles('isolation-')[0]]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('DIFFERENT from before the run', $out);
        $this->assertStringContainsString('crm_leads: rows', $out);
    }

    public function test_the_census_sees_a_change_of_one_column_of_one_row_and_the_untouched_comparison_ignores_what_the_run_may_change(): void
    {
        $this->legacyWorld();
        $isolator = new ProductIsolator;
        $before = $isolator->census();

        $this->assertSame([], ProductIsolator::censusDifferences($before, $isolator->census()), 'the same database, the same census');
        DB::table('g2g_product_master')->where('id', DB::table('g2g_product_master')->where('kind', 'product')->orderBy('id')->value('id'))->update(['updated_at' => '2030-01-01 00:00:00']);
        $changed = $isolator->census();
        $differences = ProductIsolator::censusDifferences($before, $changed);
        $this->assertCount(1, $differences);
        $this->assertStringStartsWith('g2g_product_master: rows', $differences[0]);
        $this->assertSame($before['crm_leads']['checksum'], $changed['crm_leads']['checksum'], 'another table is not affected');

        // the untouched comparison: a touched row may change, an untouched one may not
        $touchedId = (int) DB::table('g2g_product_master')->where('kind', 'product')->orderBy('id')->value('id');
        $withTouched = $isolator->census(['g2g_product_master' => [$touchedId => true]]);
        DB::table('g2g_product_master')->where('id', $touchedId)->update(['name' => 'Changed by the run']);
        $afterTouched = $isolator->census(['g2g_product_master' => [$touchedId => true]]);
        $this->assertSame([], ProductIsolator::untouchedDifferences($withTouched, $afterTouched), 'a row the run touches may change');
        $other = (int) DB::table('g2g_product_master')->where('kind', 'sub_product')->orderBy('id')->value('id');
        DB::table('g2g_product_master')->where('id', $other)->update(['name' => 'Changed by somebody else']);
        $afterStray = $isolator->census(['g2g_product_master' => [$touchedId => true]]);
        $this->assertCount(1, ProductIsolator::untouchedDifferences($withTouched, $afterStray), 'a row the run does not touch may not');
    }

    // ------------------------------------------------------------------ the guards

    public function test_the_owner_must_be_one_of_the_configured_organisations(): void
    {
        $this->legacyWorld();
        $dump = $this->dump();

        [$code, $out] = $this->isolate(['--owner' => 7, '--apply' => true]);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Organisation 7 is not in config product_isolation.builtin_owner_tenants (6)', $out);
        $this->assertSame($dump, $this->dump());

        [$code, $out] = $this->isolate(['--owner' => 'abc']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--owner must be a whole number above 0', $out);
    }

    public function test_the_owner_defaults_to_the_first_configured_organisation(): void
    {
        $this->legacyWorld();
        config(['product_isolation.builtin_owner_tenants' => [self::OWNER, 7]]);

        $code = Artisan::call('product-master:isolate-tenants');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Owner organisation: 6.', $out);
        $this->assertStringContainsString('config product_isolation.builtin_owner_tenants: 6, 7', $out);
    }

    public function test_apply_and_rollback_are_two_different_runs(): void
    {
        [$code, $out] = $this->isolate(['--apply' => true, '--rollback' => $this->dir . '/x.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('two different runs', $out);
    }

    public function test_a_missing_before_image_is_refused(): void
    {
        $code = Artisan::call('product-master:isolate-tenants', ['--rollback' => $this->dir . '/nope.json']);
        $out = Artisan::output();

        $this->assertSame(2, $code);
        $this->assertStringContainsString('does not exist or cannot be read', $out);
    }

    public function test_a_host_that_is_not_the_dev_host_is_refused_unless_it_is_named(): void
    {
        config(['database.default' => 'prod_like', 'database.connections.prod_like' => ['driver' => 'mysql', 'host' => '10.9.9.9', 'database' => 'x']]);

        $code = Artisan::call('product-master:isolate-tenants', ['--owner' => self::OWNER]);
        $out = Artisan::output();

        $this->assertSame(2, $code);
        $this->assertStringContainsString("Refusing to run on host '10.9.9.9'", $out);
    }
}
