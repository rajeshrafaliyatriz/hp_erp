<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Detectors\Platform\IntegrationStatusDetector;
use App\Domain\Intelligence\Detectors\Platform\PlatformAccess;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;

class IntegrationStatusDetectorTest extends PlatformTestCase
{
    private function detector(): IntegrationStatusDetector
    {
        return new IntegrationStatusDetector($this->db(), $this->thresholds());
    }

    private function admin(int $tenant = 1)
    {
        return $this->platformCaller('administrator', PlatformAccess::platformModuleLinks(), $tenant);
    }

    public function test_error_not_configured_and_untested_integrations_are_reported_per_status(): void
    {
        $this->addIntegration(1, 'sms', 'error');
        $this->addIntegration(1, 'whatsapp', 'not_configured', null);
        $this->addIntegration(1, 'webhook', 'unknown', null);
        $this->addIntegration(1, 'smtp', 'configured', '2026-11-15 00:00:00');       // tested recently: healthy
        $this->addIntegration(1, 'crm_sync', 'configured', '2026-06-01 00:00:00');   // tested long ago
        $this->addIntegration(1, 'other', 'configured', null);                         // never tested
        $this->addIntegration(92, 'sms', 'error');                                     // another tenant

        $result = $this->detector()->detect($this->admin(1), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame(6, $result->coverage->observed, 'only this tenant\'s rows');
        $byKey = [];
        foreach ($result->insights as $i) {
            $byKey[substr($i->key, strlen('platform.integration_status:'), strpos($i->key, ':t1:') - strlen('platform.integration_status:'))] = $i;
        }
        $this->assertSame(['error', 'not_configured', 'untested'], array_keys($byKey));
        $this->assertSame('high', $byKey['error']->severity);
        $this->assertSame('attention', $byKey['not_configured']->severity);
        $this->assertSame('info', $byKey['untested']->severity);
        $this->assertSame(1, $this->evidenceByLabel($byKey['error'])['Failed their last test']['value']);
        $this->assertSame(2, $this->evidenceByLabel($byKey['not_configured'])['Saved but not configured']['value']);
        $this->assertArrayHasKey('In error: sms', $this->evidenceByLabel($byKey['error']));
        $this->assertSame('/platform-services/integration', $byKey['error']->deepLink);
    }

    public function test_no_secret_or_free_text_column_is_ever_selected_or_serialised(): void
    {
        $this->addIntegration(1, 'sms', 'error');
        $this->addIntegration(1, 'smtp', 'configured');

        $result = null;
        $sql = $this->sqlOf(function () use (&$result) {
            $result = $this->detector()->detect($this->admin(), $this->clock());
        });
        $all = strtolower(implode("\n", $sql));

        foreach (['config', 'last_test_message', 'created_by', 'updated_by'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all, "the executed SQL names the column '{$forbidden}'");
        }
        $this->assertStringNotContainsString('select *', $all);
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $this->jsonOf($result), 'no secret reaches the response');
        $this->assertSame(['provider_key', 'status', 'last_tested_at'], IntegrationStatusDetector::SELECTED_COLUMNS);
    }

    public function test_a_hostile_provider_key_is_never_echoed(): void
    {
        $this->addIntegration(1, '<script>alert(1)</script>', 'error');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertStringNotContainsString('<script>', $this->jsonOf($result));
        $this->assertArrayHasKey('In error: integration', $this->evidenceByLabel($result->insights[0]));
    }

    public function test_a_tenant_with_no_saved_integrations_is_insufficient_not_not_connected(): void
    {
        $this->addIntegration(92, 'sms', 'error');

        $result = $this->detector()->detect($this->admin(1), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(0, $result->coverage->observed);
    }

    public function test_all_healthy_is_ok_and_empty(): void
    {
        $this->addIntegration(1, 'smtp', 'configured');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_a_missing_table_or_column_is_data_unavailable(): void
    {
        Schema::drop('g2g_integration_credentials');
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $this->detector()->detect($this->admin(), $this->clock())->state);

        Schema::create('g2g_integration_credentials', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('provider_key');
        });
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $this->detector()->detect($this->admin(), $this->clock())->state, 'a missing status column');
    }

    public function test_authorization_needs_a_platform_services_module_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator', ['/platform-services/event-bus'])));
        $this->assertTrue($this->detector()->authorizes($this->platformCaller('employee', ['/platform-services/workflow?module=hrms'])));
    }
}
