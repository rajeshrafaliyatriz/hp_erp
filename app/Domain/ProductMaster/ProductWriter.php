<?php

namespace App\Domain\ProductMaster;

use App\Domain\Portfolio\OfferIcpSync;
use App\Domain\Portfolio\ProductOffer;
use App\Support\ColumnExists;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The ONE write path of the Product Master (slice 1 task 6; made the one-record writer by slice 15, P2-C, 13 Nov 2026).
 *
 * A Sub-product is ONE record on ONE master row. It may hold an About (the Portfolio offer, table g2g_product_offers), a Sell section (the CRM catalogue row,
 * crm_products) and its ICPs (g2g_icp_profiles, through OfferIcpSync), each optional. One call writes whatever sections it carries, in ONE database transaction:
 * a failure in any store leaves nothing behind. This is the door the unified screen calls; the old drawer's requests are still understood.
 *
 * Request (POST /api/product-master, PUT /api/product-master/{id}; camelCase):
 *   kind        sub_product | product | item (item = Sell only, kept for the old drawer; a request with only Sell makes an `item` whatever kind it names)
 *   name, productId (the level-1 Product: required with an About), offerId (required with an About on create, never changes after), readiness (required with an About)
 *   about       {whatItDoes, modulesComponents, deploymentModel, proofPoints, notes, needsSolved[], primarySegments[], triggerSignals[], pricingModel, typicalDealBand,
 *                implementationEffort, deliveryOwner, oftenSoldTogether[]}   (oftenSoldTogether is the offer's bundles_with: offer ids, each one an offer the
 *                organisation can see and never the offer itself, else a 422 on about.oftenSoldTogether.N; see oftenSoldTogetherChecked)
 *   icp         the payload PortfolioController sends as icp_configs / icp_config (OfferIcpSync)
 *   sell        {enabled, type, sku, category, glAccount, taxRateId, unitPrice (alias basePrice), costPrice, currency, commissionRate, vendor, usageUnit, qtyInStock,
 *                qtyInDemand, reorderLevel, weight, assignedTo, isActive, descriptionOnQuotes}   (CatalogueFields holds the map and the rules, for this door and the CRM form)
 *
 * The rules (the owner's decisions of 13 Nov 2026):
 *   1. at least one section; Sell alone makes an `item`; an About added to an `item` makes it a sub_product with the SAME key
 *   2. the four Sell states: not sold, sold, paused (`is_active` 0, switching Sell off, never a delete), in the Recycle Bin (switching Sell on brings the SAME row back)
 *   3. one name and one Product, written to the master row, the offer and the catalogue row together (ProductMasterSync::mirror)
 *   4. rights by SECTION: About and ICP need the Portfolio right, Sell the right of its type, a new Product an administrator; a refusal is a 403 that names the
 *      section and writes nothing for ANY section, and comes before the rest of the body is judged
 *   5. the response carries Sell only for a caller who may view it (the controller asks ProductAccess::canViewCatalogue)
 *   6. every store that changed gets an audit line, written after the commit (ProductWriteAudit)
 *   7. all stores or none (one transaction, the master row locked first)
 *   8. readiness is never confirmed here; a shared row can never carry Sell
 *   9. one switch (P2-F, D-1): a Sell section whose `enabled` and `isActive` contradict is a 422 on sell.isActive (see contradiction)
 *
 * Every access check is made BEFORE the first write, so a refusal writes nothing at all. Prices are NOT priced here: a quote is priced from a price book (owner
 * decision, 2026-11-02); the Sell section holds the catalogue's own fields.
 */
final class ProductWriter
{
    /** About key => `g2g_product_offers` column: the 13 fields of the About section (the offer's name, group and readiness are the other three of its 16). */
    private const ABOUT_MAP = [
        'whatItDoes' => 'what_it_does', 'modulesComponents' => 'modules_components', 'deploymentModel' => 'deployment_model', 'proofPoints' => 'proof_points',
        'notes' => 'notes', 'needsSolved' => 'needs_solved', 'primarySegments' => 'primary_segments', 'triggerSignals' => 'trigger_signals',
        'pricingModel' => 'pricing_model', 'typicalDealBand' => 'typical_deal_band', 'implementationEffort' => 'implementation_effort', 'deliveryOwner' => 'delivery_owner',
        'oftenSoldTogether' => 'bundles_with',
    ];

    /** The About columns that hold a list (stored as JSON text by the model's array cast). */
    private const ABOUT_LISTS = ['needs_solved', 'primary_segments', 'trigger_signals', 'bundles_with'];

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public function create(array $identity, array $input): ProductMaster
    {
        return match ((string) ($input['kind'] ?? '')) {
            ProductMaster::KIND_PRODUCT => $this->createProduct($identity, $input),
            ProductMaster::KIND_SUB_PRODUCT, ProductMaster::KIND_ITEM => $this->createRecord($identity, $input),
            default => throw ProductMasterInvalid::field('kind', 'kind must be product, sub_product or item'),
        };
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public function update(array $identity, ProductMaster $row, array $input): ProductMaster
    {
        // A built-in (shared) row can never carry Sell: say that, with its reason, before the general refusal of every write to a shared row.
        if ((int) $row->sub_institute_id === ProductMaster::SHARED && is_array($input['sell'] ?? null)) {
            throw ProductMasterInvalid::field('sell', 'A built-in product shared by every organisation cannot be sold. Sell one of your own sub-products.');
        }
        $this->ensureOwn($identity, $row);

        return $row->kind === ProductMaster::KIND_PRODUCT ? $this->updateProduct($identity, $row, $input) : $this->updateRecord($identity, $row, $input);
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

        $audit = new ProductWriteAudit($identity['sub_institute_id'], (int) $identity['user_id']);
        DB::transaction(function () use ($identity, $row, $status, $audit): void {
            $audit->watch('g2g_product_master', (int) $row->id);
            $row->update(['status' => $status, 'updated_by' => $identity['user_id']]);
            $audit->settle();
            DB::afterCommit(fn () => $audit->flush());
        });

        return $row->fresh();
    }

    // ---- create ----

    private function createProduct(array $identity, array $input): ProductMaster
    {
        // The refusal comes before the input is looked at, as it always did; ProductLines::create asks again, so it is safe to call from anywhere.
        ProductLines::assertMayCreate($identity);
        $this->validate($input, ['name' => 'required|string|min:2|max:50', 'key' => 'required|string|max:50']);

        // The rules and the two rows (g2g_signal_products and the master row) are written by ProductLines::create, the same method the CRM Products form's inline
        // "new product" uses, so the two paths cannot drift apart again.
        return ProductLines::create($identity, (string) $input['key'], (string) $input['name']);
    }

    /** A Sub-product, or an item that is Sell only: the sections the request carries decide which. */
    private function createRecord(array $identity, array $input): ProductMaster
    {
        $tenant = (int) $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $asked = (string) $input['kind'];

        // 1. The sections the request carries, read from its keys. Nothing is judged yet, except what decides which right applies (the type of the Sell section).
        $aboutOn = $this->aboutRequested($input, create: true);
        $icp = $this->icpOf($input);
        $sell = $this->sellOf($input);
        $enabled = $sell === null ? null : $this->enabledOf($sell);
        $sellOn = $sell !== null && ($asked === ProductMaster::KIND_ITEM ? $enabled !== false : $enabled === true);
        if ($asked === ProductMaster::KIND_ITEM && ($aboutOn || $icp !== [])) {
            throw ProductMasterInvalid::field('kind', 'A catalogue item is Sell only. Send kind sub_product to give it an About.');
        }
        $type = $sellOn ? $this->typeOf($sell, null) : null;

        // 2. The rights, section by section, before anything else about the body is looked at: a person who may write none of the sections present gets a 403, not a 422.
        $this->assertRights($identity, $aboutOn, $icp !== [], $sellOn ? [$type, 'add'] : null, 'add');
        if ($sell !== null && ($contradiction = $this->contradiction($sell, $enabled, 'none')) !== null) {
            throw $contradiction;
        }

        // 3. The shape of the request.
        if (! $aboutOn && $icp === [] && ! $sellOn) {
            throw ProductMasterInvalid::field('sections', 'Add an About, or switch Sell in CRM on: a sub-product needs at least one section.');
        }
        if ($icp !== [] && ! $aboutOn) {
            throw ProductMasterInvalid::field('icp', 'An ideal customer profile belongs to the About of a sub-product: add the About as well.');
        }
        $rules = ['name' => 'required|string|min:2|max:191', 'productId' => $aboutOn ? 'required|integer' : 'nullable|integer', 'about' => 'nullable|array', 'icp' => 'nullable|array'];
        if ($aboutOn) {
            $rules += ['offerId' => 'required|string|max:50', 'readiness' => 'required|string|max:100'] + $this->aboutRules();
        }
        if ($sellOn) {
            $rules += $this->sellRules();
        }
        $this->validate($input, $rules);

        // 4. What the data says: the Product, the offer id, the readiness, the tax rate.
        $name = trim((string) $input['name']);
        [$parentId, $parentKey] = $this->parentOf($input);
        $parent = null;
        $key = null;
        if ($aboutOn) {
            $this->checkReadiness((string) $input['readiness']);
            $parent = $this->productForOffer($tenant, $parentId, $parentKey);
            $offerId = trim((string) $input['offerId']);
            $key = $parent->product_key . '/' . strtolower($offerId);
            if (strlen($key) > 50) {
                throw ProductMasterInvalid::field('offerId', 'This ID is too long for this product.');
            }
            $this->assertOfferIdFree($tenant, $offerId, $key);
            $input = $this->oftenSoldTogetherChecked($tenant, $input, $offerId);
        } elseif ($parentId !== null && ! ProductLines::isVisibleLine($tenant, $parentId)) {
            // The Product of a catalogue item is a visible Product (level 1): a Sub-product's id, another organisation's and an id that is not there are refused (slice 15, D5).
            throw ProductMasterInvalid::field($parentKey, 'Choose one of your products.');
        }
        $fields = $sellOn ? CatalogueFields::fromSell($sell) : [];
        $this->checkTaxRate($tenant, $fields['taxRateId'] ?? null);

        // 5. The write: every store in ONE transaction, the audit lines after it.
        $audit = new ProductWriteAudit($tenant, $userId);
        try {
            return DB::transaction(function () use ($identity, $input, $name, $parent, $parentId, $key, $icp, $fields, $type, $aboutOn, $sellOn, $tenant, $userId, $audit): ProductMaster {
                $offer = null;
                if ($aboutOn) {
                    $offer = ProductOffer::create($this->offerAttributes($identity, $input, $parent, trim((string) $input['offerId']), $name));
                    $audit->created('g2g_product_offers', (int) $offer->id);
                    if ($icp !== []) {
                        app(OfferIcpSync::class)->sync($tenant, $userId, $offer, $icp);
                    }
                }
                $crmId = null;
                if ($sellOn) {
                    $crmId = $this->insertCatalogue($identity, (string) $type, $fields, $name, $aboutOn ? (int) $parent->id : $parentId);
                    $audit->created('crm_products', $crmId);
                }

                $row = $aboutOn
                    ? $this->masterRow($identity, ['product_key' => $key, 'kind' => ProductMaster::KIND_SUB_PRODUCT, 'parent_id' => $parent->id, 'name' => $name, 'origin' => 'portfolio', 'offer_row_id' => $offer->id, 'crm_product_id' => $crmId])
                    : $this->masterRow($identity, [
                        'product_key' => substr('crm/' . strtolower((string) DB::table('crm_products')->where('id', $crmId)->value('product_no')), 0, 50), 'kind' => ProductMaster::KIND_ITEM,
                        'parent_id' => $parentId, 'name' => $name, 'origin' => 'crm', 'crm_product_id' => $crmId,
                    ]);
                $audit->created('g2g_product_master', (int) $row->id);

                $audit->settle();
                DB::afterCommit(fn () => $audit->flush());

                return $row;
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e)
                ? ($aboutOn ? ProductMasterInvalid::field('offerId', 'This sub-product was just created by someone else. Reload and try again.', 409) : ProductMasterInvalid::field('name', 'This item was just created by someone else. Reload and try again.', 409))
                : $e;
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
        if (is_array($input['sell'] ?? null)) {
            throw ProductMasterInvalid::field('sell', 'A product is a group of sub-products and is never sold itself. Sell one of its sub-products.');
        }

        return $row->fresh();
    }

    /** A Sub-product or an item that already exists: every section the request carries, in ONE transaction, decided on what the row holds when it is locked. */
    private function updateRecord(array $identity, ProductMaster $given, array $input): ProductMaster
    {
        $tenant = (int) $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $audit = new ProductWriteAudit($tenant, $userId);

        return DB::transaction(function () use ($identity, $given, $input, $tenant, $userId, $audit): ProductMaster {
            // The row, locked and read again: two people saving at once take turns, and everything below is decided on what the row holds NOW.
            $row = ProductMaster::query()->where('sub_institute_id', $tenant)->whereKey($given->id)->lockForUpdate()->first();
            if ($row === null) {
                throw new ProductMasterInvalid(['id' => ['Product not found']], 404);
            }
            $offer = $row->offer_row_id !== null ? ProductOffer::query()->where('id', $row->offer_row_id)->where('sub_institute_id', $tenant)->first() : null;
            // The catalogue row, also when it is in the Recycle Bin: the record still points at it and a restore must bring THAT row back.
            $crm = $row->crm_product_id !== null ? DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->first() : null;

            // 1. The sections the request carries and what each would do to this row.
            $hasOfferPointer = $row->offer_row_id !== null;
            $aboutAsked = $this->aboutRequested($input, create: false);
            $newOffer = ! $hasOfferPointer && $aboutAsked;
            $icp = $this->icpOf($input);
            $sell = $this->sellOf($input);
            $enabled = $sell === null ? null : $this->enabledOf($sell);
            $plan = $sell === null ? null : $this->sellPlan($sell, $enabled, $crm);
            [$parentId, $parentKey, $parentAsked] = $this->parentOf($input);
            // A name that is no text is judged by the rules below; here it only has to compare as different.
            $name = array_key_exists('name', $input) ? (is_scalar($input['name']) ? trim((string) $input['name']) : '') : (string) $row->name;
            $nameChanged = array_key_exists('name', $input) && $name !== (string) $row->name;
            $parentChanged = $parentAsked && (int) ($parentId ?? 0) !== (int) ($row->parent_id ?? 0);
            $identityChanged = $nameChanged || $parentChanged;
            if ($icp !== [] && ! $hasOfferPointer && ! $aboutAsked) {
                throw ProductMasterInvalid::field('icp', 'An ideal customer profile belongs to the About of a sub-product: add the About as well.');
            }

            // 2. The rights: About and ICP (and a change of name or Product while an offer holds them) are the Portfolio's; Sell is its type's. A record with no offer
            //    keeps its name and Product through its catalogue row's right.
            $aboutRight = $aboutAsked || ($identityChanged && ($hasOfferPointer || $newOffer));
            $sellNeed = $plan !== null && $plan['right'] !== null ? [$plan['type'], $plan['right']] : null;
            $renameNeed = null;
            if ($identityChanged && ! $hasOfferPointer && ! $newOffer) {
                if ($crm !== null) {
                    // Whatever else the request does to the row (an add right may bring it back from the Bin), a new name or Product is an EDIT of it.
                    $renameNeed = [(string) $crm->item_type, 'edit'];
                } else {
                    $aboutRight = true;
                }
            }
            $this->assertRights($identity, $aboutRight, $icp !== [], $sellNeed, $newOffer ? 'add' : 'edit', $renameNeed);
            if (! $aboutRight && $icp === [] && $sellNeed === null && $renameNeed === null) {
                // Nothing to write. The answer to an update is the record, and these routes no longer ask for the Products row's view right, so a person who may write NONE of
                // the stores this record lives in is refused all the same: an empty update is not a way to read a record.
                $mayEditAbout = $hasOfferPointer && ProductAccess::canWriteOffers($identity, 'edit');
                $mayEditSell = $crm !== null && ProductAccess::canWriteCatalogue($identity, (string) $crm->item_type, 'edit');
                if (! $mayEditAbout && ! $mayEditSell && ($hasOfferPointer || $crm !== null)) {
                    throw new ProductMasterInvalid($hasOfferPointer ? ['about' => ['You do not have the right to edit the Product Portfolio (the About section).']] : ['sell' => ['You do not have the right to edit the CRM catalogue (the Sell section).']], 403);
                }
            }

            // 2b. A problem the Sell section has with this row (its type is not the one the row has, it is in the Recycle Bin and was not switched on) is said only now.
            if ($plan !== null && $plan['problem'] !== null) {
                throw $plan['problem'];
            }

            // 3. The offer must be this organisation's own to be written (a shared offer is read-only here).
            if ($hasOfferPointer && $offer === null && ($aboutAsked || $identityChanged || $icp !== [])) {
                throw ProductMasterInvalid::field('offer', 'This sub-product has no offer of your own to edit. A shared offer is read-only here.', 409);
            }

            // 4. The shape of the request.
            $rules = ['about' => 'nullable|array', 'icp' => 'nullable|array', 'name' => 'sometimes|required|string|min:2|max:191', 'productId' => 'sometimes|nullable|integer'];
            if ($aboutAsked) {
                $rules += [
                    'offerId' => $newOffer ? 'required|string|max:50' : 'sometimes|nullable|string|max:50',
                    'readiness' => $newOffer ? 'required|string|max:100' : 'sometimes|required|string|max:100',
                ] + $this->aboutRules();
            }
            if ($plan !== null && $plan['action'] !== 'ignore') {
                $rules += $this->sellRules();
            }
            $this->validate($input, $rules);

            // 5. What the data says.
            $fields = $plan !== null && $plan['action'] !== 'ignore' ? CatalogueFields::fromSell($sell) : [];
            $this->checkTaxRate($tenant, $fields['taxRateId'] ?? null);
            $finalParent = $parentAsked ? $parentId : ($row->parent_id !== null ? (int) $row->parent_id : null);
            $parent = null;
            if ($offer !== null || $newOffer) {
                if ($aboutAsked && ! $newOffer) {
                    $sent = array_key_exists('offerId', $input) && $input['offerId'] !== null ? trim((string) $input['offerId']) : null;
                    if ($sent !== null && $sent !== '' && $sent !== (string) $offer->offer_id) {
                        throw ProductMasterInvalid::field('offerId', 'The ID of a sub-product never changes.');
                    }
                    if (array_key_exists('readiness', $input) && (string) $input['readiness'] !== (string) $offer->readiness_status) {
                        $this->checkReadiness((string) $input['readiness']);
                    }
                }
                if ($newOffer) {
                    $this->checkReadiness((string) $input['readiness']);
                    $this->assertOfferIdFree($tenant, trim((string) $input['offerId']), null);
                }
                if ($newOffer || $parentChanged) {
                    $parent = $this->productForOffer($tenant, $finalParent, $parentKey);
                } elseif ($finalParent === null && $offer !== null) {
                    throw ProductMasterInvalid::field($parentKey, 'Choose one of your products.');
                }
            } elseif ($parentChanged && $parentId !== null && ! ProductLines::isVisibleLine($tenant, $parentId)) {
                throw ProductMasterInvalid::field($parentKey, 'Choose one of your products.');
            }
            if ($aboutAsked) {
                $input = $this->oftenSoldTogetherChecked($tenant, $input, $newOffer ? trim((string) $input['offerId']) : ($offer !== null ? (string) $offer->offer_id : null));
            }

            // 6. The writes. Every row the write may touch is read first, so the audit lines say what really changed.
            $audit->watch('g2g_product_master', (int) $row->id);
            $audit->watch('g2g_product_offers', $hasOfferPointer && $offer !== null ? (int) $offer->id : null);
            $audit->watch('crm_products', $crm !== null ? (int) $crm->id : null);
            $sync = new ProductMasterSync(null, true, $userId);

            if ($newOffer) {
                // An About added to an item: the offer is born with the final name and Product, and the master row becomes a sub_product with the SAME key.
                $offer = ProductOffer::create($this->offerAttributes($identity, $input, $parent, trim((string) $input['offerId']), $name));
                $audit->created('g2g_product_offers', (int) $offer->id);
                DB::table('g2g_product_master')->where('id', $row->id)->update(['kind' => ProductMaster::KIND_SUB_PRODUCT, 'offer_row_id' => $offer->id]);
            }

            if ($identityChanged) {
                // One name and one Product in every copy that exists, in this transaction (the master row, the offer, the catalogue row, a binned one too).
                $change = [];
                if ($nameChanged) {
                    $change['name'] = $name;
                }
                if ($parentChanged) {
                    $change['parent_id'] = $finalParent;
                }
                $sync->mirror($tenant, (int) $row->id, $change);
            }

            if ($offer !== null && ! $newOffer && ($aboutAsked || $identityChanged)) {
                $offer->refresh();
                $changes = ['is_user_edited' => true, 'updated_by' => $userId];
                if (array_key_exists('readiness', $input)) {
                    $changes['readiness_status'] = (string) $input['readiness'];
                }
                $offer->forceFill($changes + $this->aboutFields($input, partial: true))->save();
            }

            if ($icp !== [] && $offer !== null) {
                app(OfferIcpSync::class)->sync($tenant, $userId, $offer->refresh(), $icp);
            }

            if ($plan !== null) {
                $this->applySell($identity, $row, $crm, $plan, $fields, $name, $parentChanged ? $finalParent : ($row->parent_id !== null ? (int) $row->parent_id : null), $audit);
            }

            DB::table('g2g_product_master')->where('id', $row->id)->update(['updated_by' => $userId, 'updated_at' => now()]);
            $audit->settle();
            DB::afterCommit(fn () => $audit->flush());

            return ProductMaster::query()->findOrFail($row->id);
        });
    }

    /**
     * What a Sell section does to the catalogue row this record holds, for the four states (none, sold, paused, binned) and the switch (on, off, not there).
     *
     * A problem the request has with the row it names (a type that is not the row's, a row in the Bin with no switch) is returned, not thrown: the caller says it only
     * AFTER the rights are checked, so a person with no right on the section is told 403 and learns nothing about the row. (An unknown type on a row that does not exist
     * yet cannot be judged by a right at all, so that one is a 422 at once.)
     *
     * @param  array<string, mixed>  $sell
     * @return array{action: string, right: ?string, type: string, problem: ?ProductMasterInvalid}
     *         action: create | restore | resume | pause | edit | none | ignore | blocked; right: the action the caller needs on the type's row (add or edit) or null
     */
    private function sellPlan(array $sell, ?bool $enabled, ?object $crm): array
    {
        $current = $crm !== null ? (string) $crm->item_type : null;
        $problem = null;
        $type = $current;
        if ($current === null) {
            $type = $this->typeOf($sell, null);
        } else {
            try {
                $this->typeOf($sell, $current);
            } catch (ProductMasterInvalid $e) {
                $problem = $e;
            }
        }
        $state = $crm === null ? 'none' : CatalogueFields::state($crm);
        $problem ??= $this->contradiction($sell, $enabled, $state);
        $hasFields = CatalogueFields::fromSell($sell) !== [];
        $plan = fn (string $action, ?string $right) => ['action' => $action, 'right' => $right, 'type' => (string) $type, 'problem' => $problem];
        $edit = fn () => $hasFields ? $plan('edit', 'edit') : $plan('none', null);

        if ($enabled === true) {
            return match ($state) {
                'none' => $plan('create', 'add'),
                'binned' => $plan('restore', 'add'),
                'paused' => $plan('resume', 'edit'),
                default => $edit(),
            };
        }
        if ($enabled === false) {
            return match ($state) {
                'sold' => $plan('pause', 'edit'),
                'paused' => $edit(),
                default => $plan('none', null),
            };
        }

        // No switch in the request: edit what is there. A row in the Recycle Bin asks to be switched on first; nothing to edit means nothing to do.
        if ($state === 'binned') {
            $problem ??= ProductMasterInvalid::field('sell.enabled', 'This item is in the Recycle Bin. Switch Sell in CRM on to bring it back.', 409);

            return ['action' => 'blocked', 'right' => 'edit', 'type' => (string) $type, 'problem' => $problem];
        }

        return match ($state) {
            'sold', 'paused' => $edit(),
            default => $plan('ignore', null),
        };
    }

    /**
     * D-1, ONE switch (P2-F). "Sell in CRM" (`enabled`) and "Offered on new documents" (`isActive`) are two names for one stored state once the row exists: off is
     * paused, and a paused row is `is_active` 0. A request that says both, and says opposite things, is refused (a 422 on `sell.isActive`, nothing written) instead of
     * letting `enabled` silently win. The two contradictions:
     *   - `enabled` false with `isActive` true (any state of the row: off and offered cannot both be true);
     *   - `enabled` true with `isActive` false on a row that is SOLD (on, and already offered, and asked to be not offered).
     * Not contradictions: `isActive` alone sets the state; `enabled` alone pauses or resumes; `enabled` true with `isActive` false on a row that is being made, brought back
     * from the Bin or resumed is a row that starts (or stays) paused, which the one form can ask for. A flag that is no flag is the validation's 422 on the same key.
     *
     * @param  array<string, mixed>  $sell
     */
    private function contradiction(array $sell, ?bool $enabled, string $state): ?ProductMasterInvalid
    {
        $offered = array_key_exists('isActive', $sell) && $sell['isActive'] !== null ? filter_var($sell['isActive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($enabled === null || $offered === null) {
            return null;
        }
        if (($enabled === false && $offered === true) || ($enabled === true && $offered === false && $state === 'sold')) {
            return ProductMasterInvalid::field('sell.isActive', 'Sell in CRM and Offered on new documents are one switch: this request turns it ' . ($offered ? 'off and on' : 'on and off') . '. Send one of them, or send both saying the same.');
        }

        return null;
    }

    /** @param array<string, mixed> $fields @param array{action: string, right: ?string, type: string} $plan */
    private function applySell(array $identity, ProductMaster $row, ?object $crm, array $plan, array $fields, string $name, ?int $parentId, ProductWriteAudit $audit): void
    {
        $userId = (int) $identity['user_id'];
        switch ($plan['action']) {
            case 'create':
                $crmId = $this->insertCatalogue($identity, $plan['type'], $fields, $name, $parentId);
                $audit->created('crm_products', $crmId);
                DB::table('g2g_product_master')->where('id', $row->id)->update(['crm_product_id' => $crmId]);

                return;
            case 'restore':
            case 'resume':
            case 'pause':
            case 'edit':
                $data = CatalogueFields::payload($fields, true);
                unset($data['name'], $data['product_master_id']);
                if ($plan['action'] === 'pause') {
                    $data['is_active'] = false;
                } elseif (in_array($plan['action'], ['restore', 'resume'], true) && ! array_key_exists('is_active', $data)) {
                    $data['is_active'] = true;
                }
                if ($plan['action'] === 'restore') {
                    // The SAME row comes back from the Recycle Bin: no second row is ever inserted (11 tables point at this one).
                    $data['deleted_at'] = null;
                    if (ColumnExists::has('crm_products', 'deleted_by')) {
                        $data['deleted_by'] = null;
                    }
                    if ($row->kind === ProductMaster::KIND_ITEM && $row->status === ProductMaster::STATUS_ARCHIVED) {
                        // The sync archived the master row of a binned catalogue-only item; it comes back with its item (what ProductMasterSync does on a restore).
                        DB::table('g2g_product_master')->where('id', $row->id)->update(['status' => ProductMaster::STATUS_ACTIVE]);
                    }
                }
                if ($plan['type'] === 'service') {
                    $data = CatalogueFields::applyServiceRules($data, onlyWhatIsThere: true);
                }
                if ($data !== []) {
                    DB::table('crm_products')->where('id', $crm->id)->where('sub_institute_id', $identity['sub_institute_id'])->update($data + ['updated_by' => $userId, 'updated_at' => now()]);
                }

                return;
            default:
                return;
        }
    }

    // ---- sections: what a request carries ----

    /**
     * Whether the request carries an About. On create an empty offer id or readiness means "no About" (a form that leaves them blank); on update a key that is sent
     * is a change and must be valid.
     */
    private function aboutRequested(array $input, bool $create): bool
    {
        if (is_array($input['about'] ?? null) && $input['about'] !== []) {
            return true;
        }
        foreach (['offerId', 'readiness'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            if (! $create) {
                // An update: a key that is sent is a change and must be valid, a null readiness included (it was a 422 before, and the framework turns "" into null).
                return true;
            }
            if (is_scalar($input[$key]) && trim((string) $input[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> the ICP payload, or [] when the request has none */
    private function icpOf(array $input): array
    {
        return is_array($input['icp'] ?? null) ? $input['icp'] : [];
    }

    /** @return ?array<string, mixed> */
    private function sellOf(array $input): ?array
    {
        return is_array($input['sell'] ?? null) ? $input['sell'] : null;
    }

    /** The switch of the Sell section: true, false, or null when it is not there. Something that is no switch is a 422 on `sell.enabled`. */
    private function enabledOf(array $sell): ?bool
    {
        if (! array_key_exists('enabled', $sell) || $sell['enabled'] === null) {
            return null;
        }
        $flag = filter_var($sell['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            throw ProductMasterInvalid::field('sell.enabled', 'Sell in CRM must be on or off.');
        }

        return $flag;
    }

    /**
     * The type of the Sell section: the one a catalogue row already has (it never changes), else the one sent, else a product. A type that is neither is a 422 here
     * and not later, because the right that applies depends on it.
     */
    private function typeOf(array $sell, ?string $current): string
    {
        // A type that is no text (a list, say) is "not a type" and gets the same 422 as an unknown word, not a PHP warning.
        $sent = array_key_exists('type', $sell) && $sell['type'] !== null && $sell['type'] !== '' ? (is_scalar($sell['type']) ? (string) $sell['type'] : '(not text)') : null;
        if ($sent !== null && ! in_array($sent, CatalogueFields::ITEM_TYPES, true)) {
            throw ProductMasterInvalid::field('sell.type', 'Choose product or service.');
        }
        if ($current !== null) {
            if ($sent !== null && $sent !== $current) {
                throw ProductMasterInvalid::field('sell.type', 'A product never becomes a service, or the other way round: make a new one instead.');
            }

            return $current;
        }

        return $sent ?? 'product';
    }

    /**
     * The Product a request names: productId, or parentId / parent_id as the old drawer sent it for an item. The error key is the name the client used (the aliases
     * are all reported as parentId).
     *
     * @return array{0: ?int, 1: string, 2: bool} [the id or null, the key errors go under, whether the request names a Product at all]
     */
    private function parentOf(array $input): array
    {
        foreach (['productId' => 'productId', 'parentId' => 'parentId', 'parent_id' => 'parentId'] as $key => $errorKey) {
            if (array_key_exists($key, $input)) {
                $v = $input[$key];

                return [is_scalar($v) && $v !== '' && (int) $v > 0 ? (int) $v : null, $errorKey, true];
            }
        }

        return [null, 'productId', false];
    }

    /** The Product an offer sits under: one of the organisation's own or a shared one, active, and with the Portfolio name its offers are filed under. */
    private function productForOffer(int $tenant, ?int $id, string $errorKey): ProductMaster
    {
        $product = $id === null ? null : ProductMaster::visibleTo($tenant)->active()->where('kind', ProductMaster::KIND_PRODUCT)->find($id);
        if (! $product || trim((string) $product->portfolio_name) === '') {
            throw ProductMasterInvalid::field($errorKey, 'Choose one of your products.');
        }

        return $product;
    }

    // ---- rights ----

    /**
     * 403 for every section the caller may not write, naming it, and nothing is written. About and ICP need the Portfolio right; Sell the right of its type.
     *
     * @param  ?array{0: string, 1: string}  $sell  [the item type, the action (add or edit)] or null when there is no Sell write
     * @param  ?array{0: string, 1: string}  $rename  the same, for a new name or Product of a record that has no offer: it is an EDIT of its catalogue row, and is asked in
     *                                                addition to whatever the Sell section needs (an add right may bring a row back from the Bin, it may not rename it)
     */
    private function assertRights(array $identity, bool $about, bool $icp, ?array $sell, string $offersAction, ?array $rename = null): void
    {
        $refusal = fn (string $action, string $where, string $section): array => ['You do not have the right to ' . ($action === 'add' ? 'add to ' : 'edit ') . $where . ' (the ' . $section . ' section).'];
        $errors = [];
        if ($about && ! ProductAccess::canWriteOffers($identity, $offersAction)) {
            $errors['about'] = $refusal($offersAction, 'the Product Portfolio', 'About');
        }
        if ($icp && ! ProductAccess::canWriteOffers($identity, $offersAction)) {
            $errors['icp'] = $refusal($offersAction, 'the Product Portfolio', 'ICP');
        }
        if ($sell !== null && ! ProductAccess::canWriteCatalogue($identity, $sell[0], $sell[1])) {
            $errors['sell'] = $refusal($sell[1], 'the CRM catalogue', 'Sell');
        }
        if ($rename !== null && ! isset($errors['sell']) && ! ProductAccess::canWriteCatalogue($identity, $rename[0], $rename[1])) {
            $errors['sell'] = $refusal($rename[1], 'the CRM catalogue', 'Sell');
        }
        if ($errors !== []) {
            throw new ProductMasterInvalid($errors, 403);
        }
    }

    // ---- rules and checks ----

    /** @return array<string, string> the rules of the About section, the lengths the Portfolio itself enforces */
    private function aboutRules(): array
    {
        return [
            'about' => 'nullable|array',
            'about.whatItDoes' => 'nullable|string', 'about.modulesComponents' => 'nullable|string', 'about.deploymentModel' => 'nullable|string|max:100',
            'about.proofPoints' => 'nullable|string', 'about.notes' => 'nullable|string',
            'about.needsSolved' => 'nullable|array|max:60', 'about.needsSolved.*' => 'string|max:400',
            'about.primarySegments' => 'nullable|array|max:60', 'about.primarySegments.*' => 'string|max:160',
            'about.triggerSignals' => 'nullable|array|max:60', 'about.triggerSignals.*' => 'string|max:160',
            'about.pricingModel' => 'nullable|string|max:191', 'about.typicalDealBand' => 'nullable|string|max:50',
            'about.implementationEffort' => 'nullable|string|max:100', 'about.deliveryOwner' => 'nullable|string|max:100',
            'about.oftenSoldTogether' => 'nullable|array|max:60', 'about.oftenSoldTogether.*' => 'string|max:50',
        ];
    }

    /**
     * The rules of the Sell section: the catalogue controller's own six (CatalogueFields::rules) for the fields it checks, plus the column sizes and number shapes the
     * database holds (CatalogueFields::limits). The alias basePrice is checked under its own name, so the old drawer finds its error next to its field.
     *
     * @return array<string, string>
     */
    private function sellRules(): array
    {
        $rules = ['sell' => 'nullable|array'];
        foreach (CatalogueFields::sellRules() as $key => $rule) {
            $rules['sell.' . $key] = $rule;
        }

        return $rules;
    }

    /** The readiness states a sub-product may be given: the same list the Portfolio offers, and the list the screen's form shows. @return list<string> */
    public static function readinessStatuses(): array
    {
        return array_map('strval', array_keys((array) config('g2g_taxonomy.readiness_statuses', [])));
    }

    /**
     * D-3 (P2-F): "often sold together" is a list of OFFER IDS, the ones the Portfolio's own show route reads back (`bundles_with`, matched on `offer_id` among the
     * organisation's own offers and the shared ones). Each id, once trimmed, must be the id of an offer the organisation can see and must not be the offer itself, else a
     * 422 on `about.oftenSoldTogether.N` (N is the position in the request) and nothing is written. An id that is not there and an id of another organisation's offer give
     * the same answer, so the message teaches nothing about the other organisation. The match is exact (the database's own would ignore case, and sqlite would not), the
     * list is stored as the offers' own ids in the order sent, none twice, and an empty list clears it. Only a list that is SENT is judged: one that is left alone may hold an
     * id whose offer has since gone, which is the Portfolio's business and never a reason to refuse an unrelated edit.
     *
     * @param  array<string, mixed>  $input
     * @param  ?string  $ownOfferId  the offer's own id (the one being made, or the one stored), which it may not list
     * @return array<string, mixed> the input, with the list cleaned
     */
    private function oftenSoldTogetherChecked(int $tenant, array $input, ?string $ownOfferId): array
    {
        $about = is_array($input['about'] ?? null) ? $input['about'] : [];
        if (! array_key_exists('oftenSoldTogether', $about)) {
            return $input;
        }
        $list = is_array($about['oftenSoldTogether']) ? $about['oftenSoldTogether'] : [];

        $errors = [];
        $wanted = [];
        foreach ($list as $position => $raw) {
            $id = is_scalar($raw) ? trim((string) $raw) : '';
            if ($id !== '' && $ownOfferId !== null && $id === $ownOfferId) {
                $errors['about.oftenSoldTogether.' . $position] = ['An offer cannot be often sold together with itself.'];
            } else {
                $wanted[$position] = $id;
            }
        }
        $visible = $wanted === [] ? [] : array_flip(ProductOffer::query()->forTenant($tenant)->whereIn('offer_id', array_values(array_unique(array_filter($wanted, fn (string $id): bool => $id !== ''))))->pluck('offer_id')->map(fn ($id): string => (string) $id)->all());
        $clean = [];
        foreach ($wanted as $position => $id) {
            if ($id === '' || ! isset($visible[$id])) {
                $errors['about.oftenSoldTogether.' . $position] = ['Choose offers of your own or shared ones: no offer with this ID is available to you.'];
            } elseif (! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        if ($errors !== []) {
            throw new ProductMasterInvalid($errors, 422);
        }
        $input['about']['oftenSoldTogether'] = $clean;

        return $input;
    }

    private function checkReadiness(string $value): void
    {
        $allowed = self::readinessStatuses();
        if ($allowed !== [] && ! in_array($value, $allowed, true)) {
            throw ProductMasterInvalid::field('readiness', 'Choose one of the listed readiness states.');
        }
    }

    /** The tax rate must be one of this organisation's own: another organisation's, and an id that is not there, are a 422 (never a raw foreign-key error). */
    private function checkTaxRate(int $tenant, mixed $taxRateId): void
    {
        if ($taxRateId === null || $taxRateId === '') {
            return;
        }
        if (! Schema::hasTable('crm_tax_rates') || ! DB::table('crm_tax_rates')->where('id', (int) $taxRateId)->where('sub_institute_id', $tenant)->exists()) {
            throw ProductMasterInvalid::field('sell.taxRateId', 'That tax rate was not found.');
        }
    }

    /** An offer id is unique across the organisation's offers and the shared ones, and its master key must be free too (a clash is a 409). */
    private function assertOfferIdFree(int $tenant, string $offerId, ?string $key): void
    {
        $clash = ProductOffer::query()->where('offer_id', $offerId)->where(fn ($q) => $q->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id'))->exists();
        if ($clash || ($key !== null && ProductMaster::visibleTo($tenant)->where('product_key', $key)->exists())) {
            throw ProductMasterInvalid::field('offerId', 'An offer with this ID already exists.', 409);
        }
    }

    // ---- the rows ----

    /**
     * The attributes of a new offer: its name, group and readiness, then the About fields. The three lists and bundles_with are always written (an empty list when
     * not sent, as the Portfolio does); a text field only when it was sent, so a host whose offers table lacks a newer column is never asked to store NULL in it.
     *
     * @return array<string, mixed>
     */
    private function offerAttributes(array $identity, array $input, ProductMaster $parent, string $offerId, string $name): array
    {
        return [
            'sub_institute_id' => $identity['sub_institute_id'], 'offer_id' => $offerId, 'name' => $name, 'parent_product' => (string) $parent->portfolio_name,
            'readiness_status' => (string) $input['readiness'],
            // Never set here: readiness is confirmed only by a person, through the Portfolio's own audited action.
            'readiness_confirmed' => false, 'is_user_edited' => true, 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
        ] + $this->aboutFields($input, partial: false);
    }

    /**
     * The About columns from the request. `$partial` (an update): only the keys that were sent, a list sent as null becoming an empty list. Otherwise (a create): every list,
     * empty when not sent, and the text fields that were sent.
     *
     * @return array<string, mixed>
     */
    private function aboutFields(array $input, bool $partial): array
    {
        $about = is_array($input['about'] ?? null) ? $input['about'] : [];
        $out = [];
        foreach (self::ABOUT_MAP as $key => $column) {
            $isList = in_array($column, self::ABOUT_LISTS, true);
            if (array_key_exists($key, $about)) {
                $out[$column] = $isList ? array_values((array) $about[$key]) : $about[$key];
            } elseif ($isList && ! $partial) {
                $out[$column] = [];
            }
        }

        return $out;
    }

    /**
     * Inserts the catalogue row of a record: only the fields that were sent (a field that was not stays NULL in the database), the item is active unless told
     * otherwise, assigned to the caller unless told otherwise, and numbered PRD- or SVC-. A service never carries stock or a vendor.
     *
     * @param  array<string, mixed>  $fields  CatalogueFields::fromSell
     */
    private function insertCatalogue(array $identity, string $type, array $fields, string $name, ?int $parentId): int
    {
        $data = CatalogueFields::payload($fields, true);
        unset($data['name'], $data['product_master_id']);
        $data['name'] = $name;
        $data['item_type'] = $type;
        $data['product_no'] = CatalogueFields::newProductNo($type);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['assigned_to'] = $data['assigned_to'] ?? (int) $identity['user_id'];
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();
        if ($type === 'service') {
            $data = CatalogueFields::applyServiceRules($data, onlyWhatIsThere: true);
        }
        if ($parentId !== null && ColumnExists::has('crm_products', 'product_master_id')) {
            $data['product_master_id'] = $parentId;
        }

        return (int) DB::table('crm_products')->insertGetId($data);
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
}
