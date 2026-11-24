<?php

namespace App\Domain\ProductMaster;

use App\Domain\Signals\Products\CustomProduct;
use App\Domain\Signals\Products\CustomProductRules;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products at the top level of the Product Master (kind `product`, called a "line" in the CRM Products form): which ids are one, which one a name means, and
 * the ONE place a new one is written (slice 15, track P2-B1).
 *
 * Before this class the CRM Products form made a line inline in CrmProductController with its own private copy of the writer's logic, and the two had
 * drifted: the controller let anyone with the add right make a line (the writer: an administrator only), wrote a 50-character key into the 20-character
 * g2g_signal_products.key and skipped CustomProductRules (so a key could be a reserved word or have hyphens), and made the master row `origin = manual`
 * with no signal key while the writer makes `origin = custom` with the signal key. Now ProductWriter::createProduct (the key a person typed) and the CRM form
 * (a key made from the name) both call create(): same rights, same rules, same two rows, in one transaction, with the SAME key in both tables.
 *
 * Every method is tenant-scoped: the organisation reads and writes its own lines only (S15 follow-up, D-1: nothing is shared between organisations).
 */
final class ProductLines
{
    /** How many suffixes a clashing key is tried with: `_2` to `_99`. */
    private const MAX_SUFFIX = 99;

    /**
     * Whether this id is a Product the organisation may link: kind `product` (a Sub-product or a catalogue item is NOT a Product), its own.
     * An archived Product still counts, as it does for LeadLinks::productVisible: a record is filed under what it was about.
     */
    public static function isVisibleLine(int $tenantId, int $id): bool
    {
        return $tenantId > 0 && $id > 0 && Schema::hasTable('g2g_product_master')
            && ProductMaster::query()->visibleTo($tenantId)->where('kind', ProductMaster::KIND_PRODUCT)->whereKey($id)->exists();
    }

    /**
     * How many Sub-products still hang under one Product of the organisation (slice 15, D-9): the children of its master row that are not archived (a Sub-product, or a
     * catalogue item filed under it) plus every offer of the organisation that names the Product as its group, even when the master has no row for it (a legacy row).
     * An offer that is archived (its master row archived, or its readiness "Archived" as the product delete of main sets it) hangs under nothing, so it does not count.
     * A Sub-product seen in both stores is counted once (the master child holds the offer's row number).
     *
     * @param  list<string>  $portfolioNames the group names offers carry as `parent_product` (the Product's `portfolio_names`)
     */
    public static function subProductCount(int $tenantId, string $key, array $portfolioNames): int
    {
        $offers = [];
        $masterOnly = 0;
        $hasMaster = Schema::hasTable('g2g_product_master');
        if ($hasMaster) {
            $productIds = ProductMaster::query()->where('sub_institute_id', $tenantId)->where('kind', ProductMaster::KIND_PRODUCT)
                ->where(fn ($q) => $q->where('product_key', $key)->orWhere('signal_key', $key))->pluck('id')->all();
            if ($productIds !== []) {
                $children = ProductMaster::query()->visibleTo($tenantId)->whereIn('parent_id', $productIds)->where('status', '!=', ProductMaster::STATUS_ARCHIVED)->get(['id', 'offer_row_id']);
                foreach ($children as $child) {
                    if ($child->offer_row_id !== null) {
                        $offers[(int) $child->offer_row_id] = true;
                    } else {
                        $masterOnly++;
                    }
                }
            }
        }
        if ($portfolioNames !== [] && Schema::hasTable('g2g_product_offers')) {
            $query = DB::table('g2g_product_offers')->where('sub_institute_id', $tenantId)->whereIn('parent_product', $portfolioNames)
                ->where(fn ($q) => $q->whereNull('readiness_status')->orWhere('readiness_status', '!=', 'Archived'));
            if ($hasMaster) {
                $query->whereNotExists(fn ($archived) => $archived->selectRaw('1')->from('g2g_product_master as pm_archived')
                    ->whereColumn('pm_archived.offer_row_id', 'g2g_product_offers.id')->where('pm_archived.status', ProductMaster::STATUS_ARCHIVED));
            }
            foreach ($query->pluck('id') as $id) {
                $offers[(int) $id] = true;
            }
        }

        return $masterOnly + count($offers);
    }

