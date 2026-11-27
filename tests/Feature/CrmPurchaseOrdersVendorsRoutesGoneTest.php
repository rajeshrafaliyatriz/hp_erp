<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The CRM Purchase Orders and Vendors modules are gone (26 Nov 2026; Docs/agentic-crm/briefs/REMOVE-PURCHASE-ORDERS-VENDORS.md): `php artisan route:list --json` holds no CRM route under
 * `vendors` or `purchase-orders` and no controller of theirs, while the LMS vendors feature (a different one: training suppliers) is still routed and the neighbouring CRM documents are too.
 */
class CrmPurchaseOrdersVendorsRoutesGoneTest extends TestCase
{
    /** @return list<array{method: string, uri: string, action: string}> */
    private function routes(): array
    {
        $this->assertSame(0, Artisan::call('route:list', ['--json' => true]));
        $routes = json_decode(Artisan::output(), true);
        $this->assertIsArray($routes);
        $this->assertNotSame([], $routes);

        return array_map(fn (array $r) => ['method' => (string) $r['method'], 'uri' => (string) $r['uri'], 'action' => (string) $r['action']], $routes);
    }

    public function test_no_crm_route_is_left_for_vendors_or_purchase_orders(): void
    {
        $left = array_values(array_filter($this->routes(), fn (array $r) => preg_match('#^api/crm/(vendors|purchase-orders)(/|$)#', $r['uri']) === 1));

        $this->assertSame([], array_map(fn (array $r) => $r['method'] . ' ' . $r['uri'], $left), 'no vendors or purchase-orders route under /api/crm');
    }

    public function test_no_route_points_at_a_controller_of_the_removed_modules(): void
    {
        $left = array_values(array_filter($this->routes(), fn (array $r) => str_contains($r['action'], 'CrmVendorController') || str_contains($r['action'], 'CrmPurchaseOrderController')));

        $this->assertSame([], array_map(fn (array $r) => $r['method'] . ' ' . $r['uri'], $left));
        // The file, not class_exists(): a Composer classmap that was built before the removal still lists the class and would make the check a warning.
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/Crm/CrmVendorController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/Crm/CrmPurchaseOrderController.php'));
    }

    public function test_the_lms_vendors_feature_and_the_other_crm_documents_are_still_routed(): void
    {
        $uris = array_column($this->routes(), 'uri');

        $this->assertContains('api/lms/governance/vendors', $uris, 'the LMS vendors (training suppliers) are a different feature and stay');
        $this->assertContains('api/lms/governance/vendors/{id}', $uris);
        foreach (['quotes', 'sales-orders', 'invoices'] as $stays) {
            $this->assertContains("api/crm/{$stays}", $uris, "{$stays} stays");
            $this->assertContains("api/crm/{$stays}/{id}/line-items", $uris, "{$stays} keeps its line items");
        }
    }
}
