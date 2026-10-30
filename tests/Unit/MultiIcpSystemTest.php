<?php

namespace Tests\Unit;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductIcpService;
use App\Domain\Signals\Products\SubProductIcp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultiIcpSystemTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('g2g_product_offers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('offer_id', 40);
            $t->string('name', 120);
            $t->string('parent_product', 80);
            $t->string('readiness_status', 40)->default('in_development');
            $t->text('what_it_does')->nullable();
            $t->text('modules_components')->nullable();
            $t->text('primary_segments')->nullable();
            $t->text('needs_solved')->nullable();
            $t->text('trigger_signals')->nullable();
            $t->text('proof_points')->nullable();
            $t->string('pricing_model', 80)->nullable();
            $t->string('typical_deal_band', 80)->nullable();
            $t->string('deployment_model', 80)->nullable();
            $t->string('implementation_effort', 80)->nullable();
            $t->string('delivery_owner', 80)->nullable();
            $t->text('notes')->nullable();
            $t->text('icp_config')->nullable();
            $t->timestamps();
        });

        Schema::create('g2g_icp_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('key', 80);
            $t->string('name', 120);
            $t->string('product_line', 80)->nullable();
            $t->string('status', 40)->default('draft');
            $t->boolean('is_default')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->text('config')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('g2g_icp_profile_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('icp_profile_id');
            $t->unsignedInteger('version');
            $t->text('config')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamps();
        });
    }

    public function test_every_product_supports_three_independent_icp_models_simultaneously(): void
    {
        $offer = new ProductOffer([
            'sub_institute_id' => self::T,
            'offer_id' => 'OFF-01',
            'name' => 'Omni Platform',
            'parent_product' => 'Scholar K-12',
            'icp_config' => [
                'b2b' => [
                    'label' => 'Omni Platform B2B ICP',
                    'customer_model' => 'b2b',
                    'industry_label' => 'Private Schools and Colleges',
                    'triggers' => ['t1' => ['source' => 'news', 'pain' => 'expansion']],
                    'queries' => [['id' => 'q1', 'q' => 'schools expanding in Gujarat']],
                ],
                'b2c' => [
                    'label' => 'Omni Platform B2C ICP',
                    'customer_model' => 'b2c',
                    'industry_label' => 'Parents and Independent Learners',
                    'triggers' => ['t1' => ['source' => 'social', 'pain' => 'exam prep']],
                    'queries' => [['id' => 'q2', 'q' => 'parents seeking personalized tutors']],
                ],
                'b2g' => [
                    'label' => 'Omni Platform B2G ICP',
                    'customer_model' => 'b2g',
                    'industry_label' => 'State Education Boards and Public Tenders',
                    'triggers' => ['t1' => ['source' => 'tender', 'pain' => 'digital procurement']],
                    'queries' => [['id' => 'q3', 'q' => 'education tender issued Gujarat']],
                ],
            ],
        ]);

        $all = SubProductIcp::allConfigsFor(self::T, $offer);
        $this->assertNotNull($all['b2b']);
        $this->assertNotNull($all['b2c']);
        $this->assertNotNull($all['b2g']);

        $this->assertSame('Private Schools and Colleges', $all['b2b']['industry_label']);
        $this->assertSame('Parents and Independent Learners', $all['b2c']['industry_label']);
        $this->assertSame('State Education Boards and Public Tenders', $all['b2g']['industry_label']);

        // Individual model checks
        $this->assertEmpty(SubProductIcp::problems($all['b2b'], 'b2b'));
        $this->assertEmpty(SubProductIcp::problems($all['b2c'], 'b2c'));
        $this->assertEmpty(SubProductIcp::problems($all['b2g'], 'b2g'));
    }

    public function test_activating_one_customer_model_does_not_deactivate_other_models_for_the_same_product(): void
    {
        $service = app(ProductIcpService::class);

        $b2b = IcpProfile::create([
            'sub_institute_id' => self::T,
            'key' => 'eb_icp',
            'name' => 'Enterprise Brain B2B',
            'product_line' => 'eb',
            'status' => 'draft',
            'is_default' => false,
            'version' => 1,
            'config' => ['customer_model' => 'b2b', 'label' => 'EB B2B'],
        ]);

        $b2g = IcpProfile::create([
            'sub_institute_id' => self::T,
            'key' => 'eb_icp_b2g',
            'name' => 'Enterprise Brain B2G',
            'product_line' => 'eb',
            'status' => 'draft',
            'is_default' => false,
            'version' => 1,
            'config' => ['customer_model' => 'b2g', 'label' => 'EB B2G'],
        ]);

        // Activate B2B
        $service->activateExclusive($b2b, 'b2b');
        $this->assertSame('active', $b2b->fresh()->status);

        // Activate B2G - should leave B2B active!
        $service->activateExclusive($b2g, 'b2g');
        $this->assertSame('active', $b2g->fresh()->status);
        $this->assertSame('active', $b2b->fresh()->status, 'B2B must remain active when B2G is activated');
    }

    public function test_multiline_and_unicode_text_preservation(): void
    {
        $multilineGuidance = "Line 1: Target K-12 institutions in Tier 2 cities.\nLine 2: Focus on CBSE & ICSE schools.\nLine 3: High priority for schools with >1000 students — 🌟 विशेष प्राथमिकता.";
        $offer = new ProductOffer([
            'sub_institute_id' => self::T,
            'offer_id' => 'OFF-UNI',
            'name' => 'Unicode Suite',
            'parent_product' => 'Scholar K-12',
            'icp_config' => [
                'b2b' => [
                    'label' => 'Unicode B2B',
                    'customer_model' => 'b2b',
                    'industry_label' => "K-12 Educational Institutions — गुजरात અને મહારાષ્ટ્ર",
                    'guidance' => $multilineGuidance,
                    'triggers' => ['t1' => ['source' => 'news', 'pain' => 'expansion']],
                    'queries' => [['id' => 'q1', 'q' => 'CBSE school new campus']],
                ],
            ],
        ]);

        $config = SubProductIcp::configFor(self::T, $offer, 'b2b');
        $this->assertNotNull($config);
        $this->assertSame($multilineGuidance, $config['guidance']);
        $this->assertStringContainsString('विशेष प्राथमिकता', $config['guidance']);
        $this->assertStringContainsString('गुजरात', $config['industry_label']);
    }
}