    /** The existing Product with this name, in any letter case, the organisation's own; null when there is none. */
    public static function findByName(int $tenantId, string $name): ?ProductMaster
    {
        $name = self::tidy($name);
        if ($tenantId <= 0 || $name === '' || ! Schema::hasTable('g2g_product_master')) {
            return null;
        }

        return ProductMaster::query()->visibleTo($tenantId)->where('kind', ProductMaster::KIND_PRODUCT)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])->orderBy('id')->first();
    }

    /**
     * The Product a name means: the existing one (anyone who may write the item may use it) or, when there is none, a NEW one, which only an administrator may
     * make (the rule of ProductWriter::createProduct). Null for a blank name. A refusal writes nothing.
     *
     * @param  array{user:object, user_id:int, sub_institute_id:int}  $identity
     * @param  ?string  $wantedKey  the key the client asked for; when null or unusable the key is made from the name
     * @throws ProductMasterInvalid  403 not an administrator, 409 no free key, 422 the name breaks CustomProductRules
     */
    public static function resolveOrCreate(array $identity, string $name, ?string $wantedKey = null): ?int
    {
        $name = self::tidy($name);
        if ($name === '') {
            return null;
        }
        $existing = self::findByName($identity['sub_institute_id'], $name);
        if ($existing !== null) {
            return (int) $existing->id;
        }

        self::assertMayCreate($identity);

        return (int) self::create($identity, self::freeKey($identity['sub_institute_id'], trim((string) $wantedKey) !== '' ? (string) $wantedKey : $name), $name)->id;
    }

    /**
     * THE one place a Product is written: the organisation's own row in g2g_signal_products and its g2g_product_master row, in one transaction, with the same
     * key. The key and name must pass CustomProductRules (a key of 3 to 20 characters, not reserved, not a built-in product's, not already this organisation's).
     *
     * `$meta` is what the custom product API (POST /api/signals/icp/products) also takes: `description`, `category`, `logo` and `status` (`active` or `inactive`; anything else is
     * `active`, as ProductCatalog::createCustom has always read it). They are stored in the custom row exactly where createCustom stores them (a `__meta__` entry of
     * `portfolio_names`), so ProductCatalog::describe() shows them. With no `$meta` (the writer's own call) the row is what it always was: `portfolio_names` is the label alone.
     * The master row follows the custom row's status the way ProductMasterSync::customProduct reads a source that is not active: an inactive Product's master row is archived.
     *
     * @param  array{user:object, user_id:int, sub_institute_id:int}  $identity
     * @param  array{description?: ?string, category?: ?string, logo?: ?string, status?: ?string}  $meta
     * @throws ProductMasterInvalid  403 not an administrator, 409 the table is missing or the key was just taken, 422 the key or name breaks the rules (carries the rules' own `problems`)
     */
    public static function create(array $identity, string $key, string $label, array $meta = []): ProductMaster
    {
        self::assertMayCreate($identity);

        $tenant = $identity['sub_institute_id'];
        $key = strtolower(trim($key));
        $label = self::tidy($label);
        if (! Schema::hasTable('g2g_signal_products')) {
            throw ProductMasterInvalid::field('key', 'Own products are not available yet: the g2g_signal_products table has not been created.', 409);
        }

        // The same rules and the same rows as ProductCatalog::createCustom (without its describe() read-back).
        ProductCatalog::forgetCustom();
        $problems = CustomProductRules::problems($key, $label, ProductCatalog::definitions(), ProductCatalog::customFor($tenant));
        if ($problems !== []) {
            throw self::fromProblems($problems);
        }

        // The four optional fields, as createCustom reads them. A caller that sends none (ProductWriter::createProduct) gets the row it always got: the label alone.
        $names = [$label];
        $status = 'active';
        if ($meta !== []) {
            $status = in_array($meta['status'] ?? 'active', ['active', 'inactive'], true) ? (string) ($meta['status'] ?? 'active') : 'active';
            $names['__meta__'] = [
                'description' => isset($meta['description']) ? trim((string) $meta['description']) : null,
                'category' => isset($meta['category']) ? trim((string) $meta['category']) : null,
                'logo' => isset($meta['logo']) ? trim((string) $meta['logo']) : null,
            ];
        }

        try {
            return DB::transaction(function () use ($identity, $tenant, $key, $label, $names, $status) {
                CustomProduct::create([
                    'sub_institute_id' => $tenant, 'key' => $key, 'label' => $label, 'business_fit' => $key, 'icp_product_line' => $key,
                    'portfolio_names' => $names, 'status' => $status, 'created_by' => $identity['user_id'],
                ]);

                return ProductMaster::create([
                    'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => ProductMaster::KIND_PRODUCT, 'name' => $label,
                    'status' => $status === 'active' ? ProductMaster::STATUS_ACTIVE : ProductMaster::STATUS_ARCHIVED,
                    'origin' => 'custom', 'signal_key' => $key, 'portfolio_name' => $label, 'parent_id' => null, 'offer_row_id' => null, 'crm_product_id' => null,
                    'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
                ]);
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e) ? ProductMasterInvalid::field('key', 'You already have a product with this key.', 409) : $e;
        } finally {
            ProductCatalog::forgetCustom();
        }
    }

    /** Only an administrator makes a Product (level 1). The wording is the writer's: this is the same rule. @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function assertMayCreate(array $identity): void
    {
        if (! ProductAccess::isAdministrator($identity)) {
            throw ProductMasterInvalid::field('kind', 'Only an administrator can create a product.', 403);
        }
    }

    /**
     * A key that is valid and free for this organisation, made from what was wanted (a name, or a key a client sent): lower case, letters, digits and
     * underscores, 3 to 20 characters (ProductCatalog::keyFrom). When that key is taken, reserved, a built-in product's, or held by any row of the master or
     * of g2g_signal_products, the same key with `_2`, `_3`, ... is used, cut so the whole still fits in 20. Deterministic: the same state gives the same key.
     *
     * @throws ProductMasterInvalid  409 when none of the first 99 is free
     */
    public static function freeKey(int $tenantId, string $wanted): string
    {
        $base = ProductCatalog::keyFrom($wanted);
        ProductCatalog::forgetCustom();
        for ($n = 1; $n <= self::MAX_SUFFIX; $n++) {
            $candidate = $n === 1 ? $base : self::suffixed($base, $n);
            if (! self::keyTaken($tenantId, $candidate)) {
                return $candidate;
            }
        }

        throw ProductMasterInvalid::field('key', 'No free key was found for this product. Give it a different name.', 409);
    }

    /** `<base>_<n>` that still fits in 20 characters: the base is cut to leave room, and never ends in an underscore. */
    private static function suffixed(string $base, int $n): string
    {
        $tail = '_' . $n;

        return rtrim(substr($base, 0, 20 - strlen($tail)), '_') . $tail;
    }

    private static function keyTaken(int $tenantId, string $key): bool
    {
        // CustomProductRules is the authority on a key: its shape, the reserved words, the built-in products' keys, and this organisation's own custom products.
        foreach (CustomProductRules::problems($key, 'Probe', ProductCatalog::definitions(), ProductCatalog::customFor($tenantId)) as $problem) {
            if (str_starts_with($problem, 'key:')) {
                return true;
            }
        }
        // And the rows the rules cannot see: an inactive own product still holds its unique keys, and the master holds lines made by hand. Only the organisation's own rows
        // hold a key: the unique keys of the master are per organisation.
        if (Schema::hasTable('g2g_product_master')
            && DB::table('g2g_product_master')->where('sub_institute_id', $tenantId)->where(fn ($q) => $q->where('product_key', $key)->orWhere('signal_key', $key))->exists()) {
            return true;
        }

        return Schema::hasTable('g2g_signal_products')
            && DB::table('g2g_signal_products')->where('sub_institute_id', $tenantId)->where(fn ($q) => $q->where('key', $key)->orWhere('business_fit', $key)->orWhere('icp_product_line', $key))->exists();
    }

    /** A name as it is stored and compared: trimmed, with every run of white space made one space. */
    private static function tidy(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** @param list<string> $problems messages like "key: use 3 to 20 characters..." */
    private static function fromProblems(array $problems): ProductMasterInvalid
    {
        $errors = [];
        foreach ($problems as $p) {
            [$field, $msg] = str_contains($p, ':') ? array_map('trim', explode(':', $p, 2)) : ['key', $p];
            $errors[$field === 'label' ? 'name' : $field][] = $msg;
        }

        return new ProductMasterInvalid($errors, 422, '', array_values($problems));
    }

    /** Only a duplicate-key error is a conflict the caller can fix; any other database error is a real failure and is not hidden. */
    private static function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
