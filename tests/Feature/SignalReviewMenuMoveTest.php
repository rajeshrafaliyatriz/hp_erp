<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Moving the Signal Review menu row out of the Market Signals branch (slice 2 of the agentic CRM, 3 Nov 2026): the live Signals navigation folds that branch
 * into one flat link and drops every child except the four tabs and Signal Inbox, so a Signal Review row left under it would have no sidebar entry. The move
 * changes ONLY that one row's parent, level and sort order; it must never touch the Signals rows, any rights row, or any other menu row.
 */
class SignalReviewMenuMoveTest extends TestCase
{
    private const ROOT = '/module/crm';

    private const PARENT = '/module/crm/signals';

    private const LEAF = '/signal-review';

    private const SIGNALS_MENU = '2026_11_29_080200_add_signals_menu_branch';

    private const MENU = '2026_12_08_090300_create_signal_review_menu_row';

    private const MOVE = '2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamps();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->string('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => 1],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => 1],
        ]);
        DB::table('tblmenumaster_g2g')->insert(['id' => 199, 'menu_name' => 'Sales & Marketing Intelligence', 'parent_id' => 0, 'level' => 1, 'access_link' => self::ROOT, 'sort_order' => 1]);
        // A few ordinary siblings of the Signals row under the root, with an inactive twin sharing a sort order (the real tree has such ties).
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 300, 'menu_name' => 'Command Center', 'parent_id' => 199, 'level' => 2, 'access_link' => '/gtm/command-center', 'sort_order' => 1, 'status' => 1],
            ['id' => 301, 'menu_name' => 'Organizations', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/marketing/organizations', 'sort_order' => 5, 'status' => 1],
            ['id' => 302, 'menu_name' => 'Master Fields', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/master-fields', 'sort_order' => 28, 'status' => 1],
            ['id' => 303, 'menu_name' => 'Inactive twin', 'parent_id' => 199, 'level' => 2, 'access_link' => '/x', 'sort_order' => 28, 'status' => 0],
        ]);
        foreach ([1, 2] as $profile) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => 1, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
        }
        $this->migration(self::SIGNALS_MENU)->up();   // Market Signals and its four tabs
        $this->migration(self::MENU)->up();            // Signal Review under Market Signals, as it was rolled out
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function leaf(): object
    {
        return DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->first();
    }

    private function rootId(): int
    {
        return (int) DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT)->where('parent_id', 0)->value('id');
    }

    private function signalsId(): int
    {
        return (int) DB::table('tblmenumaster_g2g')->where('access_link', self::PARENT)->value('id');
    }

    /** Every menu row except the leaf, as plain arrays, in id order. */
    private function everythingButTheLeaf(): array
    {
        return DB::table('tblmenumaster_g2g')->where('access_link', '<>', self::LEAF)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function rights(): array
    {
        return DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_the_row_starts_under_market_signals_so_the_move_has_something_to_do(): void
    {
        $this->assertSame($this->signalsId(), (int) $this->leaf()->parent_id);
        $this->assertSame(3, (int) $this->leaf()->level);
    }

    public function test_the_row_moves_to_the_end_of_the_roots_children_one_level_below_the_root(): void
    {
        $largest = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $this->rootId())->max('sort_order');

        $this->migration(self::MOVE)->up();

        $leaf = $this->leaf();
        $this->assertSame($this->rootId(), (int) $leaf->parent_id);
        $this->assertSame(2, (int) $leaf->level, 'a sibling of Market Signals, like Organizations');
        $this->assertSame($largest + 1, (int) $leaf->sort_order, 'after every existing sibling, so no other row has to be renumbered');
        $this->assertSame('Signal Review', $leaf->menu_name);
        $this->assertSame(1, (int) $leaf->status);
    }

    public function test_it_changes_no_other_menu_row_and_no_rights_row(): void
    {
        $others = $this->everythingButTheLeaf();
        $rights = $this->rights();
        $leafId = $this->leaf()->id;
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => 1, 'menu_id' => $leafId, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
        $rights = $this->rights();

        $this->migration(self::MOVE)->up();

        $this->assertSame($others, $this->everythingButTheLeaf(), 'Market Signals, its four tabs, the root and every sibling are exactly as they were');
        $this->assertSame($rights, $this->rights(), 'the rights hang off the menu id, so who sees the screen is unchanged');
        $this->assertSame(4, DB::table('tblmenumaster_g2g')->where('parent_id', $this->signalsId())->count(), 'the four Signals tabs are still the only children of Market Signals');
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->migration(self::MOVE)->up();
        $after = (array) $this->leaf();

        $this->migration(self::MOVE)->up();

        $this->assertSame($after, (array) $this->leaf());
    }

    public function test_the_root_is_the_row_with_parent_zero_not_any_row_that_shares_the_link(): void
    {
        // A lower id, the same access_link, but not a root. Without the parent_id = 0 guard it would be chosen.
        DB::table('tblmenumaster_g2g')->insert(['id' => 150, 'menu_name' => 'Decoy', 'parent_id' => 77, 'level' => 5, 'access_link' => self::ROOT, 'sort_order' => 1]);

        $this->migration(self::MOVE)->up();

        $this->assertSame(199, (int) $this->leaf()->parent_id);
        $this->assertSame(2, (int) $this->leaf()->level);
    }

    public function test_it_does_nothing_without_the_menu_table_the_root_or_the_row(): void
    {
        DB::table('tblmenumaster_g2g')->where('id', 199)->delete();
        $before = (array) $this->leaf();
        $this->migration(self::MOVE)->up();
        $this->assertSame($before, (array) $this->leaf(), 'no root: nothing moves and nothing is created');

        DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->delete();
        $this->migration(self::MOVE)->up();
        $this->assertSame(0, DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->count(), 'it never creates the row');

        Schema::drop('tblmenumaster_g2g');
        $this->migration(self::MOVE)->up();
        $this->migration(self::MOVE)->down();
        $this->assertFalse(Schema::hasTable('tblmenumaster_g2g'));
    }

    public function test_down_puts_the_row_back_under_market_signals_after_its_four_tabs(): void
    {
        $this->migration(self::MOVE)->up();
        $largestUnderSignals = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $this->signalsId())->max('sort_order');

        $this->migration(self::MOVE)->down();

        $leaf = $this->leaf();
        $this->assertSame($this->signalsId(), (int) $leaf->parent_id);
        $this->assertSame(3, (int) $leaf->level);
        $this->assertSame($largestUnderSignals + 1, (int) $leaf->sort_order);

        $again = (array) $this->leaf();
        $this->migration(self::MOVE)->down();
        $this->assertSame($again, (array) $this->leaf(), 'down() twice is a no-op');
    }

    public function test_down_without_the_signals_branch_leaves_the_row_where_it_is(): void
    {
        $this->migration(self::MOVE)->up();
        DB::table('tblmenumaster_g2g')->where('access_link', self::PARENT)->delete();
        $moved = (array) $this->leaf();

        $this->migration(self::MOVE)->down();

        $this->assertSame($moved, (array) $this->leaf());
    }
}
