<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Static checks of the prepared custom-product migration and the read-only measurement command. Nothing here touches a database. */
class CustomProductReadinessTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_the_migration_is_additive_idempotent_and_tenant_unique(): void
    {
        $m = $this->src('database/migrations/2026_12_05_090000_create_g2g_signal_products_table.php');
        $this->assertStringContainsString("SHOW TABLES LIKE 'g2g_signal_products'", $m, 'skips when the table exists');
        $this->assertStringContainsString("Schema::hasTable('g2g_signal_products')", $m);
        $this->assertStringNotContainsString('dropIfExists', $m);
        $this->assertStringNotContainsString('ALTER TABLE', $m);
        $this->assertStringNotContainsString('DROP ', $m);
        $this->assertStringContainsString('PREPARED, NOT RUN', $m);
        foreach (['tenant_key', 'tenant_fit', 'tenant_line'] as $unique) {
            $this->assertSame(2, substr_count($m, "g2g_signal_products_{$unique}"), "a unique index {$unique} exists for MariaDB and for the portable path");
        }
        $this->assertStringContainsString('`key` VARCHAR(20)', $m, 'the key is the 20-character business_fit');
        $this->assertStringContainsString('`business_fit` VARCHAR(20)', $m);
        $this->assertStringContainsString('TEXT NULL', $m, 'no JSON column on MariaDB 10.1');
    }

    public function test_every_custom_product_read_is_scoped_to_the_organisation(): void
    {
        $c = $this->src('app/Domain/Signals/Products/ProductCatalog.php');
        $this->assertStringContainsString("CustomProduct::where('sub_institute_id', \$tenantId)", $c);
        $this->assertSame(0, preg_match('/CustomProduct::(all|query\(\)->get|get)\(/', $c), 'no unscoped read');
        $ctl = $this->src('app/Http/Controllers/Api/Signals/ProductIcpController.php');
        $this->assertStringContainsString("createCustom(\$identity['sub_institute_id']", $ctl, 'the organisation comes from the token');
        $this->assertStringNotContainsString("input('sub_institute_id'", $ctl);
    }

    public function test_the_creation_route_is_admin_only(): void
    {
        $routes = $this->src('routes/api.php');
        $a = strpos($routes, "Route::prefix('signals/icp')");
        $b = strpos($routes, "Route::post('/products', [\$productIcp, 'create'])");
        $this->assertNotFalse($a);
        $this->assertGreaterThan($a, $b);
        $this->assertStringContainsString("['api.token', 'profile:admin']", substr($routes, $a, 120));
    }

    public function test_generation_for_custom_products_stays_behind_the_switch_and_the_gate_extension_is_runtime_only(): void
    {
        $c = $this->src('app/Domain/Signals/Products/ProductCatalog.php');
        $this->assertStringContainsString("config('signals.custom_products.generate', false)", $c);
        $this->assertStringContainsString('if (($note = self::generationBlock($def)) !== null)', $c, 'resolve() refuses before any provider call');
        $r = $this->src('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertSame(2, substr_count($r, 'QualificationGate::allowOwnProductFit('));
    }

    public function test_the_yield_report_command_is_read_only(): void
    {
        $cmd = $this->src('app/Console/Commands/SignalsYieldReport.php');
        foreach (['->insert', '->update(', '->delete(', '->truncate', 'Schema::create', 'Schema::drop', 'DB::statement', 'DB::unprepared', 'Http::', '::dispatch', 'Artisan::call'] as $write) {
            $this->assertStringNotContainsString($write, $cmd, $write);
        }
        $this->assertStringContainsString("DB::table('g2g_research_runs')->where('sub_institute_id', \$tenant)", $cmd);
        $this->assertStringContainsString("DB::table('g2g_company_opportunities')->where('sub_institute_id', \$tenant)", $cmd);
    }
}
