<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The fields of a CRM catalogue item (table `crm_products`) and the rules for them, in ONE place (slice 15, P2-C, task 1).
 *
 * Before this class the field map lived inside CrmProductController::payload() and the Product Master writer wrote six of the nineteen fields by hand, with
 * rules of its own. The owner's rule of 13 Nov 2026 is "one record, not linked records": the CRM Products form and the "Sell" section of the one Sub-product
 * form must read and write exactly the same columns under exactly the same rules, so both call this class. Nothing here changes what the CRM controller answers:
 * the map, the six validation rules, the number a new item gets and the columns a service never carries are the controller's own, moved.
 *
 * Three kinds of thing are NOT in the map, and tests/Unit/CatalogueFieldsScanTest.php fails when a column of the table is in neither place:
 *   - the columns the system fills (id, who and when, the organisation, the number): systemColumns(), each with the reason a form never sets it;
 *   - `item_type`, which is written once when the item is made and never changes (TYPE_COLUMN);
 *   - the product picture, which only the separate image upload sets (listed in systemColumns() with that reason).
 */
final class CatalogueFields
{
    /** The two kinds of catalogue item: a product (the Products screen) and a service (the Services screen). */
    public const ITEM_TYPES = ['product', 'service'];

    /** The column that says which of the two an item is. Written when the item is made; an item never becomes the other kind. */
    public const TYPE_COLUMN = 'item_type';

    /**
     * Request key => `crm_products` column: the map CrmProductController::payload() has always had, moved here unchanged.
     * `productMasterId` is the Product (level 1) the item sits under, the line the CRM form picks; the one-record writer sets it from the record's own Product.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'name' => 'name', 'sku' => 'sku', 'category' => 'category', 'glAccount' => 'gl_account',
        'description' => 'description', 'unitPrice' => 'unit_price', 'costPrice' => 'cost_price',
        'commissionRate' => 'commission_rate', 'currency' => 'currency', 'taxRateId' => 'tax_rate_id',
        'isActive' => 'is_active', 'vendor' => 'vendor', 'qtyInStock' => 'qty_in_stock',
        'qtyInDemand' => 'qty_in_demand', 'reorderLevel' => 'reorder_level', 'weight' => 'weight',
        'usageUnit' => 'usage_unit', 'assignedTo' => 'assigned_to',
        'productMasterId' => 'product_master_id',
    ];

    /** The columns a service never carries (stock and a vendor are product ideas): every write of a service sets them to NULL. */
    public const PRODUCT_ONLY = ['vendor', 'qty_in_stock', 'reorder_level', 'qty_in_demand'];

    /**
     * Other names the "Sell" section of the one form accepts for a field: the old Product Master drawer sends `basePrice`, the contract of the one-record writer
     * says `unitPrice` (basePrice stays accepted), and the field the CRM form calls "description" is "Description on quotes" on the Sub-product form.
     *
     * @var array<string, string>
     */
    public const SELL_ALIASES = ['basePrice' => 'unitPrice', 'descriptionOnQuotes' => 'description'];

    /** Every `crm_products` column the field map writes, and the type column. */
    public static function columns(): array
    {
        return array_values(array_unique([...array_values(self::MAP), self::TYPE_COLUMN]));
    }

