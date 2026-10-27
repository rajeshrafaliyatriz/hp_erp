<?php

namespace Tests\Unit;

use App\Domain\Signals\Products\ProductCatalog;
use Tests\TestCase;

/**
 * The built-in products behave exactly as before whether or not an organisation has products of its own. These checks read configuration only:
 * with no g2g_signal_products table (the migration is prepared, not run) there are simply no custom products.
 */
class ProductCatalogBuiltInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProductCatalog::forgetCustom();
    }

    public function test_the_built_in_products_are_unchanged_and_the_same_for_every_organisation(): void
    {
        $builtIn = ProductCatalog::definitions();
        $this->assertSame(['scholarclone', 'enterprise_brain', 'g2g'], array_keys($builtIn));
        $this->assertSame($builtIn, ProductCatalog::definitionsFor(null));
        $this->assertSame($builtIn, ProductCatalog::definitionsFor(1));
        $this->assertSame($builtIn, ProductCatalog::definitionsFor(2), 'without custom products every organisation sees the same list');
    }

    public function test_normalise_gives_the_same_answer_with_or_without_an_organisation_for_every_built_in_name(): void
    {
        foreach (['scholarclone' => ['scholarclone', 'scholar', 'ScholarClone'], 'enterprise_brain' => ['eb', 'Enterprise Brain'], 'g2g' => ['g2g', 'G2G']] as $key => $names) {
            foreach ($names as $name) {
                $this->assertSame($key, ProductCatalog::normalise($name), $name);
                $this->assertSame($key, ProductCatalog::normalise($name, 7), $name);
            }
        }
        $this->assertNull(ProductCatalog::normalise('not_a_product', 7));
        $this->assertNull(ProductCatalog::normalise('', 7));
    }

    public function test_business_fit_and_product_lines_of_built_in_products_are_unchanged(): void
    {
        $this->assertSame('scholar', ProductCatalog::businessFit('scholarclone'));
        $this->assertSame('eb', ProductCatalog::businessFit('enterprise_brain', 3));
        $this->assertTrue(ProductCatalog::isProductLine('scholar'));
        $this->assertTrue(ProductCatalog::isProductLine('eb', 3));
        $this->assertFalse(ProductCatalog::isProductLine('some_custom_line'));
    }

    public function test_an_organisation_with_no_custom_products_table_still_resolves_built_ins(): void
    {
        $this->assertSame([], ProductCatalog::customFor(11));
    }

    public function test_generation_is_off_for_an_organisations_own_product_until_the_gate_accepts_it_and_never_affects_built_ins(): void
    {
        foreach (ProductCatalog::definitions() as $def) {
            $this->assertNull(ProductCatalog::generationBlock($def));
        }
        $custom = ['label' => 'Payroll Cloud', 'business_fit' => 'payroll_cloud', 'custom' => true];
        $this->assertStringContainsString('not switched on', (string) ProductCatalog::generationBlock($custom));
        config(['signals.custom_products.generate' => true]);
        $this->assertNull(ProductCatalog::generationBlock($custom));
    }
}
