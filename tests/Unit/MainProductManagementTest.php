<?php

namespace Tests\Unit;

use App\Domain\Signals\Products\ProductCatalog;
use PHPUnit\Framework\TestCase;

class MainProductManagementTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_product_catalog_has_crud_and_dependency_methods(): void
    {
        $c = $this->src('app/Domain/Signals/Products/ProductCatalog.php');
        $this->assertStringContainsString('public function updateProduct(', $c);
        $this->assertStringContainsString('public function productDependencies(', $c);
        $this->assertStringContainsString('public function deleteProduct(', $c);
        $this->assertStringContainsString("'status' => \$def['status'] ?? 'active'", $c);
        $this->assertStringContainsString("'description' => \$def['description'] ?? null", $c);
        $this->assertStringContainsString("'category' => \$def['category'] ?? null", $c);
    }

    public function test_controller_has_update_and_dependencies_endpoints(): void
    {
        $ctl = $this->src('app/Http/Controllers/Api/Signals/ProductIcpController.php');
        $this->assertStringContainsString('public function update(', $ctl);
        $this->assertStringContainsString('public function dependencies(', $ctl);
        $this->assertStringContainsString('public function destroy(', $ctl);
    }

    public function test_routes_expose_put_and_dependencies_endpoints(): void
    {
        $r = $this->src('routes/api.php');
        $this->assertStringContainsString("Route::put('/products/{product}', [\$productIcp, 'update'])", $r);
        $this->assertStringContainsString("Route::get('/products/{product}/dependencies', [\$productIcp, 'dependencies'])", $r);
    }
}

