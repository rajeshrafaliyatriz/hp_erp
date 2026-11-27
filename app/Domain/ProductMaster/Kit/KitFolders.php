<?php

namespace App\Domain\ProductMaster\Kit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a Sales Kit file sits in the Document Library (Docs/agentic-crm/briefs/SALES-KIT-SHARE-CONTRACT.md, part A). Every file uploaded to a Product's kit is filed under one folder tree per
 * organisation, so the library shows the kit "project wise":
 *
 *   Sales Kit             the root folder (no parent)
 *     <Product name>      one folder per Product, e.g. "ScholarClone"
 *
 * The folders are organisation-wide: `owner_id` is NULL (a folder of the whole organisation belongs to nobody, as the table's own comment says), `visibility` is `organization`, so every
 * user of the organisation sees them in the library tree. The document row gets `folder_id` directly: the library's folder-upload endpoint needs folder OWNERSHIP, which nobody has here.
 *
 * Found before it is created, so a second upload and a second run never make a duplicate:
 *   - the root: (organisation, no parent, name "Sales Kit", not deleted);
 *   - the Product's folder: FIRST the folder of a document that is already in this Product's kit, provided that folder sits directly under the root (and it is renamed when the Product has
 *     been renamed since); then (organisation, parent = the root, the Product's name); then a new one. A folder that is not under the Sales Kit root is never reused or renamed, and another
 *     organisation's folder is never seen.
 *
 * A document added to a kit FROM the library is not moved: it already lives in somebody's folder and moving it would surprise its owner.
 *
 * The folder is a convenience, never a condition: when this host has no `document_folders` table or no `document_library.folder_id` column, or anything in here fails, `forProduct` answers
 * null and the upload goes on without a folder (the caller runs it in a savepoint, so a half-made tree is taken back).
 */
final class KitFolders
{
    public const ROOT_NAME = 'Sales Kit';

    /** Whether this host can file a document in a folder at all. */
    public static function available(): bool
    {
        try {
            return Schema::hasTable('document_folders') && Schema::hasColumn('document_library', 'folder_id');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The id of the folder a file of this Product's kit goes in (made when it does not exist yet), or null when no folder can be used. Never throws.
     */
    public static function forProduct(int $tenant, int $userId, int $productId, string $productName): ?int
    {
        if (! self::available()) {
            return null;
        }

        try {
            // A savepoint inside the caller's transaction: if the second folder cannot be made, the first is taken back with it, and the upload carries on with no folder.
            return DB::transaction(function () use ($tenant, $userId, $productId, $productName) {
                $root = self::root($tenant, $userId);

                return self::productFolder($tenant, $userId, $productId, self::folderName($productName), (int) $root);
            });
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The name of a Product's folder: its name, at most 191 characters (the column), never empty. */
    private static function folderName(string $productName): string
    {
        $name = trim(mb_substr(trim($productName), 0, 191));

        return $name !== '' ? $name : 'Product';
    }

    private static function root(int $tenant, int $userId): int
    {
        $root = DB::table('document_folders')->where('sub_institute_id', $tenant)->whereNull('parent_id')->where('name', self::ROOT_NAME)->whereNull('deleted_at')->orderBy('id')->value('id');

        return $root !== null ? (int) $root : self::create($tenant, $userId, null, self::ROOT_NAME);
    }

    private static function productFolder(int $tenant, int $userId, int $productId, string $name, int $rootId): int
    {
        // First: the folder of a document that is already in this Product's kit (also one whose kit row was taken out: the folder is still the Product's).
        $folders = DB::table(ProductKit::TABLE . ' as k')
            ->join('document_library as d', 'd.id', '=', 'k.document_id')
            ->join('document_folders as f', 'f.id', '=', 'd.folder_id')
            ->where('k.sub_institute_id', $tenant)->where('k.product_master_id', $productId)
            ->where('f.sub_institute_id', $tenant)->where('f.parent_id', $rootId)->whereNull('f.deleted_at')
            ->orderByDesc('k.id')->get(['f.id', 'f.name']);
        $known = $folders->first();
        if ($known) {
            if ((string) $known->name !== $name) {
                // The Product was renamed: its folder follows.
                DB::table('document_folders')->where('id', $known->id)->where('sub_institute_id', $tenant)->where('parent_id', $rootId)
                    ->update(['name' => $name, 'updated_by' => $userId, 'updated_at' => now()]);
            }

            return (int) $known->id;
        }

        $byName = DB::table('document_folders')->where('sub_institute_id', $tenant)->where('parent_id', $rootId)->where('name', $name)->whereNull('deleted_at')->orderBy('id')->value('id');

        return $byName !== null ? (int) $byName : self::create($tenant, $userId, $rootId, $name);
    }

    private static function create(int $tenant, int $userId, ?int $parentId, string $name): int
    {
        return (int) DB::table('document_folders')->insertGetId([
            'sub_institute_id' => $tenant,
            'owner_id' => null,
            'parent_id' => $parentId,
            'name' => $name,
            'visibility' => 'organization',
            'sort_order' => 0,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
