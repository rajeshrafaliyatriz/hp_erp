<?php

namespace Tests\Feature;

use App\Console\Commands\ProductMasterUnifyCommand;
use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductUnifySnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductUnifyWorld;
use Tests\TestCase;

/**
 * `php artisan product-master:unify` (slice 15, track P2-D): the dry run, the owner's map, the apply with its snapshot, and the rollback. On the shape of the dev data
 * (tests/Support/BuildsProductUnifyWorld.php), on in-memory sqlite; the snapshot and the reports go to a temporary folder (config product-master.unify_folder).
 *
 * What it proves: the dry run writes nothing and prints every column the plan asks for; `--apply` without `--map` does nothing; a bad pair stops the whole file before the
 * first write; `--apply` merges ONLY the pairs in the map, never a suggestion; the snapshot of every touched row exists before the first write; `--rollback` puts every row
 * back byte for byte and removes the decision rows, and refuses when a row changed since; the host guard.
 */
class ProductUnifyCommandTest extends TestCase
{
    use BuildsProductUnifyWorld;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUnifyWorld();
        $this->dir = sys_get_temp_dir() . '/pm-unify-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['product-master.unify_folder' => $this->dir . '/out']);
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

    /** @param array<string, mixed> $options @return array{0: int, 1: string} */
    private function unify(array $options): array
    {
        $code = Artisan::call('product-master:unify', $options);

        return [$code, Artisan::output()];
    }

