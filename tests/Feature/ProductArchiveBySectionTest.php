<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * Archive and restore are judged by SECTION (slice 15, track WPA1, item 4; audit decision D-E1).
 *
 * `POST /api/product-master/{id}/archive` and `/restore` used to ask the Products row's delete / edit right in the route, so HR, who owns the About of a Sub-product and holds no
 * row on the Products screen, could not archive or restore a Sub-product it owns. The routes now ask only for a signed-in token and ProductWriter::setStatus decides, the way
 * create and update do:
 *   a record with an About           the Portfolio's right (ProductAccess::canWriteOffers: administrator and HR roles; the menu right on /portfolio once that is switched on)
 *   a Sell-only record (an item)     the right of its OWN type on the Products or Services row (ProductAccess::canWriteCatalogue): delete to archive, edit to restore
 *   a level-1 Product                an administrator, who needs no row on the Products screen
 *   a record with neither            the Products row's right, as before (nothing to judge it by)
 * Archive asks the `delete` action, restore the `edit` action, as the routes did.
 */
class ProductArchiveBySectionTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    private const ADMIN = 1;

    private const HR = 4;            // role hr_manager: no row on Products or Services

    private const SELLER = 5;        // an employee with every right on Products, none on Services

    private const NOBODY = 6;        // an employee with no row at all

    private const SERVICES = 7;      // an employee with every right on Services, none on Products

    private const EDIT_ONLY = 8;     // Products: view and edit

    private const DELETE_ONLY = 9;   // Products: view and delete

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        foreach ([[self::HR, 'hr_manager'], [self::SELLER, 'employee'], [self::NOBODY, 'employee'], [self::SERVICES, 'employee'], [self::EDIT_ONLY, 'employee'], [self::DELETE_ONLY, 'employee']] as [$id, $role]) {
            DB::table('tbluserprofilemaster')->insert(['id' => $id, 'name' => "Profile {$id}", 'role_key' => $role, 'sub_institute_id' => self::T]);
        }
        $this->grant(self::SELLER, 468, 1, 1, 1, 1);
        $this->grant(self::SERVICES, 469, 1, 1, 1, 1);
        $this->grant(self::EDIT_ONLY, 468, 1, 0, 1, 0);
        $this->grant(self::DELETE_ONLY, 468, 1, 0, 0, 1);
        Cache::flush();
    }

    private function grant(int $profile, int $menu, int $view, int $add, int $edit, int $delete, int $tenant = self::T): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => $view, 'can_add' => $add, 'can_edit' => $edit, 'can_delete' => $delete]);
    }

    /** The status code of an archive or a restore by one profile. */
    private function code(int $profile, int $id, string $verb): int
    {
        return $this->postJson("/api/product-master/{$id}/{$verb}", [], $this->headers($this->h(self::T, $profile)))->status();
    }

    private function stored(int $id): string
    {
        return (string) DB::table('g2g_product_master')->where('id', $id)->value('status');
    }

    /** @return int the master id of a Sub-product that has an About only */
    private function aboutOnly(): int
    {
        return (int) $this->masterOfOffer($this->offerCreate('A1', 'About one'))->id;
    }

    /** @return int the master id of a catalogue item (Sell only) */
    private function sellOnly(string $type = 'product'): int
    {
        return (int) $this->masterOfCrm($this->crmCreate($type === 'service' ? 'Consulting' : 'Starter kit', $type))->id;
    }

    // ---------------------------------------------------------------- a record with an About

    public function test_a_sub_product_with_an_about_is_archived_and_restored_by_the_portfolio_right_not_the_products_row(): void
    {
        $id = $this->aboutOnly();

        // Nobody who lacks the Portfolio's right can: not a person with every right on Products, not Services, not a person with no row at all.
        foreach ([self::SELLER, self::SERVICES, self::NOBODY, self::EDIT_ONLY, self::DELETE_ONLY] as $profile) {
            $this->assertSame(403, $this->code($profile, $id, 'archive'), "profile {$profile} owns no About");
        }
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $this->stored($id), 'a refusal changes nothing');

        // HR owns the About and has no row on Products: it archives.
        $this->assertSame(200, $this->code(self::HR, $id, 'archive'));
        $this->assertSame(ProductMaster::STATUS_ARCHIVED, $this->stored($id));
        foreach ([self::SELLER, self::NOBODY, self::SERVICES] as $profile) {
            $this->assertSame(403, $this->code($profile, $id, 'restore'), "profile {$profile} may not restore an About either");
        }
        $this->assertSame(ProductMaster::STATUS_ARCHIVED, $this->stored($id));
        $this->assertSame(200, $this->code(self::HR, $id, 'restore'));
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $this->stored($id));

        // And the administrator, as always.
        $this->assertSame(200, $this->code(self::ADMIN, $id, 'archive'));
        $this->assertSame(200, $this->code(self::ADMIN, $id, 'restore'));
    }

    public function test_a_sub_product_with_an_about_and_a_sell_side_follows_the_about(): void
    {
        $f = $this->soldSubProduct('B1', 'Both one');

        $this->assertSame(403, $this->code(self::SELLER, $f['master'], 'archive'), 'the Sell right alone does not archive a record that has an About');
        $this->assertSame(200, $this->code(self::HR, $f['master'], 'archive'));
        $this->assertSame(200, $this->code(self::HR, $f['master'], 'restore'));
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $this->stored($f['master']));
    }

    // ---------------------------------------------------------------- a Sell-only record

    public function test_a_sell_only_item_follows_the_right_of_its_own_type(): void
    {
        $product = $this->sellOnly('product');
        $service = $this->sellOnly('service');

        // HR owns no Sell right, a person with none at all neither.
        foreach ([self::HR, self::NOBODY] as $profile) {
            $this->assertSame(403, $this->code($profile, $product, 'archive'));
            $this->assertSame(403, $this->code($profile, $service, 'archive'));
        }
        // Each type needs ITS OWN row.
        $this->assertSame(403, $this->code(self::SERVICES, $product, 'archive'), 'the Services row does not archive a product');
        $this->assertSame(403, $this->code(self::SELLER, $service, 'archive'), 'the Products row does not archive a service');
        $this->assertSame([ProductMaster::STATUS_ACTIVE, ProductMaster::STATUS_ACTIVE], [$this->stored($product), $this->stored($service)]);

        $this->assertSame(200, $this->code(self::SELLER, $product, 'archive'));
        $this->assertSame(200, $this->code(self::SERVICES, $service, 'archive'));
        $this->assertSame(403, $this->code(self::HR, $product, 'restore'));
        $this->assertSame(403, $this->code(self::SELLER, $service, 'restore'));
        $this->assertSame(200, $this->code(self::SELLER, $product, 'restore'));
        $this->assertSame(200, $this->code(self::SERVICES, $service, 'restore'));
        $this->assertSame([ProductMaster::STATUS_ACTIVE, ProductMaster::STATUS_ACTIVE], [$this->stored($product), $this->stored($service)]);
    }

    public function test_on_the_sell_side_archive_needs_the_delete_right_and_restore_the_edit_right(): void
    {
        $id = $this->sellOnly();

        $this->assertSame(403, $this->code(self::EDIT_ONLY, $id, 'archive'), 'edit is not delete');
        $this->assertSame(200, $this->code(self::DELETE_ONLY, $id, 'archive'));
        $this->assertSame(403, $this->code(self::DELETE_ONLY, $id, 'restore'), 'delete is not edit');
        $this->assertSame(200, $this->code(self::EDIT_ONLY, $id, 'restore'));
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $this->stored($id));
    }

    // ---------------------------------------------------------------- a level-1 Product and the rest

    public function test_a_product_is_an_administrators_and_the_administrator_needs_no_row_on_the_products_screen(): void
    {
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->headers($this->h()))->assertCreated();
        $id = (int) $this->masterByKey('payroll_cloud')->id;

        foreach ([self::HR, self::SELLER, self::NOBODY, self::SERVICES] as $profile) {
            $this->assertSame(403, $this->code($profile, $id, 'archive'), "profile {$profile} is no administrator");
        }
        $this->assertSame(ProductMaster::STATUS_ACTIVE, $this->stored($id));

        // The administrator has lost every row on Products and Services: it still archives and restores a Product.
        DB::table('tblgroupwise_rights_g2g')->where('profile_id', self::ADMIN)->whereIn('menu_id', [468, 469])->delete();
        Cache::flush();
        $this->assertSame(200, $this->code(self::ADMIN, $id, 'archive'));
        $this->assertSame(ProductMaster::STATUS_ARCHIVED, $this->stored($id));
        $this->assertSame(403, $this->code(self::HR, $id, 'restore'));
        $this->assertSame(200, $this->code(self::ADMIN, $id, 'restore'));
    }

    public function test_a_record_with_neither_an_about_nor_a_sell_side_keeps_the_products_rows_right(): void
    {
        $id = (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T, 'product_key' => 'scholarclone/bare', 'kind' => ProductMaster::KIND_SUB_PRODUCT, 'name' => 'Bare', 'status' => 'active', 'origin' => 'manual',
            'parent_id' => $this->sharedId('scholarclone'),
        ]);

        $this->assertSame(403, $this->code(self::HR, $id, 'archive'), 'nothing to judge it by: the Products row decides, as before');
        $this->assertSame(403, $this->code(self::EDIT_ONLY, $id, 'archive'));
        $this->assertSame(200, $this->code(self::DELETE_ONLY, $id, 'archive'));
        $this->assertSame(403, $this->code(self::DELETE_ONLY, $id, 'restore'));
        $this->assertSame(200, $this->code(self::EDIT_ONLY, $id, 'restore'));
    }

    public function test_the_other_refusals_are_unchanged(): void
    {
        $mine = $this->aboutOnly();
        $theirs = (int) $this->masterOfOffer($this->offerCreate('T2A', 'Theirs', 'Scholar K-12', self::T2), self::T2)->id;

        $this->postJson("/api/product-master/{$mine}/archive")->assertStatus(401);
        $this->assertSame(404, $this->code(self::ADMIN, $theirs, 'archive'), "another organisation's record is not found");
        $this->assertSame(404, $this->code(self::ADMIN, 999999, 'archive'));
        $leftover = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => 0, 'product_key' => 'leftover_line', 'kind' => 'product', 'name' => 'Leftover', 'status' => 'active', 'origin' => 'builtin', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(404, $this->code(self::ADMIN, $leftover, 'archive'), 'CHANGED ON PURPOSE (S15 follow-up, 24 Nov 2026): a leftover row of organisation 0 is nobody\'s, so it is not found (it was a 403 "shared built-in product")');
        $this->assertSame(409, $this->code(self::ADMIN, $this->sharedId('scholarclone'), 'archive'), 'the built-in Product is the organisation\'s own now: it has Sub-products, so it cannot be archived (the business rule, not a "shared" refusal)');
        // The order of the checks is kept: the right first, then the business rule (a Product with an active Sub-product is a 409 for an administrator, a 403 for HR).
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->headers($this->h()))->assertCreated();
        $product = (int) $this->masterByKey('payroll_cloud')->id;
        DB::table('g2g_product_master')->where('id', $mine)->update(['parent_id' => $product]);
        $this->assertSame(409, $this->code(self::ADMIN, $product, 'archive'));
        $this->assertSame(403, $this->code(self::HR, $product, 'archive'));
    }

    // ---------------------------------------------------------------- menu rights switched on

    public function test_with_the_menu_rights_of_the_portfolio_switched_on_the_portfolio_row_decides_by_action(): void
    {
        config(['signals.permissions.use_menu_rights' => true]);
        DB::table('tblmenumaster_g2g')->insert(['id' => 490, 'menu_name' => 'Product Portfolio', 'parent_id' => 199, 'level' => 2, 'access_link' => '/portfolio', 'sort_order' => 490]);
        Cache::flush();
        $id = $this->aboutOnly();

        // HR holds no row on /portfolio yet: with the menu rights on, the role no longer counts.
        $this->assertSame(403, $this->code(self::HR, $id, 'archive'));
        $this->grant(self::HR, 490, 1, 0, 0, 1);   // delete only
        Cache::flush();
        $this->assertSame(200, $this->code(self::HR, $id, 'archive'), 'delete on /portfolio archives');
        $this->assertSame(403, $this->code(self::HR, $id, 'restore'), 'but edit is needed to restore');
        $this->grant(self::SELLER, 490, 1, 0, 1, 0);   // edit only
        Cache::flush();
        $this->assertSame(403, $this->code(self::SELLER, $id, 'archive'));
        $this->assertSame(200, $this->code(self::SELLER, $id, 'restore'), 'edit on /portfolio restores');
    }

    // ---------------------------------------------------------------- what the screen is told

    public function test_the_meta_flags_say_who_may_archive_or_restore_something(): void
    {
        $flags = fn (int $profile) => $this->getJson('/api/product-master/meta', $this->headers($this->h(self::T, $profile)))->assertOk()->json('data.can');

        $hr = $flags(self::HR);
        $this->assertTrue($hr['archive'] && $hr['restore'], 'HR archives and restores the Sub-products it owns');
        $this->assertFalse($hr['archiveProduct'] || $hr['restoreProduct'], 'but no Product');

        $seller = $flags(self::SELLER);
        $this->assertTrue($seller['archive'] && $seller['restore'], 'a seller archives and restores the items of its type');
        $this->assertFalse($seller['archiveProduct'] || $seller['restoreProduct']);

        $nobody = $flags(self::NOBODY);
        $this->assertFalse($nobody['archive'] || $nobody['restore'] || $nobody['archiveProduct'] || $nobody['restoreProduct']);

        $editOnly = $flags(self::EDIT_ONLY);
        $this->assertFalse($editOnly['archive'], 'edit alone does not archive');
        $this->assertTrue($editOnly['restore']);

        $admin = $flags(self::ADMIN);
        $this->assertTrue($admin['archive'] && $admin['restore'] && $admin['archiveProduct'] && $admin['restoreProduct']);
    }
}
