<?php

namespace Tests\Unit\Money;

use App\Domain\Money\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The currency catalogue (S16 contract section 2): twelve currencies, in the contract's order, and the lookups the writers and the documents use. */
class CurrenciesTest extends TestCase
{
    public function test_the_catalogue_is_the_twelve_currencies_of_the_contract(): void
    {
        $all = Currencies::all();

        $this->assertSame(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'JPY', 'CHF', 'SAR', 'ZAR'], array_column($all, 'code'));
        $this->assertSame(['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2, 'country' => 'IN'], $all[0]);
        $this->assertSame(0, Currencies::decimals('jpy'), 'the yen keeps no decimals');
        $this->assertSame(['₹', '$', '€', '£', 'د.إ', 'S$', 'A$', 'C$', '¥', 'CHF', '﷼', 'R'], array_column($all, 'symbol'));
    }

    public function test_a_code_is_found_whatever_its_case_and_a_legacy_value_is_not_in_the_list(): void
    {
        $this->assertSame('INR', Currencies::find(' inr ')['code']);
        $this->assertNull(Currencies::find('Rupees'));
        $this->assertFalse(Currencies::isCode('XYZ'));
        $this->assertFalse(Currencies::isCode(null));
        $this->assertSame('RUPEES', Currencies::normalise(' Rupees '));
        $this->assertNull(Currencies::normalise('  '));
        $this->assertNull(Currencies::normalise(['INR']));
    }

    public function test_an_unknown_code_is_shown_as_typed_and_keeps_two_decimals(): void
    {
        $this->assertSame('€', Currencies::symbol('eur'));
        $this->assertSame('RUPEES', Currencies::symbol('rupees'));
        $this->assertSame('', Currencies::symbol(null));
        $this->assertSame(2, Currencies::decimals('rupees'));
        $this->assertSame(2, Currencies::decimals(null));
    }

    public function test_the_default_is_the_organisations_own_currency_when_it_is_in_the_list_else_inr(): void
    {
        // No settings table: the fallback, never an error.
        $this->assertSame('INR', Currencies::defaultFor(5));

        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('setting_key', 100);
            $t->text('setting_value')->nullable();
        });
        $this->assertSame('INR', Currencies::defaultFor(5), 'nothing saved: the setting default is INR');

        DB::table('tenant_setting')->insert([
            ['sub_institute_id' => 5, 'setting_key' => 'org.currency', 'setting_value' => 'usd'],
            ['sub_institute_id' => 6, 'setting_key' => 'org.currency', 'setting_value' => 'Rupees'],
        ]);
        $this->assertSame('USD', Currencies::defaultFor(5));
        $this->assertSame('INR', Currencies::defaultFor(6), 'a value outside the list is not offered as a default');
        $this->assertSame('INR', Currencies::defaultFor(0));
    }
}
