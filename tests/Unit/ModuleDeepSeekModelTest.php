<?php

namespace Tests\Unit;

use App\Domain\AI\Configuration\ModuleDeepSeekModel;
use App\Domain\AI\Configuration\ModuleModelBindings;
use App\Domain\AI\Support\SchemaCache;
use Tests\TestCase;

/**
 * The model-safety guard in front of module model bindings (audit CRA-027).
 *
 * Pure checks: none of these touch the database, because the guard's job is to refuse
 * before anything is stored or sent.
 */
class ModuleDeepSeekModelTest extends TestCase
{
    private function guard(): ModuleDeepSeekModel
    {
        $schema = new SchemaCache();

        return new ModuleDeepSeekModel(new ModuleModelBindings($schema), $schema);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['deepseek.allowed_models' => ['deepseek-flash']]);
    }

    public function test_only_deepseek_can_be_chosen_for_a_deepseek_direct_capability(): void
    {
        $this->assertNotNull($this->guard()->refusal('gemini', 'gemini-3.6-flash'));
        $this->assertNotNull($this->guard()->refusal('openai', 'gpt-4o'));
        $this->assertNull($this->guard()->refusal('deepseek', 'deepseek-flash'));
    }

    public function test_an_unverified_model_is_refused_even_though_the_catalogue_offers_it(): void
    {
        $this->assertNotNull($this->guard()->refusal('deepseek', 'deepseek-reasoner'));
        $this->assertNotNull($this->guard()->refusal('deepseek', 'deepseek-v4-pro'));
    }

    public function test_no_model_named_means_use_the_configured_default(): void
    {
        $this->assertNull($this->guard()->refusal('deepseek', null));
        $this->assertNull($this->guard()->refusal('deepseek', '  '));
    }

    public function test_a_model_named_by_a_request_is_kept_only_when_allowed(): void
    {
        $this->assertSame('deepseek-flash', $this->guard()->sanitise(' deepseek-flash '));
        $this->assertNull($this->guard()->sanitise('deepseek-reasoner'));
        $this->assertNull($this->guard()->sanitise(null));
        $this->assertNull($this->guard()->sanitise(''));
    }

    public function test_only_the_three_deepseek_direct_capabilities_read_a_binding(): void
    {
        foreach (['lms_content_ai', 'eso_intelligence', 'assessment_ai'] as $capability) {
            $this->assertTrue(ModuleDeepSeekModel::supports($capability));
        }

        $this->assertFalse(ModuleDeepSeekModel::supports('conversational_ai'));
        $this->assertFalse(ModuleDeepSeekModel::supports('recruitment_ai'));
        $this->assertNull($this->guard()->modelFor('recruitment_ai', 3));
    }
}