    /**
     * The columns of the table that no form field writes, each with the reason. The scan test reads the migrations and fails for a column that is neither in the
     * map nor here, so a field added to the table later cannot be forgotten: it must be mapped (and so written and read by both doors) or explained.
     *
     * @return array<string, string> column => reason (30 characters or more)
     */
    public static function systemColumns(): array
    {
        return [
            'id' => 'the number of the row, made by the database when the row is inserted; a form never sets or changes it',
            'legacy_id' => 'the id the record had in the legacy system it was imported from; set only by that import, never by a form',
            'product_no' => 'the PRD- or SVC- number the system makes when the item is created (newProductNo); a person never types or changes it',
            'sub_institute_id' => 'the organisation of the row, always taken from the signed-in caller\'s token and never from the request body',
            'created_by' => 'who made the row; the writer stamps it from the caller\'s identity, a form cannot send it',
            'updated_by' => 'who changed the row last; the writer stamps it from the caller\'s identity, a form cannot send it',
            'deleted_by' => 'who moved the row to the Recycle Bin; set by that action (and cleared by a restore), never by a form',
            'created_at' => 'when the row was made; a timestamp the system sets on insert',
            'updated_at' => 'when the row was last changed; a timestamp the system sets on every write',
            'deleted_at' => 'the Recycle Bin mark: set by the delete action and cleared by a restore or by switching Sell in CRM on, never typed in a form',
            'image' => 'the product picture\'s file name; set only by the separate image upload call (POST /api/crm/products/{id}/image), keyed by the CRM id',
        ];
    }

    /**
     * The validation rules for the fields the CRM Products controller has always checked, in the order it checked them (the first message it answers with is the
     * first rule that fails, so the order is part of the behaviour). `$forUpdate` makes the two required fields "sometimes required", as on an edit.
     *
     * @return array<string, string>
     */
    public static function rules(bool $forUpdate): array
    {
        return [
            'name' => $forUpdate ? 'sometimes|required|string|max:191' : 'required|string|max:191',
            'assignedTo' => $forUpdate ? 'sometimes|required|integer' : 'required|integer',
            'unitPrice' => 'nullable|numeric',
            'costPrice' => 'nullable|numeric',
            'commissionRate' => 'nullable|numeric|min:0|max:100',
            'taxRateId' => 'nullable|integer',
        ];
    }

    /**
     * What the database holds for the fields (the size of each text column, the range of each number column, and that a number is a number). The CRM controller has
     * never checked these and still does not (its answers must not change); the one-record writer enforces them so a bad value is a 422 next to its field and not a
     * database error (strict mode is on: a value past a column is an error, so an unchecked request is a 500).
     *
     * The numbers (P2-F, G-6; the types are those of `SHOW COLUMNS FROM crm_products` on dev, 14 Nov 2026): a price, a cost and a stock are decimal(15,2) (up to
     * 9999999999999.99), a demand is decimal(12,2), a weight decimal(10,3), a reorder level an unsigned int, a tax rate and an assignee an unsigned bigint (a PHP integer
     * is smaller than the column, so only the lower bound matters). "No negatives": every one of them starts at zero (the commission rate, 0 to 100, is `rules()`'s).
     * A description is a `text` column of 65,535 BYTES, and a character can take four of them in utf8mb4: at most 16,383 characters can never overflow it.
     * tests/Unit/CatalogueFieldsScanTest.php (E2) reads the migrations and fails when a rule here, or in sellRules(), lets in more than its column holds.
     *
     * @return array<string, string>
     */
    public static function limits(): array
    {
        return [
            'sku' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'glAccount' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:16383', 'currency' => 'nullable|string|max:10', 'isActive' => 'nullable|boolean',
            'vendor' => 'nullable|string|max:191', 'usageUnit' => 'nullable|string|max:191',
            'unitPrice' => 'nullable|numeric|min:0|max:9999999999999.99', 'costPrice' => 'nullable|numeric|min:0|max:9999999999999.99',
            'qtyInStock' => 'nullable|numeric|min:0|max:9999999999999.99', 'qtyInDemand' => 'nullable|numeric|min:0|max:9999999999.99',
            'reorderLevel' => 'nullable|integer|min:0|max:4294967295', 'weight' => 'nullable|numeric|min:0|max:9999999.999',
            'taxRateId' => 'nullable|integer|min:0', 'assignedTo' => 'nullable|integer|min:0',
        ];
    }

