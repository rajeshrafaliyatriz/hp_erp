<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `POST /api/product-master/move`: one or several Sub-products (and catalogue items) go under another Product (S15 follow-up, 24 Nov 2026, decision D-6; contract section 4.2).
 *
 * The change of Product is written by the ONE path ProductWriter uses when the form changes a Product: ProductMasterSync::mirror(). It writes `parent_id` of the master row, the
 * `parent_product` group of the About (offer) and the Product line of the Sell item (`crm_products.product_master_id`) in one transaction, and gives the Sub-product the key its new
 * Product gives it when that key is free, so the master row and its About agree (the checker's I5). Nothing here writes those columns by hand. The pointers of a Sub-product (leads,
 * campaigns, opportunities) are on the Sub-product's own id and do not change.
 *
 * All or nothing: every id is looked up in the caller's own organisation (another organisation's id is a 404, the same as one that is not there), every right is checked before the
 * first write, and the writes run in one transaction. A Sub-product already under the target is "unchanged". One `product.moved` line is written per Sub-product that moved.
 */
final class ProductMover
{
    /** How many Sub-products one call may move (a page of the list holds at most 100). */
    public const MAX_IDS = 500;

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public function __construct(private readonly array $identity)
    {
    }

    /**
     * @param  mixed  $ids  the `subProductIds` of the request
     * @param  mixed  $toProductId  the `toProductId` of the request
     * @return array{moved: list<int>, unchanged: list<int>, toProductId: int}
     *
     * @throws ProductRemovalRefused 403 a record the caller may not change, 404 an id that is not visible, 422 nothing to move or a target that is not an active Product
     */
    public function move(mixed $ids, mixed $toProductId): array
    {
        $tenant = (int) $this->identity['sub_institute_id'];
        $ids = $this->idsOf($ids);
        if ($ids === []) {
            throw ProductRemovalRefused::invalid('nothing_to_move', 'Choose at least one Sub-product to move.');
        }
        if (count($ids) > self::MAX_IDS) {
            throw ProductRemovalRefused::invalid('too_many', 'Move at most ' . self::MAX_IDS . ' Sub-products at a time.');
        }
        $target = $this->targetOf($toProductId);

        $rows = ProductMaster::visibleTo($tenant)->whereIn('id', $ids)->get()->keyBy(fn (ProductMaster $r) => (int) $r->id);
        if ($rows->count() !== count($ids)) {
            throw ProductRemovalRefused::notFound();
        }
        $ordered = collect($ids)->map(fn (int $id) => $rows[$id]);
        foreach ($ordered as $row) {
            if ($row->kind === ProductMaster::KIND_PRODUCT) {
                throw ProductRemovalRefused::invalid('not_a_sub_product', 'A Product cannot be moved under another Product. Choose Sub-products.');
            }
        }
        foreach ($ordered as $row) {
            if (($refusal = self::refusal($this->identity, $row)) !== null) {
                throw ProductRemovalRefused::forbidden($refusal);
            }
        }

        $events = new ProductChangeEvents($tenant, (int) $this->identity['user_id']);
        $result = DB::transaction(function () use ($ordered, $target, $events): array {
            $done = $this->moveRows($ordered, $target, $events);
            $events->flushAfterCommit();

            return $done;
        });

        return $result + ['toProductId' => (int) $target->id];
    }

