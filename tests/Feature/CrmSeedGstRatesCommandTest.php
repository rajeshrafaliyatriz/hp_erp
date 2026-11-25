<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan crm:seed-gst-rates` (S16, contract section 4): the five GST rates, tied to INR, for every organisation that works in rupees.
 *
 *   - a dry run is the default and writes nothing
 *   - --apply adds GST 0, 5, 12, 18 and 28 percent with currency INR, ONE transaction per organisation
 *   - it NEVER edits a rate that is there (an old "GST 18%" with no currency, marked default, stays exactly as it is) and only adds the ones that are missing
 *   - a second run adds nothing (idempotent)
 *   - an organisation that works in another currency and has no product in INR is skipped; --tenant names one; the missing column is a refusal
 */
class CrmSeedGstRatesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('crm_tax_rates', function ($t) {
            $t->id();
            $t->string('name', 100);
            $t->decimal('percentage', 7, 3);
            $t->string('currency', 3)->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('sub_institute_id');
            $t->timestamps();
        });
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('currency', 10)->nullable();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('setting_key', 100);
            $t->text('setting_value')->nullable();
        });
        // 6: works in rupees (no saved setting, so the default INR) and has products; 7: saves USD and sells in USD; 8: saves USD but has one product priced in INR; 9: saves INR.
        DB::table('crm_products')->insert([
            ['sub_institute_id' => 6, 'currency' => 'INR'], ['sub_institute_id' => 7, 'currency' => 'USD'], ['sub_institute_id' => 8, 'currency' => 'inr'],
        ]);
        DB::table('tenant_setting')->insert([
            ['sub_institute_id' => 7, 'setting_key' => 'org.currency', 'setting_value' => 'USD'],
            ['sub_institute_id' => 8, 'setting_key' => 'org.currency', 'setting_value' => 'USD'],
            ['sub_institute_id' => 9, 'setting_key' => 'org.currency', 'setting_value' => 'INR'],
        ]);
    }

    /** @return array{0: int, 1: string} */
    private function seeded(array $options = []): array
    {
        $code = Artisan::call('crm:seed-gst-rates', $options);

        return [$code, Artisan::output()];
    }

    /** @return array<int, list<string>> tenant => "name|percentage|currency" sorted */
    private function rates(): array
    {
        $out = [];
        foreach (DB::table('crm_tax_rates')->orderBy('id')->get() as $r) {
            $out[(int) $r->sub_institute_id][] = $r->name . '|' . (float) $r->percentage . '|' . ($r->currency ?? '-');
        }
        foreach ($out as &$list) {
            sort($list);
        }

        return $out;
    }

    private function all(string $currency = 'INR'): array
    {
        $names = ['GST 0%' => 0, 'GST 12%' => 12, 'GST 18%' => 18, 'GST 28%' => 28, 'GST 5%' => 5];
        $out = [];
        foreach ($names as $name => $p) {
            $out[] = "{$name}|{$p}|{$currency}";
        }
        sort($out);

        return $out;
    }

    public function test_a_dry_run_is_the_default_and_writes_nothing(): void
    {
        [$code, $out] = $this->seeded();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('organisation 6: would add 5 rate(s)', $out);
        $this->assertStringContainsString('organisation 7: skipped, it works in USD', $out);
        $this->assertStringContainsString('organisation 8: would add 5', $out);
        $this->assertStringContainsString('organisation 9: would add 5', $out);
        $this->assertSame(0, DB::table('crm_tax_rates')->count());
    }

    public function test_apply_adds_the_five_rates_tied_to_inr_to_the_organisations_that_work_in_rupees_only(): void
    {
        [$code, $out] = $this->seeded(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame([6 => $this->all(), 8 => $this->all(), 9 => $this->all()], $this->rates(), 'organisation 7 works in dollars and gets none');
        $row = DB::table('crm_tax_rates')->where('sub_institute_id', 6)->where('name', 'GST 18%')->first();
        $this->assertSame([1, 0], [(int) $row->is_active, (int) $row->is_default]);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $this->seeded(['--apply' => true]);
        $first = DB::table('crm_tax_rates')->orderBy('id')->get()->all();

        [$code, $out] = $this->seeded(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to do, it has all five GST rates', $out);
        $this->assertStringContainsString('0 rate(s) added', $out);
        $this->assertEquals($first, DB::table('crm_tax_rates')->orderBy('id')->get()->all());
    }

    public function test_it_never_edits_a_rate_that_exists_and_adds_only_what_is_missing(): void
    {
        DB::table('crm_tax_rates')->insert([
            ['name' => 'GST 18%', 'percentage' => 18, 'currency' => null, 'is_default' => true, 'is_active' => false, 'created_by' => 55, 'sub_institute_id' => 6, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ['name' => 'gst 5%', 'percentage' => 5, 'currency' => 'USD', 'is_default' => false, 'is_active' => true, 'created_by' => null, 'sub_institute_id' => 6, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            // same name, other percentage: that is not "a rate of that name and percentage", so the real one is added
            ['name' => 'GST 12%', 'percentage' => 11, 'currency' => null, 'is_default' => false, 'is_active' => true, 'created_by' => null, 'sub_institute_id' => 6, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
        ]);
        $existing = DB::table('crm_tax_rates')->orderBy('id')->get()->all();

        [$code, $out] = $this->seeded(['--apply' => true, '--tenant' => 6]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('organisation 6: added 3 rate(s): GST 0%, GST 12%, GST 28%', $out);
        $this->assertEquals($existing, DB::table('crm_tax_rates')->whereIn('id', [1, 2, 3])->orderBy('id')->get()->all(), 'the three old rows are byte for byte what they were');
        $this->assertSame(6, DB::table('crm_tax_rates')->count());
        $this->assertSame(0, DB::table('crm_tax_rates')->where('sub_institute_id', '<>', 6)->count(), '--tenant touched one organisation only');
    }

    public function test_tenant_names_one_organisation_and_still_asks_whether_it_works_in_rupees(): void
    {
        [$code, $out] = $this->seeded(['--apply' => true, '--tenant' => 7]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('organisation 7: skipped', $out);
        $this->assertSame(0, DB::table('crm_tax_rates')->count());

        $this->seeded(['--apply' => true, '--tenant' => 42]);   // an organisation nobody has written anything for works in INR by default
        $this->assertSame([42 => $this->all()], $this->rates());
    }

    public function test_a_bad_tenant_and_a_missing_column_are_refusals_that_write_nothing(): void
    {
        [$code, $out] = $this->seeded(['--tenant' => 'six']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('whole number', $out);

        Schema::table('crm_tax_rates', fn ($t) => $t->dropColumn('currency'));
        [$code, $out] = $this->seeded(['--apply' => true]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('no `currency` column', $out);
        $this->assertSame(0, DB::table('crm_tax_rates')->count());
    }

    public function test_an_unknown_connection_is_refused(): void
    {
        [$code, $out] = $this->seeded(['--apply' => true, '--connection' => 'nowhere']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString("no database connection named 'nowhere'", $out);
    }
}