    /** @param list<array<string, mixed>> $pairs */
    private function mapFile(array $pairs, string $name = 'map.json'): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, json_encode($pairs));

        return $path;
    }

    /** @return list<string> */
    private function outFiles(string $prefix = ''): array
    {
        return array_values(array_filter(glob($this->dir . '/out/' . $prefix . '*') ?: [], 'is_file'));
    }

    private function masterRow(int $id): object
    {
        return DB::table('g2g_product_master')->where('id', $id)->first();
    }

    // ------------------------------------------------------------------ the dry run

    public function test_the_dry_run_writes_nothing_and_prints_and_saves_the_report_with_every_column_and_verdict(): void
    {
        $w = $this->scholarWorld();
        $this->pointAtCrm($w['items']['gujarat']['crm']);
        $before = $this->dumpEverything();

        [$code, $out] = $this->unify(['--tenant' => self::T]);

        $this->assertSame(0, $code, $out);
        $this->assertSame($before, $this->dumpEverything(), 'a dry run writes nothing: every row of every table, updated_at included, is as it was');

        // the columns of the plan
        foreach (['Product', 'CRM id', 'Master', 'Product no', 'Name', 'SKU', 'Type', 'Price', 'State', 'Usage', 'Candidate Sub-product', 'Scores', 'Margin', 'Verdict'] as $column) {
            $this->assertStringContainsString($column, $out, "the table has the column {$column}");
        }
        $this->assertStringContainsString('DRY RUN (nothing is written to any table)', $out);
        $this->assertStringContainsString('ORGANISATION 1', $out);
        $this->assertStringContainsString('Verdicts: MATCH-HIGH 0, EDITION 2, AMBIGUOUS 2, SERVICE 2, NO-MATCH 3, STRAY 0, JUNK? 0', $out);
        $this->assertStringContainsString('TABLE 1 - live catalogue items, by Product', $out);
        $this->assertStringContainsString('TABLE 2 - Sub-products that no item names', $out);
        $this->assertStringContainsString('TABLE 3 - catalogue rows in the Recycle Bin', $out);
        $this->assertStringContainsString('0 of 8 Sub-product offer(s) say they are a service', $out, 'the services finding is stated');
        // one row per item, with its facts
        $this->assertStringContainsString('Scholar One ERP - Gujarat Edition', $out);
        $this->assertStringContainsString('SCH-01-GUJ', $out);
        $this->assertStringContainsString('120000.00', $out);
        $this->assertStringContainsString('cat 40 sku 30 words 12 text 16 = 98', $out);
        $this->assertStringContainsString('SCH-01 Scholar School ERP', $out);
        $this->assertStringContainsString("the category 'School ERP' is inside the Sub-product name (+40)", $out);
        $this->assertStringContainsString('quote 1', $out, 'the usage counts are shown');
        $this->assertStringContainsString('NO-MATCH', $out);
        $this->assertStringContainsString('SERVICE', $out);
        // the second table lists a Sub-product no item names
        $this->assertMatchesRegularExpression('/Prompt-to-Video Lessons/', $out);

        // saved: the same text, in the folder, outside the database
        $files = $this->outFiles('report-t1-');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Scholar One ERP - Gujarat Edition', (string) file_get_contents($files[0]));
        $this->assertStringContainsString('ORGANISATION 1', (string) file_get_contents($files[0]));
    }

    public function test_the_report_says_there_is_no_map_to_start_from_when_no_pair_is_clear_and_starts_one_when_a_pair_is(): void
    {
        $w = $this->scholarWorld();

        // the shape of the dev data has no MATCH-HIGH pair: the two editions and the weak ones need a person
        [, $out] = $this->unify(['--tenant' => self::T]);
        $this->assertStringContainsString('No pair is MATCH-HIGH here, so there is no map to start from', $out);
        $this->assertStringNotContainsString('The MATCH-HIGH pairs, as a map to start from', $out);

        $career = $this->item('Career Intelligence Suite', ['category' => 'Career Intelligence', 'sku' => 'SCH-04-X', 'description' => 'student career guidance competency']);
        [, $out2] = $this->unify(['--tenant' => self::T]);
        $this->assertStringContainsString('The MATCH-HIGH pairs, as a map to start from', $out2);
        $this->assertStringContainsString('[{"item":' . $career['master'] . ',"survivor":' . $w['subs']['SCH-04']['master'] . ',"name":"portfolio"}]', $out2, 'a file a person can edit and hand back as --map');
        $this->assertStringNotContainsString('No pair is MATCH-HIGH here', $out2);
        // and that very text is a map file the command accepts
        preg_match('/^  (\[\{"item":.*\}\])$/m', $out2, $m);
        $file = $this->dir . '/starter.json';
        file_put_contents($file, $m[1]);
        $this->assertSame([['item' => $career['master'], 'survivor' => $w['subs']['SCH-04']['master'], 'name' => 'portfolio']], ProductMasterUnifyCommand::readMap($file));
    }

    public function test_the_report_lists_the_rows_in_the_recycle_bin_with_their_usage_and_flags_a_test_row_that_a_line_still_uses(): void
    {
        $this->scholarWorld();
        $junk = $this->crm(self::T, 'ZZ UI Widget', null, ['deleted_at' => now()]);
        $used = $this->crm(self::T, 'Enterprise License', null, ['deleted_at' => now()]);
        $unused = $this->crm(self::T, 'Old pack', null, ['deleted_at' => now()]);
        $this->line('crm_quote_line_items', self::T, $junk, 2, 250);
        $this->line('crm_quote_line_items', self::T, $used, 1, 5000);
        $this->line('crm_invoice_line_items', self::T, $used, 1, 5000);

        [, $out] = $this->unify(['--tenant' => self::T]);

        $this->assertStringContainsString('JUNK? (a test row, but 1 line(s) still use it)', $out);
        $this->assertMatchesRegularExpression('/Enterprise License\s+\| product \|[^|]*\| binned \| quote 1, inv 1 \(=2\)[^|]*\| in use: keep/', $out);
        $this->assertMatchesRegularExpression('/Old pack\s+\| product \|[^|]*\| binned \| 0\s+\|/', $out, 'a row nothing uses has no note');
        unset($unused);
    }

    public function test_a_report_is_saved_where_asked_and_an_existing_file_is_never_overwritten(): void
    {
        $this->scholarWorld();
        $path = $this->dir . '/dryrun.txt';

        [$code] = $this->unify(['--tenant' => self::T, '--report' => $path]);
        $this->assertSame(0, $code);
        $this->assertFileExists($path);
        $first = (string) file_get_contents($path);

        [$code2, $out2] = $this->unify(['--tenant' => self::T, '--report' => $path]);
        $this->assertSame(2, $code2);
        $this->assertStringContainsString('Refusing to overwrite', $out2);
        $this->assertSame($first, (string) file_get_contents($path));
    }

    public function test_without_a_tenant_the_dry_run_covers_every_organisation_that_has_live_items(): void
    {
        $this->scholarWorld();
        $this->item('Their one item', [], self::T2);

        [$code, $out] = $this->unify([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ORGANISATION 1', $out);
        $this->assertStringContainsString('ORGANISATION 2', $out);
        $this->assertStringContainsString('Their one item', $out);
    }

    public function test_a_bad_tenant_is_refused(): void
    {
        foreach (['abc', '0', '-3', '1.5'] as $bad) {
            [$code, $out] = $this->unify(['--tenant' => $bad]);
            $this->assertSame(2, $code, $bad);
            $this->assertStringContainsString('--tenant must be a whole number above 0', $out);
        }
    }

    // ------------------------------------------------------------------ apply without a map does nothing

    public function test_apply_without_a_map_does_nothing_and_says_so(): void
    {
        $w = $this->scholarWorld();
        $career = $this->item('Career Intelligence Suite', ['category' => 'Career Intelligence', 'sku' => 'SCH-04-X', 'description' => 'student career guidance competency']);   // a MATCH-HIGH suggestion
        $before = $this->dumpEverything();

        [$code, $out] = $this->unify(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('--apply needs --map=<file>', $out);
        $this->assertStringContainsString('a suggestion is never merged on its own', $out);
        $this->assertSame($before, $this->dumpEverything(), 'not one row changed, though a clear suggestion exists');
        $this->assertSame([], $this->outFiles(), 'no snapshot, no report');
        $this->assertNull($this->masterRow($w['subs']['SCH-04']['master'])->crm_product_id);
        $this->assertSame('active', $this->masterRow($career['master'])->status);

        // --apply needs the organisation too
        [$code2, $out2] = $this->unify(['--apply' => true, '--map' => $this->mapFile([['item' => $career['master'], 'survivor' => $w['subs']['SCH-04']['master']]])]);
        $this->assertSame(2, $code2);
        $this->assertStringContainsString('--apply needs --tenant', $out2);
        $this->assertSame($before, $this->dumpEverything());
    }

    public function test_apply_and_rollback_do_not_mix_and_a_rollback_takes_no_map(): void
    {
        $this->scholarWorld();
        $before = $this->dumpEverything();

        [$c1, $o1] = $this->unify(['--tenant' => self::T, '--apply' => true, '--rollback' => $this->dir . '/x.json']);
        [$c2, $o2] = $this->unify(['--tenant' => self::T, '--rollback' => $this->dir . '/x.json', '--map' => $this->dir . '/m.json']);

        $this->assertSame([2, 2], [$c1, $c2]);
        $this->assertStringContainsString('two different runs', $o1);
        $this->assertStringContainsString('takes no --map', $o2);
        $this->assertSame($before, $this->dumpEverything());
    }

    // ------------------------------------------------------------------ the map: check first, merge only what it names

    public function test_a_map_alone_only_checks_it(): void
    {
        $w = $this->scholarWorld();
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master'], 'name' => 'catalogue']]);
        $before = $this->dumpEverything();

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('CHECK OF THE MAP (nothing is written)', $out);
        $this->assertStringContainsString('ok to merge', $out);
        $this->assertStringContainsString('Every pair can be merged. Nothing was written. Add --apply', $out);
        $this->assertSame($before, $this->dumpEverything());
        $this->assertSame([], $this->outFiles('snapshot-'), 'a check takes no snapshot');
    }

    public function test_one_bad_pair_stops_the_whole_file_before_anything_is_written_not_even_the_snapshot(): void
    {
        $w = $this->scholarWorld();
        $theirs = $this->item('Their item', [], self::T2);
        $map = $this->mapFile([
            ['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']],
            ['item' => $theirs['master'], 'survivor' => $w['subs']['SCH-02']['master']],   // another organisation's item
        ]);
        $before = $this->dumpEverything();

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED pair 2', $out);
        $this->assertStringContainsString('is not one of yours', $out);
        $this->assertStringContainsString('Nothing was written: fix the map file', $out);
        $this->assertSame($before, $this->dumpEverything(), 'the good pair was not merged either: the file is all or nothing at the check');
        $this->assertSame([], $this->outFiles('snapshot-'));
    }

    public function test_apply_merges_only_the_pairs_of_the_map_never_a_suggestion(): void
    {
        $w = $this->scholarWorld();
        $career = $this->item('Career Intelligence Suite', ['category' => 'Career Intelligence', 'sku' => 'SCH-04-X', 'description' => 'student career guidance competency']);
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master'], 'name' => 'portfolio']]);
        // the suggester calls the career pair MATCH-HIGH, and the owner did not name it
        $row = collect((new \App\Domain\ProductMaster\ProductMatchSuggester)->suggest(self::T)['items'])->firstWhere('itemMasterId', $career['master']);
        $this->assertSame('MATCH-HIGH', $row['verdict']);

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('MERGED  pair 1', $out);
        $this->assertStringContainsString('RESULT: OK', $out);
        // the named pair is one record now
        $this->assertSame($w['items']['gujarat']['crm'], (int) $this->masterRow($w['subs']['SCH-01']['master'])->crm_product_id);
        $this->assertSame('merged', $this->masterRow($w['items']['gujarat']['master'])->origin);
        // the clear suggestion nobody confirmed is exactly as it was
        $this->assertNull($this->masterRow($w['subs']['SCH-04']['master'])->crm_product_id);
        $this->assertSame(['active', 'crm'], [$this->masterRow($career['master'])->status, $this->masterRow($career['master'])->origin]);
        $this->assertSame(1, DB::table('g2g_product_match_decisions')->count());
        $this->assertSame(0, (new ProductIntegrity(null, self::T))->check()['errors']);
        $this->assertCount(1, $this->outFiles('snapshot-t1-'));
        $this->assertCount(1, $this->outFiles('apply-t1-'), 'the summary is saved too');
        $this->assertSame('product.merged', $this->events[0]['type']);
        $this->assertNull($this->events[0]['actor']);
    }

    public function test_the_snapshot_exists_with_every_touched_row_before_the_first_write_and_holds_the_rows_as_they_were(): void
    {
        $w = $this->scholarWorld();
        $g = $w['items']['gujarat'];
        $this->pointAtCrm($g['crm']);
        $refs = $this->pointAtMaster($g['master']);
        $map = $this->mapFile([['item' => $g['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $huskBefore = (array) $this->masterRow($g['master']);

        $atFirstWrite = null;
        $dir = $this->dir . '/out';
        DB::listen(function ($q) use (&$atFirstWrite, $dir) {
            if ($atFirstWrite === null && preg_match('/^(update|insert into|delete from) "(g2g_product_master|g2g_product_match_decisions|crm_products|g2g_product_offers|crm_leads)"/', $q->sql) === 1) {
                $atFirstWrite = array_map('basename', glob($dir . '/snapshot-*.json') ?: []);
            }
        });

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertNotNull($atFirstWrite, 'the apply wrote');
        $this->assertCount(1, $atFirstWrite, 'the snapshot file was already there when the first write happened');

        $snapshot = ProductUnifySnapshot::read($this->outFiles('snapshot-t1-')[0]);
        $this->assertSame(ProductUnifySnapshot::FORMAT, $snapshot['format']);
        $this->assertSame(self::T, $snapshot['tenant']);
        $pair = $snapshot['pairs'][0];
        $this->assertTrue($pair['applied']);
        $this->assertSame('merged', $pair['result']);
        $this->assertNull($pair['decisionBefore']);
        // the rows as they were, in full: the husk (with its catalogue pointer), the survivor, the catalogue row, the offer, and every row at the husk's id
        $this->assertSame($huskBefore, $pair['rows']['g2g_product_master'][$g['master']]);
        $this->assertNull($pair['rows']['g2g_product_master'][$w['subs']['SCH-01']['master']]['crm_product_id']);
        $this->assertSame('Scholar One ERP - Gujarat Edition', $pair['rows']['crm_products'][$g['crm']]['name'], 'the catalogue row before it was renamed');
        $this->assertArrayHasKey($w['subs']['SCH-01']['offer'], $pair['rows']['g2g_product_offers']);
        foreach ($refs as $key => $id) {
            [$table] = explode('.', $key);
            $this->assertSame($g['master'], $pair['rows'][$table][$id]['product_master_id'], "{$key}: the row as it was, pointing at the husk");
        }
        // and after the merge the fingerprints say what the merge left
        $this->assertSame($g['master'], (int) $pair['rows']['g2g_product_master'][$g['master']]['id']);
        $this->assertNotEmpty($pair['after']['g2g_product_master']);
        $this->assertArrayHasKey(ProductUnifySnapshot::DECISIONS, $pair['after']);
    }

    public function test_a_pair_that_fails_leaves_the_others_alone_and_the_exit_code_says_so(): void
    {
        $w = $this->scholarWorld();
        $refs = $this->pointAtCrm($w['items']['gujarat']['crm']);
        $second = $this->subProduct('SCH-90', 'Fee Reminder Hub', ['what_it_does' => 'Reminders']);
        $map = $this->mapFile([
            ['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']],
            ['item' => $w['items']['fees']['master'], 'survivor' => $second['master']],
        ]);
        // while the FIRST pair is being merged, a quote line total changes: the census check takes that pair back
        $done = false;
        DB::listen(function ($q) use (&$done, $refs) {
            if (! $done && str_starts_with($q->sql, 'insert into "g2g_product_match_decisions"')) {
                $done = true;
                DB::table('crm_quote_line_items')->where('id', $refs['crm_quote_line_items.product_id'])->update(['line_total' => 5]);
            }
        });

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAILED  pair 1', $out);
        $this->assertStringContainsString('a count or a sum', $out);
        $this->assertStringContainsString('MERGED  pair 2', $out);
        $this->assertStringContainsString('RESULT: FAILED (1 pair(s) failed', $out);
        $this->assertNull($this->masterRow($w['subs']['SCH-01']['master'])->crm_product_id, 'the first pair was taken back');
        $this->assertSame($w['items']['fees']['crm'], (int) $this->masterRow($second['master'])->crm_product_id, 'the second was merged');
        $snapshot = ProductUnifySnapshot::read($this->outFiles('snapshot-t1-')[0]);
        $this->assertSame(['failed', 'merged'], [$snapshot['pairs'][0]['result'], $snapshot['pairs'][1]['result']]);
        $this->assertFalse($snapshot['pairs'][0]['applied']);
        $this->assertTrue($snapshot['pairs'][1]['applied']);
    }

    public function test_a_pair_that_was_already_merged_is_a_no_op_and_an_undo_of_that_run_skips_it(): void
    {
        $w = $this->scholarWorld();
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);
        $firstSnapshot = $this->outFiles('snapshot-t1-')[0];
        $mid = $this->dumpEverything();

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('already merged, nothing to do', $out);
        $this->assertStringContainsString('ALREADY pair 1', $out);
        $this->assertSame($mid, $this->dumpEverything(), 'the second run wrote no row');
        $files = $this->outFiles('snapshot-t1-');
        $this->assertCount(2, $files);
        $second = array_values(array_diff($files, [$firstSnapshot]))[0];

        // undoing the second run changes nothing: that run did not make the merge
        [$code2, $out2] = $this->unify(['--rollback' => $second]);
        $this->assertSame(0, $code2, $out2);
        $this->assertStringContainsString('SKIPPED item', $out2);
        $this->assertStringContainsString('it was already merged before this run', $out2);
        $this->assertSame($mid, $this->dumpEverything());

        // undoing the first run does
        [$code3, $out3] = $this->unify(['--rollback' => $firstSnapshot]);
        $this->assertSame(0, $code3, $out3);
        $this->assertStringContainsString('UNDONE  item', $out3);
        $this->assertNull($this->masterRow($w['subs']['SCH-01']['master'])->crm_product_id);
    }

    // ------------------------------------------------------------------ the way back

    public function test_rollback_puts_every_row_back_byte_for_byte_and_removes_the_decision_rows(): void
    {
        $w = $this->scholarWorld();
        $g = $w['items']['gujarat'];
        $this->pointAtCrm($g['crm']);
        $this->pointAtMaster($g['master']);
        $this->pointAtMaster($w['subs']['SCH-01']['master'], self::T, 'A claim of the survivor');
        $this->claim($g['master'], 'A second claim of the husk', 'approved');
        $this->claim($w['subs']['SCH-01']['master'], 'a second claim of the husk', 'proposed');   // collides with it once normalised
        $before = $this->dumpEverything();
        $map = $this->mapFile([['item' => $g['master'], 'survivor' => $w['subs']['SCH-01']['master'], 'name' => 'catalogue']]);

        [$code] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);
        $this->assertSame(0, $code);
        $this->assertNotSame($before, $this->dumpEverything(), 'the merge changed things');
        $this->assertSame(1, DB::table('g2g_product_match_decisions')->count());
        $this->events = [];

        [$code2, $out2] = $this->unify(['--rollback' => $this->outFiles('snapshot-t1-')[0]]);

        $this->assertSame(0, $code2, $out2);
        $this->assertStringContainsString('UNDONE  item ' . $g['master'], $out2);
        $this->assertStringContainsString('RESULT: OK', $out2);
        $this->assertSame($before, $this->dumpEverything(), 'every row of every table, every column, is exactly as it was before the apply');
        $this->assertSame(0, DB::table('g2g_product_match_decisions')->count(), 'the decision row the merge made is gone');
        $this->assertSame('product.merge_undone', $this->events[0]['type']);
        $this->assertSame(0, (new ProductIntegrity(null, self::T))->check()['errors']);
    }

    public function test_rollback_puts_a_decision_row_that_existed_back_as_it_was(): void
    {
        $w = $this->scholarWorld();
        $g = $w['items']['gujarat'];
        // the owner had said "Different" about the pair and now changes their mind
        $merger = new \App\Domain\ProductMaster\ProductMerger;
        $merger->decideDifferent(['sub_institute_id' => self::T], $g['master'], $w['subs']['SCH-01']['master']);
        $before = $this->dumpEverything();
        $map = $this->mapFile([['item' => $g['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);

        $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);
        $this->assertSame('same', DB::table('g2g_product_match_decisions')->value('decision'));
        [$code, $out] = $this->unify(['--rollback' => $this->outFiles('snapshot-t1-')[0]]);

        $this->assertSame(0, $code, $out);
        $this->assertSame($before, $this->dumpEverything());
        $this->assertSame('different', DB::table('g2g_product_match_decisions')->value('decision'));
    }

    public function test_rollback_refuses_when_a_row_changed_after_the_merge_and_changes_nothing(): void
    {
        $w = $this->scholarWorld();
        $g = $w['items']['gujarat'];
        $this->pointAtMaster($g['master']);
        $map = $this->mapFile([['item' => $g['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);
        // somebody renames the merged record, and somebody approves a claim, after the merge
        DB::table('g2g_product_offers')->where('id', $w['subs']['SCH-01']['offer'])->update(['name' => 'Renamed since the merge']);
        DB::table('g2g_product_claims')->update(['status' => 'approved']);
        $after = $this->dumpEverything();

        [$code, $out] = $this->unify(['--rollback' => $this->outFiles('snapshot-t1-')[0]]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED: Nothing was undone', $out);
        $this->assertStringContainsString('g2g_product_offers row ' . $w['subs']['SCH-01']['offer'] . ' was changed after the merge', $out);
        $this->assertStringContainsString('g2g_product_claims row', $out);
        $this->assertSame($after, $this->dumpEverything(), 'not one row was overwritten');
    }

    public function test_rollback_refuses_a_snapshot_of_another_organisation_and_a_file_that_is_not_one(): void
    {
        $w = $this->scholarWorld();
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);
        $snapshot = $this->outFiles('snapshot-t1-')[0];
        $after = $this->dumpEverything();

        [$c1, $o1] = $this->unify(['--tenant' => self::T2, '--rollback' => $snapshot]);
        file_put_contents($this->dir . '/junk.json', '{"hello": 1}');
        [$c2, $o2] = $this->unify(['--rollback' => $this->dir . '/junk.json']);
        [$c3, $o3] = $this->unify(['--rollback' => $this->dir . '/missing.json']);

        $this->assertSame([2, 2, 2], [$c1, $c2, $c3]);
        $this->assertStringContainsString('taken for organisation 1 but --tenant asks for 2', $o1);
        $this->assertStringContainsString('not a snapshot written by product-master:unify', $o2);
        $this->assertStringContainsString('does not exist or cannot be read', $o3);
        $this->assertSame($after, $this->dumpEverything());
    }

    public function test_the_checker_finding_unrelated_damage_fails_the_pair_and_writes_nothing(): void
    {
        $w = $this->scholarWorld();
        DB::table('crm_leads')->insert(['product_master_id' => 987654, 'sub_institute_id' => self::T, 'updated_at' => self::STAMP]);
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $before = $this->dumpEverything();

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAILED  pair 1', $out);
        $this->assertStringContainsString('so nothing was merged', $out);
        $this->assertStringContainsString('Checker (organisation 1):', $out);
        $this->assertStringContainsString('RESULT: FAILED', $out);
        $this->assertSame($before, $this->dumpEverything());
    }

    // ------------------------------------------------------------------ the host guard

    public function test_a_host_that_is_not_the_dev_host_is_refused_unless_the_connection_is_named(): void
    {
        $this->scholarWorld();
        $before = $this->dumpEverything();
        $default = config('database.default');
        config([
            'database.connections.elsewhere' => ['driver' => 'mysql', 'host' => '10.9.9.9', 'port' => 3306, 'database' => 'x', 'username' => 'u', 'password' => ''],
            'database.default' => 'elsewhere',
        ]);

        [$code, $out] = $this->unify(['--tenant' => self::T]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString("Refusing to read host '10.9.9.9'", $out);
        $this->assertSame([], $this->outFiles(), 'it did not even try');

        [$code2] = $this->unify(['--tenant' => self::T, '--apply' => true, '--map' => $this->mapFile([['item' => 1, 'survivor' => 2]])]);
        $this->assertSame(2, $code2);

        // naming a connection is the deliberate act
        [$code3, $out3] = $this->unify(['--tenant' => self::T, '--database' => $default]);
        $this->assertSame(0, $code3, $out3);
        $this->assertStringContainsString('ORGANISATION 1', $out3);
        [$code4, $out4] = $this->unify(['--tenant' => self::T, '--database' => 'nope']);
        $this->assertSame(2, $code4);
        $this->assertStringContainsString("There is no database connection named 'nope'", $out4);
        $this->assertSame('elsewhere', DB::getDefaultConnection(), 'the command put the default back as it found it, even when it ran on another connection');
        config(['database.default' => $default]);   // the test's own pretence that the default host is another one ends here
        $this->assertSame($before, $this->dumpEverything());
    }

    // ------------------------------------------------------------------ the snapshot file

    public function test_a_snapshot_is_never_overwritten_by_the_first_write_and_is_replaced_whole_by_the_update(): void
    {
        $path = $this->dir . '/out/snapshot-x.json';
        ProductUnifySnapshot::write($path, ['format' => ProductUnifySnapshot::FORMAT, 'tenant' => 1, 'pairs' => []]);
        $first = (string) file_get_contents($path);

        try {
            ProductUnifySnapshot::write($path, ['format' => ProductUnifySnapshot::FORMAT, 'tenant' => 2, 'pairs' => []]);
            $this->fail('the baseline was overwritten');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to overwrite', $e->getMessage());
        }
        $this->assertSame($first, (string) file_get_contents($path));

        ProductUnifySnapshot::write($path, ['format' => ProductUnifySnapshot::FORMAT, 'tenant' => 3, 'pairs' => []], true);
        $this->assertSame(3, ProductUnifySnapshot::read($path)['tenant']);
        $this->assertSame([], glob($this->dir . '/out/*.tmp*') ?: [], 'no half-written file is left behind');
        $this->assertNull(ProductUnifySnapshot::read($this->dir . '/out/none.json', $why));
        $this->assertStringContainsString('does not exist', (string) $why);
    }

    public function test_the_named_connection_is_the_one_the_command_uses(): void
    {
        $w = $this->scholarWorld();
        // a second, EMPTY database: if the command really runs on the connection it is told to, it finds no Product Master there
        config(['database.connections.pmother' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);

        [$code, $out] = $this->unify(['--tenant' => self::T, '--database' => 'pmother']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('connection pmother (sqlite)', $out);
        $this->assertStringContainsString('the Product Master, the CRM catalogue or the Portfolio table is not on this host', $out);
        $this->assertStringNotContainsString('Scholar One ERP - Gujarat Edition', $out, 'the default connection\'s rows are not read');
        $this->assertSame(config('database.default'), DB::getDefaultConnection(), 'the named connection was the default for the run only: the process is put back as it was');
        $this->assertSame(0, DB::table('g2g_product_match_decisions')->count());

        // and a map applied to it finds nothing of the default connection's either
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        [$code2, $out2] = $this->unify(['--tenant' => self::T, '--database' => 'pmother', '--map' => $map, '--apply' => true]);
        $this->assertSame(1, $code2, $out2);
        $this->assertNull($this->masterRow($w['subs']['SCH-01']['master'])->crm_product_id, 'the default connection was not touched');
    }

    public function test_a_report_place_that_cannot_be_written_is_refused_before_anything_is_merged(): void
    {
        $w = $this->scholarWorld();
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $before = $this->dumpEverything();
        // a regular file where a folder is needed
        $blocker = $this->dir . '/blocker';
        file_put_contents($blocker, 'x');

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true, '--report' => $blocker . '/sub/summary.txt']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('cannot be written, so the report could not be saved there. Nothing was done.', $out);
        $this->assertSame($before, $this->dumpEverything(), 'the merge was not done and then reported nowhere');
        $this->assertSame([], $this->outFiles('snapshot-'));
    }

    public function test_a_summary_that_cannot_be_saved_after_the_merge_is_a_warning_not_a_failure_of_a_merge_that_is_done(): void
    {
        $w = $this->scholarWorld();
        $map = $this->mapFile([['item' => $w['items']['gujarat']['master'], 'survivor' => $w['subs']['SCH-01']['master']]]);
        $report = $this->dir . '/summary.txt';
        // the place was fine when the command checked it; by the time the merge has been written something occupies it (a folder where the file would go)
        $done = false;
        DB::listen(function ($q) use (&$done, $report) {
            if (! $done && str_starts_with($q->sql, 'update "g2g_product_master"')) {
                $done = true;
                mkdir($report);
            }
        });

        [$code, $out] = $this->unify(['--tenant' => self::T, '--map' => $map, '--apply' => true, '--report' => $report]);

        $this->assertTrue($done);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('MERGED  pair 1', $out);
        $this->assertStringContainsString('The summary could not be saved', $out);
        $this->assertStringContainsString('RESULT: OK', $out);
        $this->assertSame($w['items']['gujarat']['crm'], (int) $this->masterRow($w['subs']['SCH-01']['master'])->crm_product_id, 'the merge is a fact');
        rmdir($report);
    }

    // ------------------------------------------------------------------ the map file

    public function test_the_map_file_accepts_json_and_csv_and_refuses_what_it_cannot_trust(): void
    {
        $read = function (string $content, string $name = 'm.txt') use (&$problem): ?array {
            $path = $this->dir . '/' . $name;
            file_put_contents($path, $content);
            $problem = null;

            return ProductMasterUnifyCommand::readMap($path, $problem);
        };

        $this->assertSame([['item' => 5, 'survivor' => 9, 'name' => 'portfolio']], $read('[{"item": 5, "survivor": 9}]'), 'the name defaults to the Portfolio\'s');
        $this->assertSame([['item' => 5, 'survivor' => 9, 'name' => 'catalogue'], ['item' => 6, 'survivor' => 10, 'name' => 'portfolio']], $read('{"pairs": [{"itemMasterId": 5, "survivorMasterId": 9, "nameKept": "Catalogue"}, {"item_master_id": "6", "survivor_master_id": "10", "name_kept": "portfolio"}]}'));
        $this->assertSame([['item' => 5, 'survivor' => 9, 'name' => 'catalogue'], ['item' => 6, 'survivor' => 10, 'name' => 'portfolio']], $read("item,survivor,name\n5,9,catalogue\n6,10,\n"), 'CSV with a header');
        $this->assertSame([['item' => 5, 'survivor' => 9, 'name' => 'portfolio']], $read("\xEF\xBB\xBFItem;Survivor;Name\r\n5;9;portfolio\r\n"), 'a spreadsheet\'s semicolons, header case and byte order mark');

        $this->assertNull($read('[{"item": 5, "survivor": 5}]'));
        $this->assertStringContainsString('cannot be merged with itself', (string) $problem);
        $this->assertNull($read('[{"item": 5, "survivor": 9}, {"item": 5, "survivor": 10}]'));
        $this->assertStringContainsString('repeats item 5', (string) $problem);
        $this->assertNull($read('[{"item": 5, "survivor": 9}, {"item": 6, "survivor": 9}]'));
        $this->assertStringContainsString('repeats Sub-product 9', (string) $problem);
        $this->assertNull($read('[{"item": 5, "survivor": 9, "name": "both"}]'));
        $this->assertStringContainsString("'portfolio' or 'catalogue', not 'both'", (string) $problem);
        $this->assertNull($read('[{"item": "five", "survivor": 9}]'));
        $this->assertStringContainsString('whole numbers above 0', (string) $problem);
        $this->assertNull($read('[{"item": 0, "survivor": 9}]'));
        $this->assertNull($read('[{"survivor": 9}]'));
        $this->assertNull($read('[]'));
        $this->assertStringContainsString('holds no pair', (string) $problem);
        $this->assertNull($read('[{"item": 1, "survivor": 2}'));
        $this->assertStringContainsString('not valid JSON', (string) $problem);
        $this->assertNull($read('not a map'));
        $this->assertStringContainsString('needs a header line', (string) $problem);
        $this->assertNull($read('[1, 2]'));
        $this->assertStringContainsString('is not an object', (string) $problem);

        $many = array_map(fn (int $i): array => ['item' => 1000 + $i, 'survivor' => 2000 + $i], range(1, ProductMasterUnifyCommand::MAX_PAIRS + 1));
        $this->assertNull($read((string) json_encode($many)));
        $this->assertStringContainsString('at most ' . ProductMasterUnifyCommand::MAX_PAIRS, (string) $problem);
        $this->assertCount(ProductMasterUnifyCommand::MAX_PAIRS, (array) $read((string) json_encode(array_slice($many, 0, ProductMasterUnifyCommand::MAX_PAIRS))));

        $this->assertNull(ProductMasterUnifyCommand::readMap($this->dir . '/nothing-here.json', $missing));
        $this->assertStringContainsString('does not exist or cannot be read', (string) $missing);
    }
}
