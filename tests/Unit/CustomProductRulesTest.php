<?php

namespace Tests\Unit;

use App\Domain\Signals\Products\CustomProductRules;
use App\Domain\Signals\Products\ProductCatalog;
use PHPUnit\Framework\TestCase;

/** What a new top-level product's key and name must satisfy. Pure: no database. */
class CustomProductRulesTest extends TestCase
{
    /** The built-in products, in the shape of config/signals_products.php. */
    private function builtIn(): array
    {
        return [
            'scholarclone' => ['label' => 'ScholarClone', 'business_fit' => 'scholar', 'portfolio_names' => ['Scholar K-12'], 'icp_product_line' => 'scholar'],
            'enterprise_brain' => ['label' => 'Enterprise Brain', 'business_fit' => 'eb', 'portfolio_names' => ['EB'], 'icp_product_line' => 'eb'],
            'g2g' => ['label' => 'G2G', 'business_fit' => 'g2g', 'portfolio_names' => ['G2G'], 'icp_product_line' => 'g2g'],
        ];
    }

    public function test_a_clean_key_and_name_are_accepted(): void
    {
        $this->assertSame([], CustomProductRules::problems('hr_suite', 'HR Suite', $this->builtIn(), []));
    }

    public function test_invalid_keys_are_refused(): void
    {
        foreach (['', 'ab', 'HR Suite', '1suite', 'a-b-c', str_repeat('a', 21), 'has space', "x'; drop"] as $key) {
            $this->assertNotSame([], CustomProductRules::problems($key, 'Some product', $this->builtIn(), []), "'{$key}' should be refused");
        }
        $this->assertSame([], CustomProductRules::problems(str_repeat('a', 20), 'Twenty', $this->builtIn(), []), '20 characters fit the business_fit column');
    }

    public function test_reserved_words_are_refused(): void
    {
        foreach (['all', 'unassigned', 'none'] as $key) {
            $this->assertContains('key: this word is reserved', CustomProductRules::problems($key, 'Reserved word', $this->builtIn(), []));
        }
    }

    public function test_a_built_in_product_can_never_be_overridden_by_key_fit_or_icp_line(): void
    {
        foreach (['g2g', 'scholarclone', 'enterprise_brain', 'scholar', 'eb', 'EB', 'G2G'] as $key) {
            $this->assertContains('key: already used by a built-in product', CustomProductRules::problems(strtolower($key), 'Brand new name', $this->builtIn(), []), $key);
        }
    }

    public function test_a_name_that_belongs_to_a_built_in_product_or_group_is_refused(): void
    {
        foreach (['ScholarClone', 'enterprise brain', 'Scholar K-12', 'EB', 'g2g'] as $label) {
            $this->assertContains('label: another product already has this name', CustomProductRules::problems('fresh_key', $label, $this->builtIn(), []), $label);
        }
    }

    public function test_duplicate_keys_and_names_within_the_organisation_are_refused(): void
    {
        $mine = ['hr_suite' => ['label' => 'HR Suite']];
        $this->assertContains('key: you already have a product with this key', CustomProductRules::problems('hr_suite', 'Another', $this->builtIn(), $mine));
        $this->assertContains('label: another product already has this name', CustomProductRules::problems('hr_two', 'hr suite', $this->builtIn(), $mine));
    }

    public function test_another_organisations_products_are_not_in_the_list_so_they_never_block_this_one(): void
    {
        // The caller passes ONLY the signed-in organisation's products: the same key is free for a different organisation.
        $this->assertSame([], CustomProductRules::problems('hr_suite', 'HR Suite', $this->builtIn(), []));
    }

    public function test_the_label_must_fit_the_portfolio_group_column(): void
    {
        $this->assertNotSame([], CustomProductRules::problems('long_one', str_repeat('x', 51), $this->builtIn(), []));
        $this->assertNotSame([], CustomProductRules::problems('short_one', 'x', $this->builtIn(), []));
    }

    public function test_every_consumer_that_validates_a_product_asks_for_the_organisations_products(): void
    {
        $root = dirname(__DIR__, 2) . '/app/';
        foreach ([
            'Http/Controllers/Api/Signals/OpportunityController.php', 'Http/Controllers/Api/Signals/IngestionController.php',
            'Http/Controllers/Api/Signals/ProductIcpController.php', 'Http/Controllers/Api/Portfolio/ProductController.php',
            'Domain/Portfolio/MatchingEngine.php', 'Domain/Signals/Products/ProductIcpService.php',
        ] as $file) {
            $src = file_get_contents($root . $file);
            $this->assertSame(0, preg_match('/ProductCatalog::definitions\(\)/', $src), "{$file} still reads only the built-in products");
            foreach (preg_split('/\R/', $src) as $line) {
                if (str_contains($line, 'ProductCatalog::normalise(')) {
                    $this->assertTrue(str_contains($line, 'tenantId') || str_contains($line, 'sub_institute_id'), "{$file} normalises a product without the organisation: " . trim($line));
                }
            }
        }
        $this->assertTrue(method_exists(ProductCatalog::class, 'definitionsFor'));
    }
}
