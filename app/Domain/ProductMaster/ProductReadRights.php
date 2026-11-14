<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may SEE what through the unified product reads (slice 15, P2-E1; Docs/agentic-crm/briefs/S15-P2E1-read-api.md).
 *
 * The rule of the owner's merge: names, ids and lines are open to every signed-in user of the organisation (HR opens the Portfolio and has no row on the Products screen),
 * and everything priced or Sell-related (price, cost, commission, SKU, stock, the Sell history, what documents use the item) needs the VIEW right of the item's own
 * type, the Products row for a product and the Services row for a service (ProductAccess::canViewCatalogue, the same right the old `/crm/products?itemType=` picker needed).
 *
 * One instance per request: a MenuRight check is a couple of queries, and a list asks the same question for every row, so each answer is kept.
 */
final class ProductReadRights
{
    /** @var array<string, bool> item type => may view its Sell section */
    private array $view = [];

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public function __construct(private readonly array $identity)
    {
    }

    /** May the caller see prices, cost, commission, SKU and the Sell history of an item of this type? An unknown type is never viewable. */
    public function canViewSell(?string $itemType): bool
    {
        if ($itemType === null || ! in_array($itemType, CatalogueFields::ITEM_TYPES, true)) {
            return false;
        }

        return $this->view[$itemType] ??= ProductAccess::canViewCatalogue($this->identity, $itemType);
    }

    /** @return list<string> the item types whose Sell section the caller may see */
    public function viewableTypes(): array
    {
        return array_values(array_filter(CatalogueFields::ITEM_TYPES, fn (string $type): bool => $this->canViewSell($type)));
    }

    public function canViewAnySell(): bool
    {
        return $this->viewableTypes() !== [];
    }

    /**
     * May the caller see the Sell side of THIS record? The right is the one of the record's own item type (its catalogue row's `item_type`). A record with no catalogue row
     * has no type to ask about, so it asks whether the caller may view the Sell section of ANY type: such a record answers little, and a person who may see no price anywhere
     * is told nothing about whether a Sell side exists at all.
     */
    public function canViewSellOf(ProductMaster $row): bool
    {
        $type = $this->itemTypeOf($row);

        return $type === null ? $this->canViewAnySell() : $this->canViewSell($type);
    }

    /** The item type of the record's catalogue row (also one in the Recycle Bin), this organisation's own row only; null when it has none. */
    public function itemTypeOf(ProductMaster $row): ?string
    {
        if ($row->crm_product_id === null || ! Schema::hasTable('crm_products')) {
            return null;
        }
        $type = DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $this->identity['sub_institute_id'])->value('item_type');

        return $type === null ? null : (string) $type;
    }

    /**
     * What the caller may do, in the words of the writer's own rules (ProductWriter calls the same ProductAccess methods), so a screen never offers what would be
     * refused and never hides what would be accepted:
     *   createProduct     a new level-1 Product is an administrator's (ProductLines::assertMayCreate)
     *   createSubProduct  a person who may ADD the About (the Portfolio) or the Sell (either type); the routes no longer ask for the Products row's right
     *   editSubProduct    the same with EDIT
     *   viewSell          may see prices, cost and commission of at least one item type
     *   confirmMatches    the pair review: an administrator who also holds the Products row's edit right (the route asks for both)
     *
     * @return array{createProduct: bool, createSubProduct: bool, editSubProduct: bool, viewSell: bool, confirmMatches: bool}
     */
    public function can(): array
    {
        $identity = $this->identity;
        $administrator = ProductAccess::isAdministrator($identity);
        $mayWrite = fn (string $action): bool => ProductAccess::canWriteOffers($identity, $action)
            || ProductAccess::canWriteCatalogue($identity, 'product', $action)
            || ProductAccess::canWriteCatalogue($identity, 'service', $action);

        return [
            'createProduct' => $administrator,
            'createSubProduct' => $mayWrite('add'),
            'editSubProduct' => $mayWrite('edit'),
            'viewSell' => $this->canViewAnySell(),
            'confirmMatches' => $administrator && ProductAccess::canUseScreen($identity, 'edit'),
        ];
    }
}
