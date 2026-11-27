<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSalesKitWorld;
use Tests\TestCase;

/**
 * Part A of the Sales Kit's second round (Docs/agentic-crm/briefs/SALES-KIT-SHARE-CONTRACT.md): every FILE uploaded to a Product's kit is filed in the Document Library under
 * "Sales Kit / <Product name>", one tree per organization, made by App\Domain\ProductMaster\Kit\KitFolders as part of the upload (POST api/product-master/{id}/kit/files).
 */
class SalesKitFoldersTest extends TestCase
{
    use BuildsSalesKitWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesKitWorld();
    }

    private function upload1(int $product, array $h, string $name = 'deck.pdf', ?UploadedFile $file = null): array
    {
        return $this->post("/api/product-master/{$product}/kit/files", ['file' => $file ?? $this->upload($name, 'application/pdf')], $h + ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    /** @return list<object> every folder, oldest first */
    private function folders(?int $tenant = null): array
    {
        $query = DB::table('document_folders')->orderBy('id');
        if ($tenant !== null) {
            $query->where('sub_institute_id', $tenant);
        }

        return $query->get()->all();
    }

    private function folderOf(array $item): ?int
    {
        $id = DB::table('document_library')->where('id', $item['documentId'])->value('folder_id');

        return $id !== null ? (int) $id : null;
    }

    public function test_the_first_upload_makes_the_sales_kit_root_and_the_product_folder_with_the_right_fields_and_files_the_document_in_it(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();

        $item = $this->upload1($p, $m['h']);

        $folders = $this->folders();
        $this->assertCount(2, $folders);
        [$root, $product] = $folders;
        $this->assertSame(['Sales Kit', null, self::T, null, 'organization', 0, $m['id']], [$root->name, $root->parent_id, (int) $root->sub_institute_id, $root->owner_id, $root->visibility, (int) $root->sort_order, (int) $root->created_by]);
        $this->assertSame(['ScholarClone', (int) $root->id, self::T, null, 'organization', 0, $m['id']], [$product->name, (int) $product->parent_id, (int) $product->sub_institute_id, $product->owner_id, $product->visibility, (int) $product->sort_order, (int) $product->created_by]);
        $this->assertNull($product->deleted_at);
        $this->assertSame((int) $product->id, $this->folderOf($item));
        $doc = DB::table('document_library')->where('id', $item['documentId'])->first();
        $this->assertSame(['Sales Kit', 'organization', 'organization'], [$doc->document_type, $doc->visibility, $doc->category], 'the document itself is as before');
        // Everything else the upload writes is still written.
        $this->assertSame([1, 1, 1, 1], $this->kitCounts());
        $this->assertSame(1, DB::table('document_library_links')->where('document_id', $item['documentId'])->where('linked_type', 'PRODUCT')->where('linked_id', $p)->count());
    }

    public function test_a_second_upload_of_the_same_product_reuses_both_folders_and_a_second_product_gets_its_own_under_the_same_root(): void
    {
        $a = $this->kitProduct(self::T, 'ScholarClone');
        $b = $this->kitProduct(self::T, 'Enterprise Brain');
        $m = $this->kitManager();

        $one = $this->upload1($a, $m['h'], 'one.pdf');
        $two = $this->upload1($a, $m['h'], 'two.pdf');
        $this->assertCount(2, $this->folders(), 'no duplicate root and no duplicate product folder');
        $this->assertSame($this->folderOf($one), $this->folderOf($two));

        $three = $this->upload1($b, $m['h'], 'three.pdf');
        $folders = $this->folders();
        $this->assertCount(3, $folders);
        $this->assertSame(['Sales Kit', 'ScholarClone', 'Enterprise Brain'], array_column($folders, 'name'));
        $this->assertSame([(int) $folders[0]->id, (int) $folders[0]->id], [(int) $folders[1]->parent_id, (int) $folders[2]->parent_id], 'both under the one root');
        $this->assertNotSame($this->folderOf($one), $this->folderOf($three));
        $this->assertSame((int) $folders[2]->id, $this->folderOf($three));
    }

    public function test_another_organization_gets_its_own_tree_and_never_sees_or_reuses_this_ones(): void
    {
        $shared = $this->kitProduct(0, 'Fiber Valley');
        $six = $this->kitManager(self::T);
        $seven = $this->kitManager(self::T2, 'Zed Foreigner');

        $a = $this->upload1($shared, $six['h'], 'six.pdf');
        $b = $this->upload1($shared, $seven['h'], 'seven.pdf');

        $this->assertCount(2, $this->folders(self::T));
        $this->assertCount(2, $this->folders(self::T2));
        $this->assertNotSame($this->folderOf($a), $this->folderOf($b));
        $this->assertSame(self::T, (int) DB::table('document_folders')->where('id', $this->folderOf($a))->value('sub_institute_id'));
        $this->assertSame(self::T2, (int) DB::table('document_folders')->where('id', $this->folderOf($b))->value('sub_institute_id'));
        $this->assertSame(['Sales Kit', 'Fiber Valley', 'Sales Kit', 'Fiber Valley'], array_column($this->folders(), 'name'));
        // The roots are two roots, one each.
        $this->assertSame(2, DB::table('document_folders')->whereNull('parent_id')->count());
    }

    public function test_a_product_that_was_renamed_renames_its_folder_and_keeps_it(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $first = $this->upload1($p, $m['h'], 'one.pdf');
        $folder = $this->folderOf($first);

        DB::table('g2g_product_master')->where('id', $p)->update(['name' => 'ScholarClone Plus']);
        $second = $this->upload1($p, $m['h'], 'two.pdf');

        $this->assertSame($folder, $this->folderOf($second), 'the same folder, not a second one');
        $this->assertCount(2, $this->folders());
        $this->assertSame('ScholarClone Plus', DB::table('document_folders')->where('id', $folder)->value('name'));
        $this->assertSame($m['id'], (int) DB::table('document_folders')->where('id', $folder)->value('updated_by'));
    }

    public function test_a_folder_that_only_has_the_name_is_never_reused_when_it_is_not_a_root_of_this_organization(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $make = fn (array $over) => (int) DB::table('document_folders')->insertGetId($over + ['sub_institute_id' => self::T, 'name' => 'Sales Kit', 'visibility' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
        $someone = $make(['name' => 'Marketing', 'parent_id' => null]);
        $notRoot = $make(['name' => 'Sales Kit', 'parent_id' => $someone]);                         // named "Sales Kit" but inside another folder
        $foreign = $make(['name' => 'Sales Kit', 'parent_id' => null, 'sub_institute_id' => self::T2]);   // another organization's root
        $deleted = $make(['name' => 'Sales Kit', 'parent_id' => null, 'deleted_at' => now()]);      // a root that was deleted
        $before = DB::table('document_folders')->count();

        $item = $this->upload1($p, $m['h']);

        $root = DB::table('document_folders')->where('sub_institute_id', self::T)->whereNull('parent_id')->where('name', 'Sales Kit')->whereNull('deleted_at')->first();
        $this->assertNotNull($root);
        $this->assertNotContains((int) $root->id, [$someone, $notRoot, $foreign, $deleted], 'a new root was made');
        $this->assertSame($before + 2, DB::table('document_folders')->count());
        $this->assertSame((int) $root->id, (int) DB::table('document_folders')->where('id', $this->folderOf($item))->value('parent_id'));
        foreach ([$someone, $notRoot, $foreign, $deleted] as $id) {
            $this->assertSame(0, DB::table('document_library')->where('folder_id', $id)->count(), "folder {$id} holds nothing of ours");
        }
    }

    public function test_a_product_folder_with_the_right_name_under_another_parent_is_not_used_and_a_document_moved_out_of_the_tree_is_not_followed(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $first = $this->upload1($p, $m['h'], 'one.pdf');
        $tree = $this->folderOf($first);
        $other = (int) DB::table('document_folders')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Old brochures', 'parent_id' => null, 'visibility' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
        // Somebody moved the only kit document into their own folder, and the Product has been renamed.
        DB::table('document_library')->where('id', $first['documentId'])->update(['folder_id' => $other]);
        DB::table('g2g_product_master')->where('id', $p)->update(['name' => 'ScholarClone Plus']);

        $second = $this->upload1($p, $m['h'], 'two.pdf');

        $this->assertSame('Old brochures', DB::table('document_folders')->where('id', $other)->value('name'), 'a folder outside the Sales Kit tree is never renamed');
        $this->assertNotSame($other, $this->folderOf($second));
        $this->assertSame((int) DB::table('document_folders')->where('name', 'Sales Kit')->value('id'), (int) DB::table('document_folders')->where('id', $this->folderOf($second))->value('parent_id'));
        $this->assertSame('ScholarClone', DB::table('document_folders')->where('id', $tree)->value('name'), 'the old folder is left as it was; the new upload has a folder named for the Product as it is now');
        $this->assertSame('ScholarClone Plus', DB::table('document_folders')->where('id', $this->folderOf($second))->value('name'));
    }

    public function test_taking_an_item_out_keeps_the_folder_and_the_next_upload_reuses_it(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $first = $this->upload1($p, $m['h'], 'one.pdf');
        $this->deleteJson("/api/product-master/{$p}/kit/{$first['id']}", [], $m['h'])->assertOk();

        $this->assertCount(2, $this->folders(), 'removing an item never removes a folder');
        $this->assertSame($this->folderOf($first), (int) DB::table('document_library')->where('id', $first['documentId'])->value('folder_id'), 'nor moves the document');
        $second = $this->upload1($p, $m['h'], 'two.pdf');

        $this->assertCount(2, $this->folders());
        $this->assertSame($this->folderOf($first), $this->folderOf($second));
    }

    public function test_a_document_added_from_the_library_is_not_moved_and_a_link_makes_no_folder(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $owner = $this->kitManager(self::T, 'Priya Nair');
        $theirs = (int) DB::table('document_folders')->insertGetId(['sub_institute_id' => self::T, 'owner_id' => $owner['id'], 'name' => 'Priya docs', 'parent_id' => null, 'visibility' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
        $inFolder = $this->libraryDocument(self::T, $owner['id'], ['folder_id' => $theirs]);
        $atRoot = $this->libraryDocument(self::T, $owner['id'], ['original_file_name' => 'loose.pdf']);

        $this->postJson("/api/product-master/{$p}/kit/from-library", ['documentId' => $inFolder], $m['h'])->assertCreated();
        $this->postJson("/api/product-master/{$p}/kit/from-library", ['documentId' => $atRoot], $m['h'])->assertCreated();
        $this->postJson("/api/product-master/{$p}/kit/links", ['title' => 'Deck', 'url' => 'https://example.com/deck', 'kind' => 'video'], $m['h'])->assertCreated();

        $this->assertSame($theirs, (int) DB::table('document_library')->where('id', $inFolder)->value('folder_id'));
        $this->assertNull(DB::table('document_library')->where('id', $atRoot)->value('folder_id'));
        $this->assertCount(1, $this->folders(), 'only the owner\'s own folder: nothing was made for these');
        // And from-library with makeShared keeps the folder too.
        $private = $this->libraryDocument(self::T, $m['id'], ['visibility' => 'private', 'folder_id' => $theirs, 'original_file_name' => 'mine.pdf']);
        $this->postJson("/api/product-master/{$p}/kit/from-library", ['documentId' => $private, 'makeShared' => true], $m['h'])->assertCreated();
        $this->assertSame($theirs, (int) DB::table('document_library')->where('id', $private)->value('folder_id'));
    }

    public function test_a_host_without_the_folder_table_still_takes_the_upload_with_no_folder(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        Schema::drop('document_folders');

        $item = $this->upload1($p, $m['h']);

        $this->assertNull($this->folderOf($item));
        $this->assertSame([1, 1, 1, 1], $this->kitCounts());
        Storage::disk(config('documents.disk'))->assertExists((string) DB::table('document_library')->where('id', $item['documentId'])->value('storage_path'));
    }

    public function test_a_host_without_the_folder_column_still_takes_the_upload_with_no_folder(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        Schema::table('document_library', fn ($t) => $t->dropColumn('folder_id'));

        $item = $this->upload1($p, $m['h']);

        $this->assertFalse(Schema::hasColumn('document_library', 'folder_id'));
        $this->assertSame([1, 1, 1, 1], $this->kitCounts());
        $this->assertSame(0, DB::table('document_folders')->count(), 'no folder is made when the document could not be put in it');
        $this->assertSame('deck', $item['title']);
    }

    public function test_a_folder_that_cannot_be_made_never_fails_the_upload_and_leaves_no_half_made_tree(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        // A folder table that takes a root and refuses anything inside one: the Product's folder fails AFTER the root was made.
        Schema::drop('document_folders');
        DB::statement('CREATE TABLE document_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, sub_institute_id INTEGER NOT NULL, owner_id INTEGER NULL, department_id INTEGER NULL, parent_id INTEGER NULL CHECK (parent_id IS NULL), name VARCHAR(191) NOT NULL, visibility VARCHAR(20) NOT NULL DEFAULT \'private\', sort_order INTEGER NOT NULL DEFAULT 0, created_by INTEGER NULL, updated_by INTEGER NULL, deleted_by INTEGER NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL)');

        $item = $this->upload1($p, $m['h']);

        $this->assertNull($this->folderOf($item));
        $this->assertSame(0, DB::table('document_folders')->count(), 'the root made before the failure was taken back with it');
        $this->assertSame([1, 1, 1, 1], $this->kitCounts(), 'the document, its history, its link and the kit row are all there');
        $this->assertSame('deck', $this->getJson("/api/product-master/{$p}/kit", $m['h'])->assertOk()->json('data.items.0.title'));
    }

    public function test_a_product_name_as_long_as_the_column_allows_is_the_folder_name_exactly(): void
    {
        $long = $this->kitProduct(self::T, str_repeat('A', 191));
        $m = $this->kitManager();

        $item = $this->upload1($long, $m['h']);

        $this->assertSame(str_repeat('A', 191), DB::table('document_folders')->where('id', $this->folderOf($item))->value('name'));
        $this->assertSame('Sales Kit', DB::table('document_folders')->whereNull('parent_id')->value('name'));
    }
}
