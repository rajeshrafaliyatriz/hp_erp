<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may delete or move a record, asked of many records at once without asking the database once per record (S15 follow-up, 24 Nov 2026, the cost of a preview found on the dev
 * database; see ProductPointerCounts).
 *
 * A right is a question about the CALLER and a menu row ("may this profile delete on the Products screen?"), not about the record, so the answer is remembered for the life of the
 * instance: a Product with 12 Sub-products asks "may the Portfolio's right delete?" once and not twelve times. The one thing that does depend on the record is the type (product or
 * service) of its Sell row; `sellTypes()` reads those for a whole list in ONE query.
 *
 * The rules are the ones ProductRemoval and ProductMover have always applied (their static `removeRefusal` and `refusal` build one of these for a single record):
 *   delete   a Product: an administrator. A Sub-product: the Portfolio's right when it has an About AND the right of its Sell type when it has a Sell row (deleting bins the Sell row);
 *            a record with neither: the Products row's right. The action is `delete`, as archive asks.
 *   move     the writer's rule for a change of Product: the Portfolio's `edit` right for a record that holds an About, the `edit` right of its type for a Sell-only record, and the
 *            Portfolio's for a record with neither.
 */
final class ProductChangeRights
{
    /** @var array<string, bool> */
    private array $memo = [];

    /** @var ?list<string> the columns of crm_products, read once */
    private ?array $catalogueColumns = null;

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public function __construct(private readonly array $identity)
    {
    }

    public function administrator(): bool
    {
        return $this->memo['administrator'] ??= ProductAccess::isAdministrator($this->identity);
    }

    public function offers(string $action): bool
    {
        return $this->memo["offers:{$action}"] ??= ProductAccess::canWriteOffers($this->identity, $action);
    }

    public function catalogue(string $type, string $action): bool
    {
        return $this->memo["catalogue:{$type}:{$action}"] ??= ProductAccess::canWriteCatalogue($this->identity, $type, $action);
    }

    public function screen(string $action): bool
    {
        return $this->memo["screen:{$action}"] ??= ProductAccess::canUseScreen($this->identity, $action);
    }

    /**
     * The item type (product or service) of the Sell row of each record, also one in the Recycle Bin, this organisation's own rows only; a record with no Sell row is left out.
     *
     * @param  Collection<int, ProductMaster>  $rows
     * @return array<int, string> master id => item type
     */
    public function sellTypes(Collection $rows): array
    {
        $crmIds = $rows->pluck('crm_product_id')->filter()->unique()->values()->all();
        if ($crmIds === [] || ! Schema::hasTable('crm_products')) {
            return [];
        }
        $this->catalogueColumns ??= ColumnExists::all('crm_products');
        if (! in_array('item_type', $this->catalogueColumns, true)) {
            return [];
        }
        $types = DB::table('crm_products')->where('sub_institute_id', $this->identity['sub_institute_id'])->whereIn('id', $crmIds)->pluck('item_type', 'id');
        $out = [];
        foreach ($rows as $row) {
            if ($row->crm_product_id !== null && isset($types[(int) $row->crm_product_id])) {
                $out[(int) $row->id] = (string) $types[(int) $row->crm_product_id];
            }
        }

        return $out;
    }

    /** Why the caller may not delete this record, or null when it may. `$type` is the item type of its Sell row (see sellTypes), null when it has none. */
    public function removeRefusal(ProductMaster $row, ?string $type): ?string
    {
        if ($row->kind === ProductMaster::KIND_PRODUCT) {
            return $this->administrator() ? null : 'Only an administrator can delete a product.';
        }
        if ($row->offer_row_id !== null && ! $this->offers('delete')) {
            return 'You do not have the right to delete this sub-product (the About section belongs to the Product Portfolio).';
        }
        if ($type !== null && ! $this->catalogue($type, 'delete')) {
            return 'You do not have the right to delete this sub-product (the Sell section).';
        }
        if ($row->offer_row_id === null && $type === null && ! $this->screen('delete')) {
            return 'You do not have the right to delete this sub-product.';
        }

        return null;
    }

    /** Why the caller may not move this record, or null when it may. */
    public function moveRefusal(ProductMaster $row, ?string $type): ?string
    {
        if ($row->offer_row_id === null && $type !== null) {
            return $this->catalogue($type, 'edit') ? null : 'You do not have the right to move this item (the Sell section).';
        }

        return $this->offers('edit') ? null : 'You do not have the right to move this sub-product (the About section belongs to the Product Portfolio).';
    }
}
