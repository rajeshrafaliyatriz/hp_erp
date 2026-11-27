<?php

namespace App\Domain\Signals\Outreach;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four filters of the Outreach tab (Product, Sub-product, ICP, Region) as ONE definition, used by Companies, All people and Messages, so a count, a chip and a row can never disagree.
 *
 * A row (a company, a person or a message) has a SET of values per facet. A filter passes a row when the set contains the chosen value; the value `none` passes a row whose set is EMPTY
 * ("Not recorded"); the four filters are ANDed; a value nobody has matches nothing (no error). Most signals lack a product, a sub-product, an ICP or a region, so "Not recorded" is a real
 * choice with a real count.
 *
 *   company   the union over its non-deleted signals, plus the company's own state and the parts of its location
 *   person    its company's sets, plus its own signal's (opportunity_id) and its own location
 *   message   its own product_master_id is authoritative for Product (and Sub-product when that id is a sub-product); everything else comes from the person whose gtm_contact_id is the
 *             message's contact (a contact that is not a signal contact has no sets)
 *
 * Reading is set-based: a handful of queries per list (the product master, the offers, the signals of the listed companies, the people of the listed messages), never one per row. Always
 * tenant-scoped; a deleted signal, person or company is never read. A host that lacks a table or a column answers with empty sets instead of failing: columns are read from
 * ColumnExists::all() (the portable listing; never the bound `SHOW COLUMNS ... LIKE ?` form, it fails on the live MariaDB 10.1). Nothing is written.
 *
 * Facet options (`answer()`): each facet's counts are taken with the OTHER THREE facets applied and not itself, so picking an option never promises rows it does not have. The cascade
 * (only the chosen product's sub-products) is the front end's job: the backend accepts any combination.
 */
final class OutreachFacets
{
    public const NONE = 'none';

    public const PRODUCT = 'product';

    public const SUB_PRODUCT = 'subProduct';

    public const ICP = 'icp';

    public const REGION = 'region';

    /** @var list<string> */
    public const FACETS = [self::PRODUCT, self::SUB_PRODUCT, self::ICP, self::REGION];

    /** The three customer models and what the screen calls them, in the order the ICP list shows them. @var array<string, string> */
    public const ICP_LABELS = ['b2b' => 'B2B: Private organisations', 'b2c' => 'B2C: Consumers', 'b2g' => 'B2G: Government and tenders'];

    /** The Region list shows the most common places only (and the chosen one). */
    public const REGION_LIMIT = 50;

    private const MAX_TOKEN = 60;

    /** Text a research tool writes in a location when it found no place: not a region, so it is not offered as one (seen on db3: "Identified from Source"). */
    private const PLACEHOLDERS = ['null', 'n/a', 'na', 'unknown', 'not available', 'not specified', 'not recorded', 'tbd', 'identified from source'];

    private const CHUNK = 800;

    /** @var array<string, string> facet => the query-string name */
    private const PARAMS = [self::PRODUCT => 'product', self::SUB_PRODUCT => 'sub_product', self::ICP => 'icp', self::REGION => 'region'];

    private bool $catalogLoaded = false;

    /** @var array<int, array{id: int, kind: string, parent: ?int, name: string, offer_row: ?int}> */
    private array $masters = [];

    /** @var array<string, int> lower-cased product_key / signal_key => master id (product and sub-product kinds) */
    private array $masterByKey = [];

    /** @var array<int, int> offer row id => sub-product master id */
    private array $subByOfferRow = [];

    /** @var array<string, int> lower-cased portfolio name => product master id */
    private array $productByPortfolio = [];

    /** @var array<string, list<array{id: int, code: string, name: string, parent: string}>> lower-cased offer code => offer rows, the organisation's own first */
    private array $offersByCode = [];

    /** @var array<int, array<string, mixed>> signal id => its sets */
    private array $signalSets = [];

    /** @var array<int, list<int>> company id => the ids of its signals */
    private array $signalIdsByCompany = [];

    /** @var array<int, true> */
    private array $companyLoaded = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly int $tenant)
    {
    }

    // ── what the request asked for ───────────────────────────────────────────────

    /**
     * The four chosen values from a request (`product`, `sub_product`, `icp`, `region`), cleaned: null = not chosen, `none` = Not recorded, anything else is the value itself
     * (an ICP lower-cased, a region as a key). Anything that is not text (an array in the query string) counts as not chosen.
     *
     * @param  array<string, mixed>  $input
     * @return array{product: ?string, subProduct: ?string, icp: ?string, region: ?string}
     */
    public static function selection(array $input): array
    {
        $out = [];
        foreach (self::PARAMS as $facet => $param) {
            $raw = $input[$param] ?? null;
            $value = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
            if ($value === '') {
                $out[$facet] = null;

                continue;
            }
            if (mb_strtolower($value) === self::NONE) {
                $out[$facet] = self::NONE;

                continue;
            }
            $out[$facet] = match ($facet) {
                self::ICP => mb_strtolower($value),
                self::REGION => self::regionKey($value),
                default => ctype_digit($value) ? (string) (int) $value : $value,   // a master id: "007" is 7
            };
        }

        return $out;
    }

    /** @param array<string, ?string> $selection */
    public static function isActive(array $selection): bool
    {
        foreach (self::FACETS as $facet) {
            if (($selection[$facet] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a row with these sets pass the chosen filters? `$skip` leaves one facet out (its own options are counted without it).
     *
     * @param  array<string, array<int|string, mixed>>  $sets
     * @param  array<string, ?string>  $selection
     */
    public static function passes(array $sets, array $selection, ?string $skip = null): bool
    {
        foreach (self::FACETS as $facet) {
            $chosen = $selection[$facet] ?? null;
            if ($chosen === null || $facet === $skip) {
                continue;
            }
            $set = $sets[$facet] ?? [];
            if ($chosen === self::NONE) {
                if ($set !== []) {
                    return false;
                }

                continue;
            }
            if (! isset($set[$chosen])) {
                return false;
            }
        }

        return true;
    }

    /** @return array{product: array<int|string, array<string, mixed>>, subProduct: array<int|string, array<string, mixed>>, icp: array<int|string, array<string, mixed>>, region: array<int|string, array<string, mixed>>} */
    public static function emptySets(): array
    {
        return [self::PRODUCT => [], self::SUB_PRODUCT => [], self::ICP => [], self::REGION => []];
    }

    // ── the sets of a row ────────────────────────────────────────────────────────

    /**
     * @param  iterable<array<string, mixed>|object>  $companies  each with id, state, location
     * @return array<int, array<string, mixed>> company id => its sets
     */
    public function companySets(iterable $companies): array
    {
        $rows = [];
        foreach ($companies as $c) {
            $rows[(int) self::get($c, 'id')] = $c;
        }
        $this->loadSignals(array_keys($rows), []);

        $out = [];
        foreach ($rows as $id => $c) {
            $out[$id] = $this->companyTotals($id, self::get($c, 'state'), self::get($c, 'location'));
        }

        return $out;
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $people  each with id, company_id, opportunity_id, location
     * @return array<int, array<string, mixed>> person id => the sets of the company, of the person's own signal and of the person's own location
     */
    public function personSets(iterable $people): array
    {
        $rows = [];
        $companyIds = [];
        $signalIds = [];
        foreach ($people as $p) {
            $id = (int) self::get($p, 'id');
            $rows[$id] = $p;
            if ((int) self::get($p, 'company_id') > 0) {
                $companyIds[(int) self::get($p, 'company_id')] = true;
            }
            if ((int) self::get($p, 'opportunity_id') > 0) {
                $signalIds[(int) self::get($p, 'opportunity_id')] = true;
            }
        }
        $places = $this->companyPlaces(array_keys($companyIds));
        $this->loadSignals(array_keys($companyIds), array_keys($signalIds));

        $out = [];
        foreach ($rows as $id => $p) {
            $companyId = (int) self::get($p, 'company_id');
            $sets = $companyId > 0 ? $this->companyTotals($companyId, $places[$companyId]['state'] ?? null, $places[$companyId]['location'] ?? null) : self::emptySets();
            $own = (int) self::get($p, 'opportunity_id');
            if ($own > 0 && isset($this->signalSets[$own])) {
                self::merge($sets, $this->signalSets[$own]);
            }
            self::merge($sets, [self::REGION => self::regionSet(self::textTokens(self::get($p, 'location')))]);
            $out[$id] = $sets;
        }

        return $out;
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $messages  each with id, contact_id, product_master_id
     * @return array<int, array<string, mixed>> message id => its sets
     */
    public function messageSets(iterable $messages): array
    {
        $rows = [];
        $contactIds = [];
        foreach ($messages as $m) {
            $rows[(int) self::get($m, 'id')] = $m;
            if ((int) self::get($m, 'contact_id') > 0) {
                $contactIds[(int) self::get($m, 'contact_id')] = true;
            }
        }

        // The people behind those GTM contacts (a contact that is not a signal contact has no row here and so no sets).
        $byContact = [];
        foreach ($this->peopleOfContacts(array_keys($contactIds)) as $contactId => $people) {
            foreach ($this->personSets($people) as $sets) {
                $byContact[$contactId] ??= self::emptySets();
                self::merge($byContact[$contactId], $sets);
            }
        }

        $this->loadCatalog();
        $out = [];
        foreach ($rows as $id => $m) {
            $sets = $byContact[(int) self::get($m, 'contact_id')] ?? self::emptySets();
            $own = $this->masters[(int) self::get($m, 'product_master_id')] ?? null;
            if ($own !== null) {
                $product = $this->productOf($own);
                if ($product !== null) {
                    $sets[self::PRODUCT] = [$product => ['label' => $this->masters[$product]['name']]];
                }
                if ($own['kind'] === 'sub_product') {
                    $sets[self::SUB_PRODUCT] = [$own['id'] => $this->subEntry($own)];
                }
            }
            $out[$id] = $sets;
        }

        return $out;
    }

    /** The sets of every message of the tenant (the Messages queue is filtered and counted over all of them). @return array<int, array<string, mixed>> */
    public function tenantMessageSets(): array
    {
        if (! $this->has('gtm_outreach_messages')) {
            return [];
        }
        $cols = $this->pick('gtm_outreach_messages', ['id', 'contact_id', 'product_master_id']);
        if (! in_array('id', $cols, true) || ! in_array('sub_institute_id', $this->cols('gtm_outreach_messages'), true)) {
            return [];
        }

        $query = DB::table('gtm_outreach_messages as m')->where('m.sub_institute_id', $this->tenant);
        // Only the messages the queue can SHOW: one whose GTM contact or account no longer exists is counted by the old status tabs but is never listed, and an option that promises
        // rows the list does not show is a broken filter (found on dev: "Not recorded (3)" led to an empty queue).
        if ($this->has('gtm_contacts') && $this->has('gtm_accounts') && in_array('contact_id', $cols, true) && in_array('account_id', $this->cols('gtm_outreach_messages'), true)) {
            $query->whereExists(fn ($c) => $c->from('gtm_contacts as c')->whereColumn('c.id', 'm.contact_id')->where('c.sub_institute_id', $this->tenant))
                ->whereExists(fn ($a) => $a->from('gtm_accounts as a')->whereColumn('a.id', 'm.account_id')->where('a.sub_institute_id', $this->tenant));
        }

        return $this->messageSets($query->orderBy('m.id')->get(array_map(fn (string $column) => 'm.' . $column, $cols)));
    }

    // ── the answer: options with counts ──────────────────────────────────────────

    /**
     * The options of the four filters with live counts: `{products, subProducts, icps, regions, notRecorded, active}`. Each facet is counted over the rows that pass the OTHER three,
     * options with no row are left out except the chosen one, and "Not recorded" is the number of those rows with nothing in that facet.
     *
     * @param  array<int, array<string, mixed>>  $setsById
     * @param  array<string, ?string>  $selection
     * @return array<string, mixed>
     */
    public function answer(array $setsById, array $selection): array
    {
        ksort($setsById);
        $lists = [];
        $none = [];
        foreach (self::FACETS as $facet) {
            $counts = [];
            $labels = [];
            $parents = [];
            $missing = 0;
            foreach ($setsById as $sets) {
                if (! self::passes($sets, $selection, $facet)) {
                    continue;
                }
                $set = $sets[$facet] ?? [];
                if ($set === []) {
                    $missing++;

                    continue;
                }
                foreach ($set as $value => $meta) {
                    $key = (string) $value;
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $labels[$key] ??= (string) $meta['label'];
                    if ($facet === self::SUB_PRODUCT && ! isset($parents[$key]) && ($meta['productId'] ?? null) !== null) {
                        $parents[$key] = (int) $meta['productId'];
                    }
                }
            }
            $chosen = $selection[$facet] ?? null;
            if ($chosen !== null && $chosen !== self::NONE && ! isset($counts[$chosen])) {
                $counts[$chosen] = 0;
                $labels[$chosen] = $this->labelOf($facet, $chosen);
                if ($facet === self::SUB_PRODUCT && ($parent = $this->parentOf($chosen)) !== null) {
                    $parents[$chosen] = $parent;
                }
            }
            $none[$facet] = $missing;
            $lists[$facet] = $this->options($facet, $counts, $labels, $parents, $chosen);
        }

        return [
            'products' => $lists[self::PRODUCT], 'subProducts' => $lists[self::SUB_PRODUCT], 'icps' => $lists[self::ICP], 'regions' => $lists[self::REGION],
            'notRecorded' => ['products' => $none[self::PRODUCT], 'subProducts' => $none[self::SUB_PRODUCT], 'icps' => $none[self::ICP], 'regions' => $none[self::REGION]],
            'active' => ['product' => $selection[self::PRODUCT] ?? null, 'subProduct' => $selection[self::SUB_PRODUCT] ?? null, 'icp' => $selection[self::ICP] ?? null, 'region' => $selection[self::REGION] ?? null],
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $labels
     * @param  array<string, int>  $parents
     * @return list<array<string, mixed>>
     */
    private function options(string $facet, array $counts, array $labels, array $parents, ?string $chosen): array
    {
        $rows = [];
        foreach ($counts as $value => $count) {
            $value = (string) $value;
            $row = ['value' => $value, 'label' => $labels[$value] ?? $value, 'count' => $count];
            if ($facet === self::SUB_PRODUCT) {
                $row['productId'] = $parents[$value] ?? null;
            }
            $rows[] = $row;
        }
        $byName = fn (array $a, array $b) => [mb_strtolower($a['label']), $a['value']] <=> [mb_strtolower($b['label']), $b['value']];
        if ($facet === self::ICP) {
            $order = array_flip(array_keys(self::ICP_LABELS));
            usort($rows, fn (array $a, array $b) => ($order[$a['value']] ?? 99) <=> ($order[$b['value']] ?? 99) ?: $byName($a, $b));
        } elseif ($facet === self::REGION) {
            usort($rows, fn (array $a, array $b) => ($b['count'] <=> $a['count']) ?: $byName($a, $b));
            if (count($rows) > self::REGION_LIMIT) {
                $top = array_slice($rows, 0, self::REGION_LIMIT);
                $keep = array_values(array_filter($rows, fn (array $r) => $r['value'] === $chosen));
                $rows = $chosen !== null && $keep !== [] && ! in_array($chosen, array_column($top, 'value'), true) ? array_merge($top, $keep) : $top;
            }
        } else {
            usort($rows, $byName);
        }

        return $rows;
    }

    // ── one signal, one company ──────────────────────────────────────────────────

    /** The union of a company's signals, plus the company's own state and the parts of its location. @return array<string, array<int|string, mixed>> */
    private function companyTotals(int $companyId, mixed $state, mixed $location): array
    {
        $sets = self::emptySets();
        foreach ($this->signalIdsByCompany[$companyId] ?? [] as $signalId) {
            self::merge($sets, $this->signalSets[$signalId]);
        }
        self::merge($sets, [self::REGION => self::regionSet(array_merge(self::textTokens($state), self::textTokens($location)))]);

        return $sets;
    }

    /**
     * What one signal says. Product: its product_master_id (a sub-product resolves to its parent product), else its product_key matched to a product master (product_key or signal_key);
     * Sub-product: the offer code (offer_id, else relevant_offer_id) resolved through the offers to a sub-product master, or `offer:<code>` when no master exists, plus its own
     * product_master_id when that is a sub-product; ICP: its customer model when it is one of the three; Region: the country, state and city of every entry of its region_scope.
     *
     * @return array<string, array<int|string, mixed>>
     */
    private function signalOf(object $s): array
    {
        $this->loadCatalog();
        $sets = self::emptySets();

        $master = $this->masters[(int) ($s->product_master_id ?? 0)] ?? null;
        $productId = $master !== null ? $this->productOf($master) : null;
        if ($productId === null) {
            $key = mb_strtolower(trim((string) ($s->product_key ?? '')));
            $byKey = $key !== '' && isset($this->masterByKey[$key]) ? $this->masters[$this->masterByKey[$key]] : null;
            $productId = $byKey !== null ? $this->productOf($byKey) : null;
        }
        if ($productId !== null) {
            $sets[self::PRODUCT][$productId] = ['label' => $this->masters[$productId]['name']];
        }

        if ($master !== null && $master['kind'] === 'sub_product') {
            $sets[self::SUB_PRODUCT][$master['id']] = $this->subEntry($master);
        }
        foreach ([$s->offer_id ?? null, $s->relevant_offer_id ?? null] as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            [$value, $entry] = $this->subOfCode($code, $productId);
            $sets[self::SUB_PRODUCT][$value] ??= $entry;

            break;   // COALESCE(offer_id, relevant_offer_id): the first filled one
        }

        $model = mb_strtolower(trim((string) ($s->customer_model ?? '')));
        if (isset(self::ICP_LABELS[$model])) {
            $sets[self::ICP][$model] = ['label' => self::ICP_LABELS[$model]];
        }

        $sets[self::REGION] = self::regionSet(self::scopeTokens($s->region_scope ?? null));

        return $sets;
    }

    /** @param array{id: int, kind: string, parent: ?int, name: string, offer_row: ?int} $master @return array{label: string, productId: ?int} */
    private function subEntry(array $master): array
    {
        return ['label' => $master['name'], 'productId' => $this->productOf($master)];
    }

    /** @return array{0: string, 1: array{label: string, productId: ?int}} the value and the entry of an offer code */
    private function subOfCode(string $code, ?int $signalProduct): array
    {
        $offers = $this->offersByCode[mb_strtolower($code)] ?? [];
        foreach ($offers as $offer) {
            $masterId = $this->subByOfferRow[$offer['id']] ?? null;
            if ($masterId !== null && isset($this->masters[$masterId])) {
                return [(string) $masterId, $this->subEntry($this->masters[$masterId])];
            }
        }
        if ($offers !== []) {
            $offer = $offers[0];
            $parent = $this->productByPortfolio[mb_strtolower(trim($offer['parent']))] ?? $signalProduct;

            return ['offer:' . $offer['code'], ['label' => $offer['name'] !== '' ? $offer['name'] : $offer['code'], 'productId' => $parent]];
        }

        return ['offer:' . $code, ['label' => $code, 'productId' => $signalProduct]];
    }

    /** The product a master stands for: itself, or the parent product of a sub-product; null for anything else. @param array{id: int, kind: string, parent: ?int, name: string, offer_row: ?int} $master */
    private function productOf(array $master): ?int
    {
        if ($master['kind'] === 'product') {
            return $master['id'];
        }
        if ($master['kind'] === 'sub_product' && $master['parent'] !== null && ($parent = $this->masters[$master['parent']] ?? null) !== null && $parent['kind'] === 'product') {
            return $parent['id'];
        }

        return null;
    }

    // ── the words of a value that no row has (a chosen option at zero) ───────────

    private function labelOf(string $facet, string $value): string
    {
        $this->loadCatalog();
        if ($facet === self::PRODUCT) {
            return $this->masters[(int) $value]['name'] ?? $value;
        }
        if ($facet === self::SUB_PRODUCT) {
            if (str_starts_with($value, 'offer:')) {
                $code = substr($value, 6);
                $offer = ($this->offersByCode[mb_strtolower($code)] ?? [])[0] ?? null;

                return $offer !== null && $offer['name'] !== '' ? $offer['name'] : $code;
            }

            return $this->masters[(int) $value]['name'] ?? $value;
        }
        if ($facet === self::ICP) {
            return self::ICP_LABELS[$value] ?? $value;
        }

        return $value;
    }

    private function parentOf(string $value): ?int
    {
        $this->loadCatalog();
        if (str_starts_with($value, 'offer:')) {
            $offer = ($this->offersByCode[mb_strtolower(substr($value, 6))] ?? [])[0] ?? null;

            return $offer !== null ? ($this->productByPortfolio[mb_strtolower(trim($offer['parent']))] ?? null) : null;
        }

        return isset($this->masters[(int) $value]) ? $this->productOf($this->masters[(int) $value]) : null;
    }

    // ── regions (pure) ───────────────────────────────────────────────────────────

    /** How a place is compared: lower-cased, trimmed, runs of spaces collapsed. */
    public static function regionKey(string $text): string
    {
        // "&" and "and" are the same word: Jammu & Kashmir and Jammu and Kashmir are one region.
        return mb_strtolower(trim(str_replace('&', 'and', preg_replace('/\s+/u', ' ', $text) ?? $text)));
    }

    /**
     * The places a region_scope JSON names: the country, state and city of every entry (a list of entries, one entry, or a plain word). Junk is skipped by regionSet().
     *
     * @return list<string>
     */
    public static function scopeTokens(mixed $scope): array
    {
        $data = $scope;
        if (is_string($scope)) {
            $text = trim($scope);
            if ($text === '') {
                return [];
            }
            $data = json_decode($text, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return str_starts_with($text, '[') || str_starts_with($text, '{') ? [] : [$text];   // not JSON: a plain place, unless it is broken JSON
            }
        }
        if (is_string($data)) {
            return [$data];
        }
        if (! is_array($data)) {
            return [];
        }
        $entries = array_is_list($data) ? $data : [$data];
        $out = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            } elseif (is_array($entry)) {
                foreach (['country', 'state', 'city'] as $part) {
                    if (isset($entry[$part]) && is_string($entry[$part])) {
                        $out[] = $entry[$part];
                    }
                }
            }
        }

        return $out;
    }

    /** The comma-separated parts of a free-text place (`Nagpur, Maharashtra` gives both). @return list<string> */
    public static function textTokens(mixed $text): array
    {
        return is_string($text) && trim($text) !== '' ? explode(',', $text) : [];
    }

    /**
     * Tokens to a region set: keyed by regionKey(), labelled with the first spelling seen. Empty, numeric-only (a postcode, a year), over-60-character tokens are skipped, and so is the
     * word "none" (it would collide with the Not recorded choice).
     *
     * @param  list<string>  $tokens
     * @return array<string, array{label: string}>
     */
    public static function regionSet(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $label = trim(preg_replace('/\s+/u', ' ', $token) ?? $token);
            // A postcode written after the place ("Gujarat - 380060") is not part of the place.
            $label = trim(preg_replace('/\s*[-\x{2013}\x{2014}]\s*\d{5,6}$/u', '', $label) ?? $label);
            if ($label === '' || mb_strlen($label) > self::MAX_TOKEN || preg_match('/^[\d\s.,\/\-]+$/u', $label) === 1) {
                continue;
            }
            $key = self::regionKey($label);
            if ($key === self::NONE || in_array($key, self::PLACEHOLDERS, true)) {
                continue;
            }
            $out[$key] ??= ['label' => $label];
        }

        return $out;
    }

    // ── reading ──────────────────────────────────────────────────────────────────

    /** @param list<int> $companyIds @param list<int> $signalIds */
    private function loadSignals(array $companyIds, array $signalIds): void
    {
        $table = 'g2g_company_opportunities';
        if (! $this->has($table)) {
            return;
        }
        $cols = $this->pick($table, ['id', 'company_id', 'product_master_id', 'product_key', 'offer_id', 'relevant_offer_id', 'customer_model', 'region_scope']);
        $all = $this->cols($table);
        if (! in_array('id', $cols, true) || ! in_array('company_id', $cols, true) || ! in_array('sub_institute_id', $all, true)) {
            return;
        }
        $deleted = in_array('deleted_at', $all, true);

        $need = array_values(array_diff($companyIds, array_keys($this->companyLoaded)));
        foreach (array_chunk($need, self::CHUNK) as $chunk) {
            $q = DB::table($table)->where('sub_institute_id', $this->tenant)->whereIn('company_id', $chunk);
            if ($deleted) {
                $q->whereNull('deleted_at');
            }
            foreach ($q->orderBy('id')->get($cols) as $row) {
                $id = (int) $row->id;
                $this->signalSets[$id] ??= $this->signalOf($row);
                $this->signalIdsByCompany[(int) $row->company_id][] = $id;
            }
            foreach ($chunk as $companyId) {
                $this->companyLoaded[$companyId] = true;
            }
        }

        $need = array_values(array_diff($signalIds, array_keys($this->signalSets)));
        foreach (array_chunk($need, self::CHUNK) as $chunk) {
            $q = DB::table($table)->where('sub_institute_id', $this->tenant)->whereIn('id', $chunk);
            if ($deleted) {
                $q->whereNull('deleted_at');
            }
            foreach ($q->get($cols) as $row) {
                $this->signalSets[(int) $row->id] ??= $this->signalOf($row);
            }
        }
    }

    /** @param list<int> $companyIds @return array<int, array{state: mixed, location: mixed}> the place of each (not deleted) company */
    private function companyPlaces(array $companyIds): array
    {
        $table = 'g2g_companies';
        if ($companyIds === [] || ! $this->has($table)) {
            return [];
        }
        $all = $this->cols($table);
        $cols = $this->pick($table, ['id', 'state', 'location']);
        if (! in_array('id', $cols, true) || ! in_array('sub_institute_id', $all, true)) {
            return [];
        }
        $out = [];
        foreach (array_chunk($companyIds, self::CHUNK) as $chunk) {
            $q = DB::table($table)->where('sub_institute_id', $this->tenant)->whereIn('id', $chunk);
            if (in_array('deleted_at', $all, true)) {
                $q->whereNull('deleted_at');
            }
            foreach ($q->get($cols) as $row) {
                $out[(int) $row->id] = ['state' => $row->state ?? null, 'location' => $row->location ?? null];
            }
        }

        return $out;
    }

    /** The signal contacts behind GTM contacts. @param list<int> $contactIds @return array<int, list<object>> gtm contact id => its people */
    private function peopleOfContacts(array $contactIds): array
    {
        $table = 'g2g_signal_contacts';
        if ($contactIds === [] || ! $this->has($table)) {
            return [];
        }
        $all = $this->cols($table);
        $cols = $this->pick($table, ['id', 'company_id', 'opportunity_id', 'location', 'gtm_contact_id']);
        if (! in_array('id', $cols, true) || ! in_array('gtm_contact_id', $cols, true) || ! in_array('sub_institute_id', $all, true)) {
            return [];
        }
        $out = [];
        foreach (array_chunk($contactIds, self::CHUNK) as $chunk) {
            $q = DB::table($table)->where('sub_institute_id', $this->tenant)->whereIn('gtm_contact_id', $chunk);
            if (in_array('deleted_at', $all, true)) {
                $q->whereNull('deleted_at');
            }
            foreach ($q->orderBy('id')->get($cols) as $row) {
                $out[(int) $row->gtm_contact_id][] = $row;
            }
        }

        return $out;
    }

    /** The product master (the organisation's own and the shared rows) and the offers, read once. */
    private function loadCatalog(): void
    {
        if ($this->catalogLoaded) {
            return;
        }
        $this->catalogLoaded = true;

        $table = 'g2g_product_master';
        $cols = $this->has($table) ? $this->pick($table, ['id', 'sub_institute_id', 'product_key', 'kind', 'parent_id', 'name', 'signal_key', 'portfolio_name', 'offer_row_id']) : [];
        if (array_diff(['id', 'sub_institute_id', 'kind', 'name'], $cols) === []) {
            // Shared rows first, so the organisation's own row wins a clash on a key.
            $rows = DB::table($table)->whereIn('sub_institute_id', [$this->tenant, 0])->orderByRaw('CASE WHEN sub_institute_id = 0 THEN 0 ELSE 1 END')->orderBy('id')->get($cols);
            foreach ($rows as $r) {
                $id = (int) $r->id;
                $this->masters[$id] = ['id' => $id, 'kind' => (string) $r->kind, 'parent' => ($r->parent_id ?? null) ? (int) $r->parent_id : null, 'name' => (string) $r->name,
                    'offer_row' => ($r->offer_row_id ?? null) ? (int) $r->offer_row_id : null];
                if ($r->kind === 'sub_product' && ($r->offer_row_id ?? null)) {
                    $this->subByOfferRow[(int) $r->offer_row_id] = $id;
                }
                if ($r->kind === 'product' && trim((string) ($r->portfolio_name ?? '')) !== '') {
                    $this->productByPortfolio[mb_strtolower(trim((string) $r->portfolio_name))] = $id;
                }
            }
            // signal_key first, product_key after it, so a product_key wins a clash between the two.
            foreach (['signal_key', 'product_key'] as $keyColumn) {
                foreach ($rows as $r) {
                    $key = mb_strtolower(trim((string) ($r->{$keyColumn} ?? '')));
                    if ($key !== '' && in_array($r->kind, ['product', 'sub_product'], true)) {
                        $this->masterByKey[$key] = (int) $r->id;
                    }
                }
            }
        }

        $table = 'g2g_product_offers';
        $cols = $this->has($table) ? $this->pick($table, ['id', 'sub_institute_id', 'offer_id', 'name', 'parent_product']) : [];
        if (array_diff(['id', 'offer_id'], $cols) === []) {
            $q = DB::table($table);
            if (in_array('sub_institute_id', $cols, true)) {
                $q->where(fn ($w) => $w->where('sub_institute_id', $this->tenant)->orWhereNull('sub_institute_id')->orWhere('sub_institute_id', 0));
                $q->orderByRaw('CASE WHEN sub_institute_id = ? THEN 0 ELSE 1 END', [$this->tenant]);
            }
            foreach ($q->orderBy('id')->get($cols) as $o) {
                $code = trim((string) $o->offer_id);
                if ($code !== '') {
                    $this->offersByCode[mb_strtolower($code)][] = ['id' => (int) $o->id, 'code' => $code, 'name' => trim((string) ($o->name ?? '')), 'parent' => (string) ($o->parent_product ?? '')];
                }
            }
        }
    }

    private function has(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @return list<string> every column of a table that exists on this host (the portable listing) */
    private function cols(string $table): array
    {
        return $this->columns[$table] ??= $this->has($table) ? ColumnExists::all($table) : [];
    }

    /** @param list<string> $wanted @return list<string> the wanted columns this host has, in the wanted order */
    private function pick(string $table, array $wanted): array
    {
        return array_values(array_intersect($wanted, $this->cols($table)));
    }

    /** @param array<string, mixed>|object $row */
    private static function get(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    /**
     * Union of sets, the first label (and the first known parent product) wins.
     *
     * @param  array<string, array<int|string, array<string, mixed>>>  $into
     * @param  array<string, array<int|string, array<string, mixed>>>  $from
     */
    private static function merge(array &$into, array $from): void
    {
        foreach ($from as $facet => $entries) {
            foreach ($entries as $value => $meta) {
                if (! isset($into[$facet][$value])) {
                    $into[$facet][$value] = $meta;
                } elseif (($into[$facet][$value]['productId'] ?? null) === null && ($meta['productId'] ?? null) !== null) {
                    $into[$facet][$value]['productId'] = $meta['productId'];
                }
            }
        }
    }
}
