<?php

namespace Tests\Unit;

use App\Domain\Signals\Products\ProductCatalog;
use PHPUnit\Framework\TestCase;

/** A custom-product key made from any Product ID is valid: 3 to 20 characters, lower-case letters, digits and underscores, starting with a letter. */
class ProductKeyTest extends TestCase
{
    public function test_every_key_is_valid_and_ordinary_ones_are_unchanged(): void
    {
        foreach (['P3', 'p', '7', 'X-', 'G2G-01', 'EB-1', 'My Product-01', '01-ABC', 'A very long product identifier here', '---'] as $raw) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{2,19}$/', ProductCatalog::keyFrom($raw), $raw);
        }
        $this->assertSame('prod_p3', ProductCatalog::keyFrom('P3'));
        $this->assertSame('my_01', ProductCatalog::keyFrom('MY-01'));
        $this->assertSame('g2g_01', ProductCatalog::keyFrom('G2G-01'));
    }
}