    /**
     * The rules the one-record writer applies to each field of the Sell section, by request key, aliases included: the controller's own rules for the fields it
     * checks (`rules()`: the commission rate, 0 to 100), replaced by `limits()` wherever that says more, and each alias (`basePrice`, `descriptionOnQuotes`) under the
     * rule of the field it stands for, so the old drawer finds its error next to its own key. The record's own name is not here (the writer judges it with the record).
     * ProductWriter prefixes each key with `sell.`; the scan test (E2) reads this method, so it checks exactly the set the writer uses.
     *
     * @return array<string, string>
     */
    public static function sellRules(): array
    {
        $rules = array_replace(array_diff_key(self::rules(forUpdate: true), ['name' => true]), self::limits());
        foreach (self::SELL_ALIASES as $alias => $key) {
            $rules[$alias] = $rules[$key];
        }

        return $rules;
    }

    /**
     * The columns to write from a request: for each key of the map that the request has, its column. On an update only the keys the request has; on a create every
     * key, an absent one as NULL (a new item is active unless told otherwise). Moved line for line from CrmProductController::payload().
     *
     * @param  array<string, mixed>  $input  the request's input (CrmProductController passes $request->all())
     * @param  ?\Closure(): bool  $hasLineColumn  whether crm_products has `product_master_id` on this host; asked only when the request carries that key
     * @return array<string, mixed>
     */
    public static function payload(array $input, bool $forUpdate, ?\Closure $hasLineColumn = null): array
    {
        $data = [];

        foreach (self::MAP as $requestKey => $column) {
            if ($forUpdate && ! array_key_exists($requestKey, $input)) {
                continue;
            }

            if ($column === 'product_master_id') {
                if (! array_key_exists($requestKey, $input)) {
                    continue;
                }
                if ($hasLineColumn !== null && ! $hasLineColumn()) {
                    continue;
                }
            }

            $data[$column] = $input[$requestKey] ?? null;
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $data['is_active'] === null ? true : (bool) $data['is_active'];
        }

        if (array_key_exists('tax_rate_id', $data) && $data['tax_rate_id'] === '') {
            $data['tax_rate_id'] = null;
        }

        if (array_key_exists('product_master_id', $data)) {
            $data['product_master_id'] = ($data['product_master_id'] === '' || $data['product_master_id'] === null || (int) $data['product_master_id'] <= 0)
                ? null
                : (int) $data['product_master_id'];
        }

        return $data;
    }

    /**
     * A service never carries stock or a vendor: the same columns are set to NULL on every write of a service (the CRM controller's rule, which sets all four).
     * The one-record writer asks for `$onlyWhatIsThere`: it writes only the fields a request sent, so it nulls only those of the four that are in the write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyServiceRules(array $data, bool $onlyWhatIsThere = false): array
    {
        foreach (self::PRODUCT_ONLY as $column) {
            if (! $onlyWhatIsThere || array_key_exists($column, $data)) {
                $data[$column] = null;
            }
        }

        return $data;
    }

    /** The number a new item gets: PRD- for a product, SVC- for a service, then eight random upper-case characters. */
    public static function newProductNo(string $itemType): string
    {
        return ($itemType === 'service' ? 'SVC-' : 'PRD-') . Str::upper(Str::random(8));
    }

    /**
     * The "Sell" section of the one form, with the alias names turned into the map's names, and only the keys that are catalogue fields (not `type` or `enabled`,
     * which the writer reads itself, and not `name` or `productMasterId`, which belong to the record and not to its Sell section). A key under both names keeps
     * the map's own (`unitPrice` over `basePrice`).
     *
     * @param  array<string, mixed>  $sell
     * @return array<string, mixed>
     */
    public static function fromSell(array $sell): array
    {
        $out = [];
        foreach (self::SELL_ALIASES as $alias => $key) {
            if (array_key_exists($alias, $sell)) {
                $out[$key] = $sell[$alias];
            }
        }
        foreach (array_keys(self::MAP) as $key) {
            if (in_array($key, ['name', 'productMasterId'], true)) {
                continue;
            }
            if (array_key_exists($key, $sell)) {
                $out[$key] = $sell[$key];
            }
        }

        return $out;
    }

