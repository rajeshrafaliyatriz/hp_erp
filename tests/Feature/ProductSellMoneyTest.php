<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\CatalogueFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * S16 (25 Nov 2026), contract section 4: the money rules of a Sell section, at BOTH doors that write a catalogue row (the one-record writer `/api/product-master` and the CRM Products form
 * `/api/crm/products`, with its import and export), all of them one class (SellMoney):
 *   - a non-empty currency is a catalogue code (any case, stored upper case): else 422 "Choose a currency from the list." and nothing is written
 *   - a value that is exactly what the row already holds is accepted unchanged: an old free-text row can still be saved (never "fixed" silently)
 *   - a new row with no currency gets the organisation's default (`org.currency` when it is in the list, else INR)
 *   - a tax rate tied to a currency fits a Sub-product priced in that currency only, whichever of the two a request changes (a rate with no currency fits any)
 *   - the import validates `currency` and `taxRate` per row (a bad row is reported, not saved); the export carries Tax Rate and Tax %
 */
class ProductSellMoneyTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    private const ADMIN = 1;

    private int $inrRate;

    private int $anyRate;

    private int $usdRate;

    private int $otherRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        Schema::table('crm_tax_rates', fn ($t) => $t->string('currency', 3)->nullable());
        $rate = fn (int $tenant, string $name, float $pct, ?string $currency, int $active = 1): int => (int) DB::table('crm_tax_rates')->insertGetId(
            ['sub_institute_id' => $tenant, 'name' => $name, 'percentage' => $pct, 'currency' => $currency, 'is_active' => $active]
        );
        $this->inrRate = $rate(self::T, 'GST 18%', 18, 'INR');
        $this->anyRate = $rate(self::T, 'Service charge', 10, null);
        $this->usdRate = $rate(self::T, 'US sales tax', 7.5, 'USD');
        $this->otherRate = $rate(self::T2, 'Elsewhere', 5, null);
        $rate(self::T, 'Old GST 12%', 12, 'INR', 0);
    }

    /** @return array<string, string> */
    private function bearer(): array
    {
        return $this->headers($this->h(self::T, self::ADMIN));
    }

    /** @param array<string, mixed> $sell */
    private function make(array $sell, string $name = 'Plan'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/product-master', ['kind' => 'item', 'name' => $name, 'sell' => ['type' => 'product', 'unitPrice' => 100] + $sell], $this->bearer());
    }

    private function crmId(\Illuminate\Testing\TestResponse $created): int
    {
        return (int) $created->assertStatus(201)->json('data.sell.crmProductId');
    }

    private function stored(int $crmId): object
    {
        return DB::table('crm_products')->where('id', $crmId)->first();
    }

    // ================================================================== the writer

    public function test_a_currency_of_the_list_is_stored_in_upper_case(): void
    {
        $res = $this->make(['currency' => ' usd '])->assertStatus(201);

        $this->assertSame('USD', $res->json('data.sell.currency'));
        $this->assertSame('USD', $this->stored((int) $res->json('data.sell.crmProductId'))->currency);
    }

    public function test_a_currency_outside_the_list_is_refused_and_nothing_is_written(): void
    {
        $before = DB::table('crm_products')->count();

        foreach (['Rupees', 'XYZ', 'INRR', 'RS'] as $bad) {
            $this->make(['currency' => $bad], 'Bad ' . $bad)->assertStatus(422)->assertJson(['errors' => ['sell.currency' => ['Choose a currency from the list.']]]);
        }
        $this->make(['currency' => ['INR']], 'A list')->assertStatus(422)->assertJsonStructure(['errors' => ['sell.currency']]);

        $this->assertSame($before, DB::table('crm_products')->count());
        $this->assertSame(0, DB::table('g2g_product_master')->where('name', 'like', 'Bad %')->count());
    }

    public function test_a_new_row_with_no_currency_gets_the_organisations_default(): void
    {
        $this->assertSame('INR', $this->stored($this->crmId($this->make([], 'No currency')))->currency, 'no setting saved: INR');
        $this->assertSame('INR', $this->stored($this->crmId($this->make(['currency' => ''], 'Blank currency')))->currency);
        $this->assertSame('INR', $this->stored($this->crmId($this->make(['currency' => null], 'Null currency')))->currency);

        DB::table('tenant_setting')->insert(['sub_institute_id' => self::T, 'setting_key' => 'org.currency', 'setting_value' => 'EUR']);
        $this->assertSame('EUR', $this->stored($this->crmId($this->make([], 'Euro house')))->currency);

        DB::table('tenant_setting')->update(['setting_value' => 'Rupees']);
        $this->assertSame('INR', $this->stored($this->crmId($this->make([], 'Typed setting')))->currency, 'a setting outside the list is not a default');
    }

    public function test_an_old_free_text_currency_can_still_be_saved_but_only_as_it_is(): void
    {
        $id = $this->crmId($this->make(['currency' => 'USD'], 'Old row'));
        DB::table('crm_products')->where('id', $id)->update(['currency' => 'Rupees']);
        $master = (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('id');

        $this->putJson("/api/product-master/{$master}", ['sell' => ['unitPrice' => 250, 'currency' => 'Rupees']], $this->bearer())->assertOk();
        $this->assertEquals(['Rupees', 250], [$this->stored($id)->currency, $this->stored($id)->unit_price]);

        $this->putJson("/api/product-master/{$master}", ['sell' => ['unitPrice' => 260]], $this->bearer())->assertOk();
        $this->assertSame('Rupees', $this->stored($id)->currency, 'a write that does not mention the currency leaves it');

        foreach (['rupees', 'Rupee', 'Dollars'] as $other) {
            $this->putJson("/api/product-master/{$master}", ['sell' => ['currency' => $other]], $this->bearer())->assertStatus(422)->assertJson(['errors' => ['sell.currency' => ['Choose a currency from the list.']]]);
        }
        $this->putJson("/api/product-master/{$master}", ['sell' => ['currency' => 'GBP']], $this->bearer())->assertOk();
        $this->assertSame('GBP', $this->stored($id)->currency, 'and a catalogue code replaces it');
    }

    public function test_a_tax_rate_for_another_currency_is_refused_on_create(): void
    {
        $this->make(['currency' => 'USD', 'taxRateId' => $this->inrRate], 'Mismatch')->assertStatus(422)
            ->assertJson(['errors' => ['sell.taxRateId' => ['This tax rate is for INR. Choose a rate for USD, or one that is not tied to a currency.']]]);
        $this->assertSame(0, DB::table('crm_products')->where('name', 'Mismatch')->count());

        // fits: the same currency (any case), a rate with no currency, a rate for the default currency on a row that names none
        $this->make(['currency' => 'inr', 'taxRateId' => $this->inrRate], 'Fits one')->assertStatus(201);
        $this->make(['currency' => 'USD', 'taxRateId' => $this->anyRate], 'Fits any')->assertStatus(201);
        $this->make(['taxRateId' => $this->inrRate], 'Fits default')->assertStatus(201);
        $this->make(['currency' => 'USD', 'taxRateId' => $this->usdRate], 'Fits usd')->assertStatus(201);
        $this->make(['currency' => 'EUR', 'taxRateId' => $this->usdRate], 'Euro and usd tax')->assertStatus(422);
        $this->make(['taxRateId' => $this->usdRate], 'Default INR with a usd rate')->assertStatus(422)
            ->assertJson(['errors' => ['sell.taxRateId' => ['This tax rate is for USD. Choose a rate for INR, or one that is not tied to a currency.']]]);
        $this->make(['taxRateId' => $this->otherRate], 'Not ours')->assertStatus(422)->assertJson(['errors' => ['sell.taxRateId' => ['That tax rate was not found.']]]);
    }

    public function test_a_tax_rate_and_a_currency_are_checked_on_update_whichever_of_the_two_changes(): void
    {
        $id = $this->crmId($this->make(['currency' => 'INR', 'taxRateId' => $this->inrRate], 'Rupee plan'));
        $master = (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('id');
        $put = fn (array $sell) => $this->putJson("/api/product-master/{$master}", ['sell' => $sell], $this->bearer());

        // the currency changes under a rate that does not fit it
        $put(['currency' => 'USD'])->assertStatus(422)->assertJson(['errors' => ['sell.taxRateId' => ['This tax rate is for INR. Choose a rate for USD, or one that is not tied to a currency.']]]);
        // the rate changes to one that does not fit the stored currency
        $put(['taxRateId' => $this->usdRate])->assertStatus(422)->assertJson(['errors' => ['sell.taxRateId' => ['This tax rate is for USD. Choose a rate for INR, or one that is not tied to a currency.']]]);
        $this->assertSame(['INR', $this->inrRate], [$this->stored($id)->currency, (int) $this->stored($id)->tax_rate_id], 'nothing was written');

        // both at once, so the pair fits; a rate with no currency; no rate at all
        $put(['currency' => 'USD', 'taxRateId' => $this->usdRate])->assertOk();
        $this->assertSame(['USD', $this->usdRate], [$this->stored($id)->currency, (int) $this->stored($id)->tax_rate_id]);
        $put(['currency' => 'GBP', 'taxRateId' => null])->assertOk();
        $put(['taxRateId' => $this->anyRate])->assertOk();
        $put(['currency' => 'JPY'])->assertOk();
        $this->assertSame(['JPY', $this->anyRate], [$this->stored($id)->currency, (int) $this->stored($id)->tax_rate_id]);
    }

    public function test_a_row_with_an_old_mismatch_can_still_be_renamed_or_repriced(): void
    {
        $id = $this->crmId($this->make(['currency' => 'USD'], 'Legacy pair'));
        DB::table('crm_products')->where('id', $id)->update(['tax_rate_id' => $this->inrRate]);   // an old pair that no rule of today would let in
        $master = (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('id');

        $this->putJson("/api/product-master/{$master}", ['sell' => ['unitPrice' => 900, 'sku' => 'LEG-1']], $this->bearer())->assertOk();
        $this->assertEquals(900, $this->stored($id)->unit_price);
    }

    // ================================================================== the CRM Products form

    public function test_the_crm_form_has_the_same_rules_on_create_and_update(): void
    {
        $base = ['itemType' => 'product', 'name' => 'Crm plan', 'assignedTo' => 1, 'unitPrice' => 10];

        $this->postJson('/api/crm/products', $base + ['currency' => 'Rupees'], $this->bearer())->assertStatus(422)->assertJsonPath('message', 'Choose a currency from the list.');
        $this->postJson('/api/crm/products', $base + ['currency' => 'USD', 'taxRateId' => $this->inrRate], $this->bearer())->assertStatus(422)
            ->assertJsonPath('message', 'This tax rate is for INR. Choose a rate for USD, or one that is not tied to a currency.');
        $this->assertSame(0, DB::table('crm_products')->where('name', 'Crm plan')->count());

        $id = (int) $this->postJson('/api/crm/products', $base + ['currency' => 'eur'], $this->bearer())->assertStatus(201)->json('data.id');
        $this->assertSame('EUR', $this->stored($id)->currency);
        $none = (int) $this->postJson('/api/crm/products', ['name' => 'Crm none'] + $base, $this->bearer())->assertStatus(201)->json('data.id');
        $this->assertSame('INR', $this->stored($none)->currency, 'the default');

        DB::table('crm_products')->where('id', $id)->update(['currency' => 'Rupees']);
        $this->putJson("/api/crm/products/{$id}", ['currency' => 'Rupees', 'unitPrice' => 11], $this->bearer())->assertOk();
        $this->putJson("/api/crm/products/{$id}", ['currency' => 'Rupee'], $this->bearer())->assertStatus(422)->assertJsonPath('message', 'Choose a currency from the list.');
        $this->putJson("/api/crm/products/{$id}", ['currency' => 'USD', 'taxRateId' => $this->inrRate], $this->bearer())->assertStatus(422);
        $this->putJson("/api/crm/products/{$id}", ['currency' => 'USD', 'taxRateId' => $this->usdRate], $this->bearer())->assertOk();
        $this->assertSame(['USD', $this->usdRate], [$this->stored($id)->currency, (int) $this->stored($id)->tax_rate_id]);
    }

    public function test_the_import_reports_a_bad_currency_or_tax_rate_per_row_and_saves_the_good_ones(): void
    {
        $rows = [
            ['name' => 'Good one', 'assignedTo' => 1, 'unitPrice' => 5, 'currency' => 'usd', 'taxRate' => 'us SALES tax'],
            ['name' => 'Bad currency', 'assignedTo' => 1, 'currency' => 'Rupees'],
            ['name' => 'No such rate', 'assignedTo' => 1, 'currency' => 'INR', 'taxRate' => 'GST 99%'],
            ['name' => 'Switched off rate', 'assignedTo' => 1, 'taxRate' => 'Old GST 12%'],
            ['name' => 'Rate of another currency', 'assignedTo' => 1, 'currency' => 'USD', 'taxRate' => 'GST 18%'],
            ['name' => 'Rate of another organisation', 'assignedTo' => 1, 'taxRate' => 'Elsewhere'],
            ['name' => 'Default currency', 'assignedTo' => 1, 'taxRate' => 'GST 18%'],
            ['name' => 'Number of a rate', 'assignedTo' => 1, 'taxRateId' => $this->anyRate, 'currency' => 'GBP'],
            ['name' => 'Bad rate number', 'assignedTo' => 1, 'taxRateId' => $this->otherRate],
        ];

        $res = $this->postJson('/api/crm/products/import', ['itemType' => 'product', 'rows' => $rows], $this->bearer())->assertOk();

        $results = collect($res->json('data.results'));
        $this->assertSame([true, false, false, false, false, false, true, true, false], $results->pluck('ok')->all());
        $this->assertSame('Choose a currency from the list.', $results[1]['reason']);
        $this->assertSame('The tax rate "GST 99%" is not one of your active tax rates.', $results[2]['reason']);
        $this->assertStringContainsString('not one of your active tax rates', $results[3]['reason']);
        $this->assertSame('This tax rate is for INR. Choose a rate for USD, or one that is not tied to a currency.', $results[4]['reason']);
        $this->assertStringContainsString('not one of your active tax rates', $results[5]['reason']);
        $this->assertSame('That tax rate was not found.', $results[8]['reason']);

        $good = DB::table('crm_products')->where('name', 'Good one')->first();
        $this->assertSame(['USD', $this->usdRate], [$good->currency, (int) $good->tax_rate_id]);
        $default = DB::table('crm_products')->where('name', 'Default currency')->first();
        $this->assertSame(['INR', $this->inrRate], [$default->currency, (int) $default->tax_rate_id]);
        $this->assertSame(['GBP', $this->anyRate], [DB::table('crm_products')->where('name', 'Number of a rate')->value('currency'), (int) DB::table('crm_products')->where('name', 'Number of a rate')->value('tax_rate_id')]);
        $this->assertSame(0, DB::table('crm_products')->whereIn('name', ['Bad currency', 'No such rate', 'Switched off rate', 'Rate of another currency', 'Rate of another organisation', 'Bad rate number'])->count());
    }

    public function test_the_export_carries_the_tax_rate_and_its_percentage(): void
    {
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Taxed', 'assignedTo' => 1, 'currency' => 'INR', 'taxRateId' => $this->inrRate], $this->bearer())->assertStatus(201);
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Untaxed', 'assignedTo' => 1], $this->bearer())->assertStatus(201);

        $csv = $this->get('/api/crm/products/export?itemType=product', $this->bearer())->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $header = $lines[0];
        $this->assertSame(['Currency', 'Tax Rate', 'Tax %'], array_slice($header, array_search('Currency', $header), 3));
        $byName = [];
        foreach (array_slice($lines, 1) as $line) {
            $byName[$line[0]] = array_combine($header, $line);
        }
        $this->assertSame(['INR', 'GST 18%'], [$byName['Taxed']['Currency'], $byName['Taxed']['Tax Rate']]);
        $this->assertEquals(18.0, (float) $byName['Taxed']['Tax %'], 'the database writes the number its own way (18.000 on MariaDB)');
        $this->assertSame(['', ''], [$byName['Untaxed']['Tax Rate'], $byName['Untaxed']['Tax %']]);
    }

    // ================================================================== the pure rules

    public function test_the_pure_rules_of_the_class(): void
    {
        $this->assertNull(CatalogueFields::currencyError(null));
        $this->assertNull(CatalogueFields::currencyError('  '));
        $this->assertNull(CatalogueFields::currencyError('eur'));
        $this->assertNull(CatalogueFields::currencyError('Old text', 'Old text'));
        $this->assertSame('Choose a currency from the list.', CatalogueFields::currencyError('Old text', 'old text'));
        $this->assertSame('Choose a currency from the list.', CatalogueFields::currencyError(true));
        $this->assertSame('EUR', CatalogueFields::currencyToStore(' eur '));
        $this->assertSame('Old text', CatalogueFields::currencyToStore('Old text ', 'Old text'));
        $this->assertNull(CatalogueFields::currencyToStore(''));
        $this->assertNull(CatalogueFields::taxCurrencyError(null, 'USD'));
        $this->assertNull(CatalogueFields::taxCurrencyError('INR', null));
        $this->assertNull(CatalogueFields::taxCurrencyError('inr', 'INR'));
        $this->assertSame('This tax rate is for INR. Choose a rate for USD, or one that is not tied to a currency.', CatalogueFields::taxCurrencyError('INR', 'usd'));
    }
}
