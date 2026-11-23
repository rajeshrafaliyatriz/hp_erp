<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Crm\CrmRecycleBinController;
use App\Support\MenuRight;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * The Recycle Bin and the old product addresses (slice 15, track WPB, item 7). The plan asked for the `link` entries of `products` and `services` in
 * CrmRecycleBinController::TYPES to name the Portfolio's addresses. They must NOT: those entries are not addresses in the answer, they are the MENU ROWS the rights of
 * each tab are looked up by (`canOnType` finds the row by its access_link, never by a numeric id). A Portfolio address there finds no menu row, so nobody would hold any right
 * on the Products and Services tabs and both would vanish from the Recycle Bin. The answer itself carries no address at all: a row is `{type, id, name, subLabel, deletedAt,
 * deletedBy}`, and the screen (components/domain/crm/recycle-bin-view.tsx) builds nothing from the type but the restore and delete calls.
 *
 * What this pins, so the change that was asked for cannot be made by accident: the shape of a row (no address), the two entries are the menu rows of the Products and Services
 * screens, a person with the Products row only sees the Products tab and one with the Services row only sees the Services tab, and nobody is shown the other's rows.
 */
class CrmRecycleBinAddressesTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    private function binned(string $type, string $name): int
    {
        $admin = $this->headers($this->h(self::T, 1));
        $id = $this->crmCreate($name, $type);
        $this->deleteJson("/api/crm/products/{$id}", [], $admin)->assertOk();

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function bin(int $profile = 1): array
    {
        return $this->getJson('/api/crm/recycle-bin?per_page=100', $this->headers($this->h(self::T, $profile)))->assertOk()->json('data.items');
    }

    public function test_a_binned_product_and_service_are_listed_with_no_address_in_the_row(): void
    {
        $product = $this->binned('product', 'Binned kit');
        $service = $this->binned('service', 'Binned training');

        $items = collect($this->bin())->keyBy(fn (array $i) => $i['type'] . ':' . $i['id']);

        $this->assertSame(['products:' . $product, 'services:' . $service], collect($items->keys())->sort()->values()->all());
        foreach ($items as $item) {
            $this->assertSame(['type', 'id', 'name', 'subLabel', 'deletedAt', 'deletedBy'], array_keys($item), 'a row names no address: the screen builds none from the type');
            $this->assertDoesNotMatchRegularExpression('~/module/|/portfolio|https?:~i', json_encode($item), 'no address in the row');
        }
    }

    public function test_the_two_entries_are_the_menu_rows_the_rights_are_found_by_not_addresses_of_a_screen(): void
    {
        $types = (new \ReflectionClassConstant(CrmRecycleBinController::class, 'TYPES'))->getValue();

        $this->assertSame('/module/crm/sales/products', $types['products']['link']);
        $this->assertSame('/module/crm/sales/services', $types['services']['link']);
        foreach (['products', 'services'] as $tab) {
            $this->assertNotNull(MenuRight::idForAccessLink($types[$tab]['link']), "{$tab}: the entry must be the access_link of a menu row, or nobody holds a right on the tab");
            $this->assertStringNotContainsString('portfolio', $types[$tab]['link']);
        }
    }

    public function test_each_tab_follows_its_own_menu_row(): void
    {
        $product = $this->binned('product', 'Binned kit');
        $service = $this->binned('service', 'Binned training');
        $kinds = fn (int $profile): array => collect($this->bin($profile))->pluck('type')->unique()->sort()->values()->all();

        $this->assertSame(['products', 'services'], $kinds(2), 'the employee of the fixture holds both rows');

        // take the Services row away from the employee: the Products tab stays, the Services tab goes
        DB::table('tblgroupwise_rights_g2g')->where('profile_id', 2)->where('menu_id', 469)->delete();
        $this->assertSame(['products'], $kinds(2));
        $this->assertSame([$product], collect($this->bin(2))->pluck('id')->map(fn ($i) => (int) $i)->all());

        // and the other way round
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 469, 'profile_id' => 2, 'can_view' => 1]);
        DB::table('tblgroupwise_rights_g2g')->where('profile_id', 2)->where('menu_id', 468)->delete();
        $this->assertSame(['services'], $kinds(2));
        $this->assertSame([$service], collect($this->bin(2))->pluck('id')->map(fn ($i) => (int) $i)->all());
    }
}