    /**
     * Moves rows that have been looked up and checked, inside the caller's transaction (ProductRemoval moves the Sub-products of a deleted Product with it). The caller flushes
     * `$events` after its own commit.
     *
     * @param  Collection<int, ProductMaster>  $rows
     * @return array{moved: list<int>, unchanged: list<int>}
     *
     * @throws ProductRemovalRefused 422 the Product cannot take the Sub-product (it has no Portfolio name for an About to sit under)
     */
    public function moveRows(Collection $rows, ProductMaster $target, ProductChangeEvents $events): array
    {
        $tenant = (int) $this->identity['sub_institute_id'];
        $sync = new ProductMasterSync(null, true, (int) $this->identity['user_id']);
        $names = ProductMaster::visibleTo($tenant)->whereIn('id', $rows->pluck('parent_id')->filter()->unique()->all())->pluck('name', 'id');
        $moved = [];
        $unchanged = [];
        foreach ($rows as $row) {
            if ((int) ($row->parent_id ?? 0) === (int) $target->id) {
                $unchanged[] = (int) $row->id;

                continue;
            }
            try {
                $sync->mirror($tenant, (int) $row->id, ['parent_id' => (int) $target->id]);
            } catch (ProductMasterInvalid $e) {
                throw ProductRemovalRefused::invalid('invalid_target', $e->getMessage());
            }
            $events->moved($row, $row->parent_id !== null ? ($names[(int) $row->parent_id] ?? null) : null, (int) ($row->parent_id ?? 0), (string) $target->name, (int) $target->id);
            $moved[] = (int) $row->id;
        }

        return ['moved' => $moved, 'unchanged' => $unchanged];
    }

    /**
     * The Product a Sub-product may be moved under: one of this organisation's own, an active Product (kind `product`).
     *
     * An id that is not one of the organisation's rows at all (another organisation's, or one nobody has) is a 404 like every other id of these routes, and says nothing about which
     * of the two it is. An id that is the organisation's own but is no place to move to (a Sub-product, an archived Product, the Product being deleted) and a value that is no id
     * (missing, 0, text) are a 422 `invalid_target`.
     *
     * @throws ProductRemovalRefused 404 not one of this organisation's rows, 422 `invalid_target`
     */
    public function targetOf(mixed $toProductId, ?int $notThis = null): ProductMaster
    {
        $id = is_int($toProductId) || (is_string($toProductId) && ctype_digit($toProductId)) ? (int) $toProductId : 0;
        $row = $id > 0 ? ProductMaster::visibleTo((int) $this->identity['sub_institute_id'])->find($id) : null;
        if ($id > 0 && $row === null) {
            throw ProductRemovalRefused::notFound();
        }
        if ($row === null || $id === $notThis || $row->kind !== ProductMaster::KIND_PRODUCT || $row->status !== ProductMaster::STATUS_ACTIVE) {
            throw ProductRemovalRefused::invalid('invalid_target', 'Choose one of your own active products to move them to.');
        }

        return $row;
    }

    /**
     * Why the caller may not move this record, or null when it may. The writer's own rule for a change of Product (ProductWriter::updateRecord): a record that holds an About is the
     * Portfolio's (the Sell copy follows with it), a record with only a Sell side asks the EDIT right of its own type, and a record with neither is the Portfolio's too.
     *
     * @param array{user: object, user_id: int, sub_institute_id: int} $identity
     */
    public static function refusal(array $identity, ProductMaster $row): ?string
    {
        if ($row->offer_row_id === null && ($type = self::sellTypeOf($identity, $row)) !== null) {
            return ProductAccess::canWriteCatalogue($identity, $type, 'edit') ? null : 'You do not have the right to move this item (the Sell section).';
        }

        return ProductAccess::canWriteOffers($identity, 'edit') ? null : 'You do not have the right to move this sub-product (the About section belongs to the Product Portfolio).';
    }

    /** The item type (product or service) of the record's Sell row in this organisation, also one in the Recycle Bin; null when it has none. @param array{sub_institute_id: int} $identity */
    public static function sellTypeOf(array $identity, ProductMaster $row): ?string
    {
        if ($row->crm_product_id === null || ! Schema::hasTable('crm_products') || ! in_array('item_type', ColumnExists::all('crm_products'), true)) {
            return null;
        }
        $type = DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $identity['sub_institute_id'])->value('item_type');

        return $type === null ? null : (string) $type;
    }

    /** @return list<int> the ids asked for, each once, in the order sent; an entry that is no whole number above 0 is kept as 0 so the lookup fails it (a 404) */
    private function idsOf(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $out[] = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
        }

        return array_values(array_unique($out));
    }
}