    /** The state of a catalogue row from the Sub-product's point of view: sold, paused (switched off, still there), or in the Recycle Bin. */
    public static function state(object $row): string
    {
        if (($row->deleted_at ?? null) !== null) {
            return 'binned';
        }

        return (bool) ($row->is_active ?? true) ? 'sold' : 'paused';
    }

    /**
     * The address of an item's picture. The table keeps only the file name (set by the separate upload call); the address is built when it is read, on the
     * `digitalocean` disk, exactly as CrmProductController::imageUrl builds it for the CRM page (a test compares the two). Null for no picture, or when the disk cannot say.
     */
    public static function imageUrl(?string $filename): ?string
    {
        $filename = trim((string) $filename);
        if ($filename === '') {
            return null;
        }

        try {
            return Storage::disk('digitalocean')->url('public/hp_crm_product/' . $filename);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The name of the item's default tax rate: the one a joined row already carries (`tax_rate_name`, as the CRM controller's queries select it), else looked up in the
     * organisation's own tax rates by the row's id. Null for no tax rate, for a rate that is gone, and for a host with no tax rate table.
     */
    private static function taxRateName(object $row): ?string
    {
        if (property_exists($row, 'tax_rate_name')) {
            return $row->tax_rate_name === null ? null : (string) $row->tax_rate_name;
        }
        $id = $row->tax_rate_id ?? null;
        if ($id === null || $id === '' || ! Schema::hasTable('crm_tax_rates')) {
            return null;
        }
        $name = DB::table('crm_tax_rates')->where('id', (int) $id)->where('sub_institute_id', $row->sub_institute_id ?? null)->value('name');

        return $name === null ? null : (string) $name;
    }

    /**
     * A catalogue row as the "Sell" section of a response shows it (the map read the other way round, numbers as numbers), with everything the old product page showed
     * (P2-F, G-7): the picture's address, the tax rate's name, the Product the item sits under, who made it and when it was made and last changed. The caller decides WHO
     * may see it: this carries prices, cost, commission and stock.
     *
     * @return array<string, mixed>
     */
    public static function toSell(object $row): array
    {
        // A host whose table lacks a column answers NULL for it (the row object has no such property), never a warning.
        $v = fn (string $column): mixed => $row->{$column} ?? null;
        $number = fn (string $column): ?float => $v($column) === null ? null : (float) $v($column);
        $whole = fn (string $column): ?int => $v($column) === null || $v($column) === '' ? null : (int) $v($column);

        return [
            'state' => self::state($row), 'crmProductId' => (int) $row->id, 'productNo' => $v('product_no'), 'type' => $v('item_type'),
            'sku' => $v('sku'), 'category' => $v('category'), 'glAccount' => $v('gl_account'), 'descriptionOnQuotes' => $v('description'),
            'unitPrice' => $number('unit_price'), 'costPrice' => $number('cost_price'), 'commissionRate' => $number('commission_rate'), 'currency' => $v('currency'),
            'taxRateId' => $whole('tax_rate_id'), 'isActive' => (bool) ($v('is_active') ?? true),
            'vendor' => $v('vendor'), 'qtyInStock' => $number('qty_in_stock'), 'qtyInDemand' => $number('qty_in_demand'), 'reorderLevel' => $whole('reorder_level'),
            'weight' => $number('weight'), 'usageUnit' => $v('usage_unit'), 'assignedTo' => $whole('assigned_to'),
            'imageUrl' => self::imageUrl(is_string($v('image')) ? $v('image') : null), 'taxRateName' => self::taxRateName($row), 'productMasterId' => $whole('product_master_id'),
            'createdBy' => $whole('created_by'), 'createdAt' => $v('created_at'), 'updatedAt' => $v('updated_at'),
        ];
    }
}
