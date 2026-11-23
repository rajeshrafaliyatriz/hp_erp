<?php

namespace Tests\Feature;

use App\Domain\Portfolio\OfferIcpSync;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Products\SubProductIcp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The ICP sync that used to be a private method of PortfolioController (S15 plan section 2.2), called directly. The Portfolio endpoints still reach it
 * through the controller and their own suites (ProductPortfolioIcpTest, SubProductIcpOwnershipTest) are the proof that nothing changed there; these tests are
 * what the one product writer relies on when it calls the service without an HTTP request: what rows it writes, what it leaves alone, what it does on a host
 * that lacks a table.
 */
class OfferIcpSyncTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_220000_create_g2g_portfolio_and_partners_tables', '2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers',
            '2026_10_27_200000_add_icp_config_to_g2g_product_offers_table', '2026_10_14_100000_create_g2g_icp_profiles_tables',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
    }

    private function offer(int $tenant = self::T, string $offerId = 'P1', string $name = 'Scholar One'): ProductOffer
    {
        return ProductOffer::create([
            'sub_institute_id' => $tenant, 'offer_id' => $offerId, 'name' => $name, 'parent_product' => 'Scholar K-12', 'readiness_status' => 'In development', 'is_user_edited' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function config(array $over = []): array
    {
        return $over + ['target_segments' => ['K12-PVT'], 'buyer_roles' => ['Principal']];
    }

    private function sync(ProductOffer $offer, array $payload, int $tenant = self::T, ?int $user = 7): void
    {
        (new OfferIcpSync)->sync($tenant, $user, $offer, $payload);
    }

    public function test_it_creates_one_active_profile_per_customer_model_that_carries_something_and_writes_the_offers_own_config(): void
    {
        $offer = $this->offer();

        $this->sync($offer, ['b2b' => $this->config(), 'b2g' => $this->config(['label' => 'Government schools']), 'b2c' => []]);

        $profiles = IcpProfile::where('sub_institute_id', self::T)->orderBy('key')->get();
        $this->assertSame([SubProductIcp::key('P1', 'b2b'), SubProductIcp::key('P1', 'b2g')], $profiles->pluck('key')->all(), 'b2c was empty, so it has no profile');
        $this->assertSame(['sub_p1', 'sub_p1_b2g'], $profiles->pluck('key')->all());
        foreach ($profiles as $profile) {
            $this->assertSame('active', $profile->status);
            $this->assertSame(1, $profile->version);
            $this->assertFalse($profile->is_default);
            $this->assertSame(7, (int) $profile->created_by);
            $this->assertSame(['P1'], $profile->config['offer_ids']);
        }
        $b2b = $profiles->firstWhere('key', 'sub_p1');
        $b2g = $profiles->firstWhere('key', 'sub_p1_b2g');
        $this->assertSame('offer_p1_b2b', $b2b->product_line);
        $this->assertSame('offer_p1_b2g', $b2g->product_line);
        $this->assertSame('b2b', $b2b->config['customer_model']);
        $this->assertSame('Scholar One B2B ICP', $b2b->config['label'], 'the default label names the offer and the model');
        $this->assertStringStartsWith('Government schools', $b2g->name, 'a label the person gave is kept');

        $stored = $offer->fresh()->icp_config;
        $this->assertSame(['K12-PVT'], $stored['b2b']['target_segments']);
        $this->assertSame('Government schools', $stored['b2g']['label']);
        $this->assertNull($stored['b2c']);
    }

    public function test_a_second_sync_updates_the_same_profile_in_place_and_raises_its_version(): void
    {
        $offer = $this->offer();
        $this->sync($offer, ['b2b' => $this->config(['label' => 'First'])]);

        $this->sync($offer->fresh(), ['b2b' => $this->config(['label' => 'Second', 'buyer_roles' => ['Trustee']])], self::T, 9);

        $profiles = IcpProfile::where('sub_institute_id', self::T)->get();
        $this->assertCount(1, $profiles, 'found by its key, not duplicated');
        $this->assertSame(2, $profiles[0]->version);
        $this->assertSame(9, (int) $profiles[0]->updated_by);
        $this->assertSame(7, (int) $profiles[0]->created_by, 'who made it is not overwritten');
        $this->assertSame(['Trustee'], $profiles[0]->config['buyer_roles']);
        $this->assertStringStartsWith('Second', $profiles[0]->name);
        $this->assertSame('Second', $offer->fresh()->icp_config['b2b']['label']);
    }

    public function test_a_model_the_payload_does_not_mention_keeps_its_stored_config(): void
    {
        $offer = $this->offer();
        $this->sync($offer, ['b2b' => $this->config(['label' => 'Kept']), 'b2g' => $this->config(['label' => 'Replaced'])]);

        $this->sync($offer->fresh(), ['b2g' => $this->config(['label' => 'Replaced again'])]);

        $stored = $offer->fresh()->icp_config;
        $this->assertSame('Kept', $stored['b2b']['label'], 'b2b was not in the second payload');
        $this->assertSame('Replaced again', $stored['b2g']['label']);
        $this->assertSame(1, IcpProfile::where('key', 'sub_p1')->value('version'), 'and its profile was not touched');
        $this->assertSame(2, IcpProfile::where('key', 'sub_p1_b2g')->value('version'));
    }

    public function test_a_single_config_goes_to_the_customer_model_it_names_and_to_b2b_when_it_names_none(): void
    {
        $offer = $this->offer();
        $this->sync($offer, $this->config(['customer_model' => 'b2g']));
        $other = $this->offer(self::T, 'P2', 'Scholar Two');
        $this->sync($other, $this->config(['customer_model' => 'nonsense']));

        $this->assertSame(['sub_p1_b2g', 'sub_p2'], IcpProfile::orderBy('key')->pluck('key')->all());
        $this->assertNull($offer->fresh()->icp_config['b2b']);
        $this->assertSame('b2g', $offer->fresh()->icp_config['b2g']['customer_model']);
        $this->assertSame('b2b', $other->fresh()->icp_config['b2b']['customer_model'], 'an unknown model falls back to b2b');
    }

    public function test_an_empty_payload_writes_no_profile_but_the_offers_config_is_still_saved(): void
    {
        $offer = $this->offer();

        $this->sync($offer, ['b2b' => []]);

        $this->assertSame(0, IcpProfile::count());
        $this->assertSame(['b2b' => null, 'b2c' => null, 'b2g' => null], $offer->fresh()->icp_config);
    }

    public function test_the_profile_belongs_to_the_organisation_that_called_and_another_organisations_profile_is_left_alone(): void
    {
        $theirs = $this->offer(self::T2, 'P1', 'Their Scholar One');
        $this->sync($theirs, ['b2b' => $this->config(['label' => 'Theirs'])], self::T2, 3);
        $mine = $this->offer(self::T, 'P1', 'Scholar One');

        $this->sync($mine, ['b2b' => $this->config(['label' => 'Mine'])]);

        $this->assertSame(1, IcpProfile::where('sub_institute_id', self::T)->count());
        $this->assertSame(1, IcpProfile::where('sub_institute_id', self::T2)->count());
        $this->assertSame(1, (int) IcpProfile::where('sub_institute_id', self::T2)->value('version'), 'the same key in another organisation is not a match');
        $this->assertStringStartsWith('Theirs', (string) IcpProfile::where('sub_institute_id', self::T2)->value('name'));
    }

    public function test_a_host_without_the_profiles_table_still_saves_the_offers_own_config(): void
    {
        $offer = $this->offer();
        Schema::drop('g2g_icp_profiles');

        $this->sync($offer, ['b2b' => $this->config(['label' => 'Alone'])]);

        $this->assertSame('Alone', $offer->fresh()->icp_config['b2b']['label']);
    }

    public function test_a_host_without_the_icp_config_column_writes_the_profiles_and_leaves_the_offer_alone(): void
    {
        $offer = $this->offer();
        Schema::table('g2g_product_offers', fn ($t) => $t->dropColumn('icp_config'));

        $this->sync($offer, ['b2b' => $this->config()]);

        $this->assertSame(1, IcpProfile::count(), 'the profile is written');
        $this->assertFalse(Schema::hasColumn('g2g_product_offers', 'icp_config'));
        $this->assertSame(1, DB::table('g2g_product_offers')->count());
    }
}
