<?php

namespace App\Domain\ProductMaster;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Products\CustomProduct;
use App\Domain\Signals\Products\CustomProductRules;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The writes of the Product Master screen (slice 1, task 6). Each one runs in ONE transaction, in the order offer, catalogue item, Product Master
 * row, so a failure leaves nothing half-saved; and every access check is made BEFORE the first write, so a refusal writes nothing at all.
 *
 * It writes THROUGH the screens that already own the data, with the same rules they have: an offer follows PortfolioController (required offer id,
 * product and readiness, a 409 on a repeated offer id, readiness never confirmed here, shared offers read-only), a catalogue item follows
 * CrmProductController (item type, an organisation's own tax rate, the next PRD- or SVC- number). Prices are NOT written here beyond a base price:
 * a quote is priced from a price book (owner decision, 2026-11-02).
 *
 * Input is camelCase: kind, name, key, productId, offerId, readiness, about{whatItDoes, modulesComponents, needsSolved[], primarySegments[],
 * triggerSignals[], deploymentModel, proofPoints, notes}, sell{enabled, type, sku, glAccount, taxRateId, basePrice, currency}.
 */
final class ProductWriter
{
    private const ABOUT_MAP = [
        'whatItDoes' => 'what_it_does', 'modulesComponents' => 'modules_components', 'needsSolved' => 'needs_solved',
        'primarySegments' => 'primary_segments', 'triggerSignals' => 'trigger_signals', 'deploymentModel' => 'deployment_model',
        'proofPoints' => 'proof_points', 'notes' => 'notes',
    ];

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public function create(array $identity, array $input): ProductMaster
    {
        return match ((string) ($input['kind'] ?? '')) {
            ProductMaster::KIND_PRODUCT => $this->createProduct($identity, $input),
            ProductMaster::KIND_SUB_PRODUCT => $this->createSubProduct($identity, $input),
            ProductMaster::KIND_ITEM => $this->createItem($identity, $input),
            default => throw ProductMasterInvalid::field('kind', 'kind must be product, sub_product or item'),
        };
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public function update(array $identity, ProductMaster $row, array $input): ProductMaster
    {
        $this->ensureOwn($identity, $row);

        return match ($row->kind) {
            ProductMaster::KIND_PRODUCT => $this->updateProduct($identity, $row, $input),
            ProductMaster::KIND_SUB_PRODUCT => $this->updateSubProduct($identity, $row, $input),
            default => $this->updateItem($identity, $row, $input),
        };
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public function setStatus(array $identity, ProductMaster $row, string $status): ProductMaster
    {
        $this->ensureOwn($identity, $row);
        if ($row->kind === ProductMaster::KIND_PRODUCT && ! ProductAccess::isAdministrator($identity)) {
            throw ProductMasterInvalid::field('status', 'Only an administrator can archive or restore a product.', 403);
        }
        if ($status === ProductMaster::STATUS_ARCHIVED && $row->kind === ProductMaster::KIND_PRODUCT
            && ProductMaster::visibleTo($identity['sub_institute_id'])->where('parent_id', $row->id)->where('status', ProductMaster::STATUS_ACTIVE)->exists()) {
            throw ProductMasterInvalid::field('status', 'This product still has active sub-products. Archive them first.', 409);
        }
        if ($status === ProductMaster::STATUS_ACTIVE && $row->parent_id !== null
            && ProductMaster::visibleTo($identity['sub_institute_id'])->where('id', $row->parent_id)->where('status', ProductMaster::STATUS_ARCHIVED)->exists()) {
            throw ProductMasterInvalid::field('status', 'Restore its product first.', 409);
        }

        $row->update(['status' => $status, 'updated_by' => $identity['user_id']]);

        return $row->fresh();
    }

    // ---- create ----

    private function createProduct(array $identity, array $input): ProductMaster
    {
        if (! ProductAccess::isAdministrator($identity)) {
            throw ProductMasterInvalid::field('kind', 'Only an administrator can create a product.', 403);
        }
        $this->validate($input, ['name' => 'required|string|min:2|max:50', 'key' => 'required|string|max:50']);

        $tenant = $identity['sub_institute_id'];
        $key = strtolower(trim((string) $input['key']));
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) $input['name']));
        if (! Schema::hasTable('g2g_signal_products')) {
            throw ProductMasterInvalid::field('key', 'Own products are not available yet: the g2g_signal_products table has not been created.', 409);
        }

        // The same rules and the same row as ProductCatalog::createCustom (without its describe() read-back).
        ProductCatalog::forgetCustom();
        $problems = CustomProductRules::problems($key, $label, ProductCatalog::definitions(), ProductCatalog::customFor($tenant));
        if ($problems !== []) {
            throw $this->fromProblems($problems);
        }

        try {
            $row = DB::transaction(function () use ($identity, $tenant, $key, $label) {
                CustomProduct::create([
                    'sub_institute_id' => $tenant, 'key' => $key, 'label' => $label, 'business_fit' => $key, 'icp_product_line' => $key,
                    'portfolio_names' => [$label], 'status' => 'active', 'created_by' => $identity['user_id'],
                ]);

                return $this->masterRow($identity, [
                    'product_key' => $key, 'kind' => ProductMaster::KIND_PRODUCT, 'name' => $label, 'origin' => 'custom',
                    'signal_key' => $key, 'portfolio_name' => $label,
                ]);
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e) ? ProductMasterInvalid::field('key', 'You already have a product with this key.', 409) : $e;
        } finally {
            ProductCatalog::forgetCustom();
        }

        return $row;
    }

    private function createSubProduct(array $identity, array $input): ProductMaster
    {
        if (! ProductAccess::canWriteOffers($identity, 'add')) {
            throw ProductMasterInvalid::field('kind', 'You do not have the right to add to the Product Portfolio.', 403);
        }
        $this->validate($input, $this->subProductRules(create: true));
        $sell = $this->sellInput($input);
        if ($sell !== null && ! ProductAccess::canWriteCatalogue($identity, $sell['type'], 'add')) {
            throw ProductMasterInvalid::field('sell', 'You do not have the right to add to the CRM catalogue.', 403);
        }
        $this->checkReadiness((string) $input['readiness']);
        $this->checkTaxRate($identity, $sell);

        $tenant = $identity['sub_institute_id'];
        $parent = ProductMaster::visibleTo($tenant)->active()->where('kind', ProductMaster::KIND_PRODUCT)->find((int) $input['productId']);
        if (! $parent) {
            throw ProductMasterInvalid::field('productId', 'Choose one of your products.');
        }
        $offerId = trim((string) $input['offerId']);
        $key = $parent->product_key . '/' . strtolower($offerId);
        if (strlen($key) > 50) {
            throw ProductMasterInvalid::field('offerId', 'This ID is too long for this product.');
        }
        $clash = ProductOffer::query()->where('offer_id', $offerId)->where(fn ($q) => $q->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id'))->exists();
        if ($clash || ProductMaster::visibleTo($tenant)->where('product_key', $key)->exists()) {
            throw ProductMasterInvalid::field('offerId', 'An offer with this ID already exists.', 409);
        }

        try {
            return DB::transaction(function () use ($identity, $input, $parent, $offerId, $key, $sell) {
                $offer = ProductOffer::create($this->offerFields($input, [
                    'sub_institute_id' => $identity['sub_institute_id'], 'offer_id' => $offerId, 'name' => trim((string) $input['name']),
                    'parent_product' => (string) $parent->portfolio_name, 'readiness_status' => (string) $input['readiness'],
                    // Never set here: readiness is confirmed only by a person, through the Portfolio's own audited action.
                    'readiness_confirmed' => false, 'is_user_edited' => true, 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
                ]));
                $crmId = $sell !== null ? $this->createCatalogueRow($identity, $sell, trim((string) $input['name'])) : null;

                return $this->masterRow($identity, [
                    'product_key' => $key, 'kind' => ProductMaster::KIND_SUB_PRODUCT, 'parent_id' => $parent->id, 'name' => trim((string) $input['name']),
                    'origin' => 'portfolio', 'offer_row_id' => $offer->id, 'crm_product_id' => $crmId,
                ]);
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e) ? ProductMasterInvalid::field('offerId', 'This sub-product was just created by someone else. Reload and try again.', 409) : $e;
        }
    }

    private function createItem(array $identity, array $input): ProductMaster
    {
        $this->validate($input, ['name' => 'required|string|min:2|max:191', 'sell' => 'required|array']);
        $sell = $this->sellInput($input, forced: true);
        if (! ProductAccess::canWriteCatalogue($identity, $sell['type'], 'add')) {
            throw ProductMasterInvalid::field('sell', 'You do not have the right to add to the CRM catalogue.', 403);
        }
        $this->checkTaxRate($identity, $sell);

        try {
            return DB::transaction(function () use ($identity, $input, $sell) {
                $crmId = $this->createCatalogueRow($identity, $sell, trim((string) $input['name']));
                $no = (string) DB::table('crm_products')->where('id', $crmId)->value('product_no');

                return $this->masterRow($identity, [
                    'product_key' => substr('crm/' . strtolower($no), 0, 50), 'kind' => ProductMaster::KIND_ITEM, 'name' => trim((string) $input['name']),
                    'origin' => 'crm', 'crm_product_id' => $crmId,
                ]);
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e) ? ProductMasterInvalid::field('name', 'This item was just created by someone else. Reload and try again.', 409) : $e;
        }
    }

    // ---- update ----

    private function updateProduct(array $identity, ProductMaster $row, array $input): ProductMaster
    {
        if (! ProductAccess::isAdministrator($identity)) {
            throw ProductMasterInvalid::field('kind', 'Only an administrator can change a product.', 403);
        }
        if (array_key_exists('name', $input) && trim((string) $input['name']) !== (string) $row->name) {
            throw ProductMasterInvalid::field('name', 'Renaming a product is not supported yet: the Portfolio groups its sub-products by this name.');
        }

        return $row->fresh();
    }

    private function updateSubProduct(array $identity, ProductMaster $row, array $input): ProductMaster
    {
        if (! ProductAccess::canWriteOffers($identity, 'edit')) {
            throw ProductMasterInvalid::field('kind', 'You do not have the right to edit the Product Portfolio.', 403);
        }
        $this->validate($input, $this->subProductRules(create: false));
        $tenant = $identity['sub_institute_id'];

        $offer = $row->offer_row_id !== null ? ProductOffer::query()->where('id', $row->offer_row_id)->where('sub_institute_id', $tenant)->first() : null;
        if ($offer === null) {
            throw ProductMasterInvalid::field('offer', 'This sub-product has no offer of your own to edit. A shared offer is read-only here.', 409);
        }
        if (array_key_exists('readiness', $input) && (string) $input['readiness'] !== (string) $offer->readiness_status) {
            $this->checkReadiness((string) $input['readiness']);
        }
        $sell = array_key_exists('sell', $input) ? $this->sellInput($input) : null;
        $crm = $row->crm_product_id !== null ? DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->first() : null;
        $this->checkSellChange($identity, $input, $sell, $crm);
        $this->checkTaxRate($identity, $sell);

        return DB::transaction(function () use ($identity, $row, $input, $offer, $sell, $crm) {
            $fields = [];
            if (array_key_exists('name', $input)) {
                $fields['name'] = trim((string) $input['name']);
            }
            if (array_key_exists('readiness', $input)) {
                $fields['readiness_status'] = (string) $input['readiness'];
            }
            $fields = $this->offerFields($input, $fields, partial: true);
            if ($fields !== []) {
                $offer->update($fields + ['is_user_edited' => true, 'updated_by' => $identity['user_id']]);
            }

            $crmId = $row->crm_product_id;
            if ($sell !== null && $crm === null) {
                $crmId = $this->createCatalogueRow($identity, $sell, (string) ($fields['name'] ?? $row->name));
            } elseif ($crm !== null) {
                $this->updateCatalogueRow($identity, (int) $crm->id, $sell, $fields['name'] ?? null);
            }
            $row->update(array_filter([
                'name' => $fields['name'] ?? null, 'crm_product_id' => $crmId !== $row->crm_product_id ? $crmId : null, 'updated_by' => $identity['user_id'],
            ], fn ($v) => $v !== null));

            return $row->fresh();
        });
    }

    private function updateItem(array $identity, ProductMaster $row, array $input): ProductMaster
    {
        $this->validate($input, ['name' => 'sometimes|required|string|min:2|max:191']);
        $tenant = $identity['sub_institute_id'];
        $crm = $row->crm_product_id !== null ? DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->first() : null;
        if ($crm === null) {
            throw ProductMasterInvalid::field('item', 'This item has no catalogue entry of your own to edit.', 409);
        }
        $sell = array_key_exists('sell', $input) ? $this->sellInput($input, forced: true, fallbackType: (string) $crm->item_type) : null;
        if (! ProductAccess::canWriteCatalogue($identity, (string) $crm->item_type, 'edit')) {
            throw ProductMasterInvalid::field('sell', 'You do not have the right to edit the CRM catalogue.', 403);
        }
        $this->checkTaxRate($identity, $sell);

        return DB::transaction(function () use ($identity, $row, $input, $crm, $sell) {
            $name = array_key_exists('name', $input) ? trim((string) $input['name']) : null;
            $this->updateCatalogueRow($identity, (int) $crm->id, $sell, $name);
            $row->update(array_filter(['name' => $name, 'updated_by' => $identity['user_id']], fn ($v) => $v !== null));

            return $row->fresh();
        });
    }

    // ---- shared pieces ----

    /** @return array<string, string> */
    private function subProductRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes|required';

        return [
            'name' => $req . '|string|min:2|max:191',
            'readiness' => $req . '|string|max:100',
            'offerId' => $create ? 'required|string|max:50' : 'prohibited',
            'productId' => $create ? 'required|integer' : 'prohibited',
            'about' => 'nullable|array',
            'about.whatItDoes' => 'nullable|string', 'about.modulesComponents' => 'nullable|string', 'about.deploymentModel' => 'nullable|string|max:100',
            'about.proofPoints' => 'nullable|string', 'about.notes' => 'nullable|string',
            'about.needsSolved' => 'nullable|array|max:60', 'about.needsSolved.*' => 'string|max:400',
            'about.primarySegments' => 'nullable|array|max:60', 'about.primarySegments.*' => 'string|max:160',
            'about.triggerSignals' => 'nullable|array|max:60', 'about.triggerSignals.*' => 'string|max:160',
            'sell' => 'nullable|array',
        ];
    }

    /** Applies the Portfolio columns the form can set. In a partial update only the keys that were sent. */
    private function offerFields(array $input, array $base, bool $partial = false): array
    {
        $about = (array) ($input['about'] ?? []);
        $out = $base;
        foreach (self::ABOUT_MAP as $camel => $column) {
            if ($partial && ! array_key_exists($camel, $about)) {
                continue;
            }
            $arrays = in_array($column, ['needs_solved', 'primary_segments', 'trigger_signals'], true);
            $out[$column] = $arrays ? array_values((array) ($about[$camel] ?? [])) : ($about[$camel] ?? null);
        }
        if (! $partial) {
            $out += ['bundles_with' => []];
        }

        return $out;
    }

    /**
     * The sell section as a validated array, or null when it is absent or switched off.
     *
     * @return array{type:string, sku:?string, gl_account:?string, tax_rate_id:?int, base_price:?float, currency:?string}|null
     */
    private function sellInput(array $input, bool $forced = false, ?string $fallbackType = null): ?array
    {
        $sell = $input['sell'] ?? null;
        if (! is_array($sell) || (! $forced && ! filter_var($sell['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))) {
            return null;
        }
        $this->validate(['sell' => $sell], [
            'sell.type' => 'nullable|in:product,service', 'sell.sku' => 'nullable|string|max:100', 'sell.glAccount' => 'nullable|string|max:100',
            'sell.taxRateId' => 'nullable|integer', 'sell.basePrice' => 'nullable|numeric|min:0', 'sell.currency' => 'nullable|string|max:10',
        ]);

        return [
            'type' => (string) ($sell['type'] ?? $fallbackType ?? 'product'),
            'sku' => isset($sell['sku']) && $sell['sku'] !== '' ? (string) $sell['sku'] : null,
            'gl_account' => isset($sell['glAccount']) && $sell['glAccount'] !== '' ? (string) $sell['glAccount'] : null,
            'tax_rate_id' => isset($sell['taxRateId']) && $sell['taxRateId'] !== '' ? (int) $sell['taxRateId'] : null,
            'base_price' => isset($sell['basePrice']) && $sell['basePrice'] !== '' ? (float) $sell['basePrice'] : null,
            'currency' => isset($sell['currency']) && $sell['currency'] !== '' ? (string) $sell['currency'] : null,
        ];
    }

    private function checkSellChange(array $identity, array $input, ?array $sell, ?object $crm): void
    {
        if (array_key_exists('sell', $input) && is_array($input['sell']) && $sell === null && $crm !== null) {
            throw ProductMasterInvalid::field('sell', 'Taking a sub-product out of the CRM catalogue is not supported yet. Archive it instead.', 409);
        }
        if ($sell === null) {
            return;
        }
        $needs = $crm === null ? 'add' : 'edit';
        if (! ProductAccess::canWriteCatalogue($identity, $sell['type'], $needs)) {
            throw ProductMasterInvalid::field('sell', 'You do not have the right to ' . $needs . ' in the CRM catalogue.', 403);
        }
    }

    /** The readiness states a sub-product may be given: the same list the Portfolio offers, and the list the screen's form shows. @return list<string> */
    public static function readinessStatuses(): array
    {
        return array_map('strval', array_keys((array) config('g2g_taxonomy.readiness_statuses', [])));
    }

    private function checkReadiness(string $value): void
    {
        $allowed = self::readinessStatuses();
        if ($allowed !== [] && ! in_array($value, $allowed, true)) {
            throw ProductMasterInvalid::field('readiness', 'Choose one of the listed readiness states.');
        }
    }

    private function checkTaxRate(array $identity, ?array $sell): void
    {
        if ($sell === null || $sell['tax_rate_id'] === null) {
            return;
        }
        if (! Schema::hasTable('crm_tax_rates') || ! DB::table('crm_tax_rates')->where('id', $sell['tax_rate_id'])->where('sub_institute_id', $identity['sub_institute_id'])->exists()) {
            throw ProductMasterInvalid::field('sell.taxRateId', 'That tax rate was not found.');
        }
    }

    private function createCatalogueRow(array $identity, array $sell, string $name): int
    {
        return (int) DB::table('crm_products')->insertGetId([
            'item_type' => $sell['type'], 'name' => $name, 'sku' => $sell['sku'], 'gl_account' => $sell['gl_account'], 'unit_price' => $sell['base_price'],
            'currency' => $sell['currency'], 'tax_rate_id' => $sell['tax_rate_id'], 'is_active' => true, 'assigned_to' => $identity['user_id'],
            'product_no' => ($sell['type'] === 'service' ? 'SVC-' : 'PRD-') . Str::upper(Str::random(8)),
            'sub_institute_id' => $identity['sub_institute_id'], 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function updateCatalogueRow(array $identity, int $crmId, ?array $sell, ?string $name): void
    {
        $data = [];
        if ($name !== null) {
            $data['name'] = $name;
        }
        if ($sell !== null) {
            $data += ['sku' => $sell['sku'], 'gl_account' => $sell['gl_account'], 'unit_price' => $sell['base_price'], 'currency' => $sell['currency'], 'tax_rate_id' => $sell['tax_rate_id']];
        }
        if ($data !== []) {
            DB::table('crm_products')->where('id', $crmId)->where('sub_institute_id', $identity['sub_institute_id'])->update($data + ['updated_by' => $identity['user_id'], 'updated_at' => now()]);
        }
    }

    private function masterRow(array $identity, array $fields): ProductMaster
    {
        return ProductMaster::create($fields + [
            'sub_institute_id' => $identity['sub_institute_id'], 'status' => ProductMaster::STATUS_ACTIVE, 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
            'parent_id' => null, 'signal_key' => null, 'portfolio_name' => null, 'offer_row_id' => null, 'crm_product_id' => null,
        ]);
    }

    /** Only the organisation's own rows can be written; the shared built-in rows (organisation 0) and other organisations' rows cannot. */
    private function ensureOwn(array $identity, ProductMaster $row): void
    {
        if ((int) $row->sub_institute_id === ProductMaster::SHARED) {
            throw ProductMasterInvalid::field('id', 'This is a built-in product shared by every organisation and cannot be changed here.', 403);
        }
        if ((int) $row->sub_institute_id !== $identity['sub_institute_id']) {
            throw new ProductMasterInvalid(['id' => ['Product not found']], 404);
        }
    }

    private function validate(array $input, array $rules): void
    {
        $v = Validator::make($input, $rules);
        if ($v->fails()) {
            throw new ProductMasterInvalid($v->errors()->toArray(), 422);
        }
    }

    /** Only a duplicate-key error is a conflict the caller can fix; any other database error is a real failure and is not hidden. */
    private static function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry');
    }

    /** @param list<string> $problems messages like "key: use 3 to 20 characters..." */
    private function fromProblems(array $problems): ProductMasterInvalid
    {
        $errors = [];
        foreach ($problems as $p) {
            [$field, $msg] = str_contains($p, ':') ? array_map('trim', explode(':', $p, 2)) : ['key', $p];
            $errors[$field === 'label' ? 'name' : $field][] = $msg;
        }

        return new ProductMasterInvalid($errors, 422);
    }
}
