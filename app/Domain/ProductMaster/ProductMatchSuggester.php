<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suggests which CRM catalogue items and Portfolio Sub-products are the SAME product (slice 15, track P2-D; Docs/agentic-crm/slices/S15-product-portfolio-merge.md
 * section 6). PURE: it only reads (SELECT), it never writes a row, and nothing it says is acted on until a person confirms the pair (ProductMerger).
 *
 * For one organisation, every LIVE catalogue item (a master row of kind `item`, active, whose crm_products row is not in the Recycle Bin) is compared with the
 * Sub-products (kind `sub_product`, active, with an offer, no catalogue pointer yet) of the SAME Product, and nothing else. Each comparison is a sum of signals, each
 * with its own weight and a short reason a person can read; the sum is 0 to 100.
 *
 *   IDENTITY signals (a curated field says it is the same thing)         NAME     45 the same name, 35 one name holds the other whole (brand words ignored)
 *                                                                        CATEGORY 40 the item's category sits inside the Sub-product's name, 25 all its words do
 *                                                                        SKU      30 the offer id (SCH-01) is a run of tokens of the SKU (SCH-01-GUJ)
 *   SUPPORT signals (words and numbers; they can never reach HIGH alone) WORDS    12 per content word the two NAMES share (max 24)
 *                                                                        TEXT      4 per content word the item's name/description shares with the Sub-product's
 *                                                                                  name, what it does and modules (max 16)
 *                                                                        ACRONYM   8 an acronym on one side is spelled out on the other (LMS / Learning Management System)
 *                                                                        PRICE     5 the item's price lies inside the offer's typical deal band (a hint)
 *
 * Why not just compare names: the Product's brand words ("scholar", "scholarclone", taken from the PARENT Product's name, key and Portfolio name, never hard-coded)
 * would give every pair of a line a shared word, so they are removed first; two items of one category (editions: "Scholar One ERP - Gujarat Edition" and "Scholar Core
 * School Management", both "School ERP") would both claim the same Sub-product, so the verdict is EDITION and a person chooses; an acronym ("LMS-PAL" against "LMS &
 * Digital Classroom", or "LMS" against "Learning Management System") is invisible to a whole-name comparison, so words are compared one by one and acronyms are
 * expanded; and a service ("... Implementation") shares words with the "implementation effort" of every product, so a service is compared with services ONLY.
 * HIGH (55) is above everything the support signals can add up to (53), so a pair with no identity signal can never be MATCH-HIGH: "nothing merges by name alone".
 *
 * USAGE (quote lines, orders, invoices, price books, ...) is counted and reported because it PROTECTS an item (it must not be deleted: a person sees why), but it is
 * NEVER an input of a score: an item used 500 times is no more likely to be the same product than one used never.
 *
 * Verdicts, in the order a person should read them:
 *   MATCH-HIGH  one best Sub-product, score >= HIGH, ahead of the runner-up Sub-product by MARGIN, and ahead of every other item that scores it by MARGIN
 *   EDITION     two or more items (each scoring >= EDITION_MIN) have the same best Sub-product: they are cuts of one thing, a person picks which one is the pair
 *   AMBIGUOUS   a best Sub-product exists (>= FLOOR) but is weak, or too close to another one, or an item competes for it: a person decides, with the reasons
 *   SERVICE     the item is a service and no Sub-product that says it is a service fits: services have no product twin
 *   NO-MATCH    nothing scores FLOOR
 *   STRAY       the item has no Product, so there is no set of Sub-products to look in: give it a Product first
 *   JUNK?       the name looks like a test row ("ZZ UI Widget"): REPORT ONLY; removing it goes through the normal Recycle Bin
 * A pair a person already marked "Different" is never offered again (the next best Sub-product is considered).
 *
 * How services appear in the data (read on dev, 13 Nov 2026): a CRM item has `item_type` product or service; a Portfolio offer has NO type field. An offer is taken for
 * a service only when it says so itself: a whole word (service, training, implementation, consulting, consultancy, onboarding) in its name, what it does or its
 * deployment model. "Decision Support" and "implementation effort" do not count. On dev no offer says it (0 of 50), so a CRM service has no candidate there.
 *
 * Built on the query builder and an explicit connection, SELECT only, MariaDB 10.1 safe (plain joins, no JSON, no window functions, no CTEs, no bound SHOW).
 */
final class ProductMatchSuggester
{
    public const MATCH_HIGH = 'MATCH-HIGH';

    public const EDITION = 'EDITION';

    public const AMBIGUOUS = 'AMBIGUOUS';

    public const SERVICE = 'SERVICE';

    public const NO_MATCH = 'NO-MATCH';

    public const STRAY = 'STRAY';

    public const JUNK = 'JUNK?';

    /** The verdicts in reading order. */
    public const VERDICTS = [self::MATCH_HIGH, self::EDITION, self::AMBIGUOUS, self::SERVICE, self::NO_MATCH, self::STRAY, self::JUNK];

    /** Below this score a Sub-product is not named at all. */
    public const FLOOR = 10.0;

    /** The score MATCH-HIGH needs. Above the sum of every support signal (24 + 16 + 8 + 5 = 53). */
    public const HIGH = 55.0;

    /** How far the best pair must lead the runner-up (on the item's side AND on the Sub-product's side). */
    public const MARGIN = 15.0;

    /** Two items that share a best Sub-product are editions only when each scores at least this much (a weak hint is not an edition). */
    public const EDITION_MIN = 35.0;

    public const W_NAME_SAME = 45.0;

    public const W_NAME_CONTAINS = 35.0;

    public const W_CATEGORY_PHRASE = 40.0;

    public const W_CATEGORY_WORDS = 25.0;

    public const W_SKU = 30.0;

    public const W_WORD = 12.0;

    public const W_WORD_MAX = 24.0;

    public const W_TEXT = 4.0;

    public const W_TEXT_MAX = 16.0;

    public const W_ACRONYM = 8.0;

    public const W_PRICE = 5.0;

    /** The words that make an offer a service (folded to one plural form). */
    public const SERVICE_WORDS = ['service', 'training', 'implementation', 'consulting', 'consultancy', 'onboarding'];

    /** A name made of one of these words (whole word) looks like a test row. */
    public const JUNK_WORDS = ['zz', 'zzz', 'test', 'testing', 'tester', 'dummy', 'sample', 'demo', 'lorem', 'ipsum', 'asdf', 'qwerty', 'xxx', 'tmp', 'temp', 'junk'];

    /** Function words that carry no meaning (already folded). */
    private const STOP = [
        'the', 'and', 'for', 'with', 'from', 'into', 'that', 'this', 'these', 'those', 'are', 'was', 'were', 'been', 'being', 'has', 'have', 'had', 'not', 'but', 'can', 'will',
        'all', 'any', 'each', 'per', 'via', 'its', 'our', 'your', 'their', 'also', 'such', 'than', 'then', 'them', 'they', 'you', 'who', 'which', 'what', 'when', 'where', 'while',
        'over', 'under', 'between', 'within', 'without', 'across', 'about', 'after', 'before', 'using', 'used', 'use', 'based', 'including', 'include', 'etc', 'new', 'more',
        'most', 'other', 'out', 'own', 'same', 'both', 'able', 'real', 'time', 'end',
    ];

    /** Words every product line uses ("system", "suite", "edition", ...): shared by chance, so they are not evidence. Already folded. */
    private const FILLER = [
        'system', 'management', 'solution', 'software', 'platform', 'suite', 'module', 'engine', 'tool', 'application', 'app', 'service', 'pack', 'package', 'edition',
        'version', 'complete', 'comprehensive', 'integrated', 'advanced', 'smart', 'online',
    ];

    /** The words between the letters of a spelled-out acronym that may be skipped ("Department of Education"). */
    private const CONNECTORS = ['of', 'and', 'the', 'for', 'to', 'in'];

    /** crm_products pointer tables, by what a person calls them. Any registry table not listed keeps its table name as its label. */
    private const USAGE_LABELS = [
        'crm_opportunity_products' => 'opportunities', 'crm_quote_line_items' => 'quotes', 'crm_sales_order_line_items' => 'orders', 'crm_purchase_order_line_items' => 'purchaseOrders',
        'crm_invoice_line_items' => 'invoices', 'crm_tickets' => 'tickets', 'crm_assets' => 'assets', 'crm_faqs' => 'faqs',
        'crm_product_bundle_items' => 'bundles', 'crm_campaigns' => 'campaigns',
    ];

    private const CHUNK = 400;

    private readonly Connection $db;

    /** @var array<string, bool> */
    private array $present = [];

    public function __construct(?string $connection = null)
    {
        $this->db = DB::connection($connection);
    }

    // ------------------------------------------------------------------ text helpers (pure, public so the tests can pin them)

    /**
     * Lower-case tokens of a text, each folded to one plural form ("fees" and "fee" are one word; "studies" and "study" too).
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $out[] = self::fold($token);
        }

        return $out;
    }

    /** One plural form: studies -> study, classes -> class, fees -> fee; business, status, analysis and 3-letter words are left alone. */
    public static function fold(string $token): string
    {
        $n = mb_strlen($token);
        if ($n >= 5 && str_ends_with($token, 'ies')) {
            return mb_substr($token, 0, -3) . 'y';
        }
        if ($n >= 5 && preg_match('/(sses|shes|ches|xes)$/u', $token) === 1) {
            return mb_substr($token, 0, -2);
        }
        if ($n >= 4 && str_ends_with($token, 's') && preg_match('/(ss|us|is)$/u', $token) !== 1) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * The words that can be evidence: at least 3 letters, not a number, not a function word, not a filler word, not one of the Product's brand words.
     *
     * @param list<string> $tokens
     * @param array<string, true> $brand
     * @return list<string> distinct, in order of first appearance
     */
    public static function content(array $tokens, array $brand = []): array
    {
        $stop = array_flip(self::STOP) + array_flip(self::FILLER);
        $out = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 3 || ctype_digit($t) || isset($stop[$t]) || isset($brand[$t])) {
                continue;
            }
            $out[$t] = true;
        }

        return array_keys($out);
    }

    /**
     * The brand words of a Product, taken from the Product row itself (its name, key, Portfolio name and signal key), plus the parts of a camel-cased word
     * (ScholarClone -> scholar, clone). Nothing is hard-coded.
     *
     * @return array<string, true>
     */
    public static function brandWords(?object $product): array
    {
        $set = [];
        if ($product === null) {
            return $set;
        }
        foreach ([$product->name ?? '', $product->product_key ?? '', $product->portfolio_name ?? '', $product->signal_key ?? ''] as $text) {
            foreach (self::tokens((string) $text) as $t) {
                $set[$t] = true;
            }
            foreach (preg_split('/[^\p{L}\p{M}\p{N}]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                foreach (preg_split('/(?<=[a-z])(?=[A-Z])/', $word) ?: [] as $part) {
                    $set[self::fold(mb_strtolower($part))] = true;
                }
            }
        }
        unset($set['']);

        return $set;
    }

    /**
     * A typical deal band written as text ("10-25 L", "Rs 50,000 - 1.5 L", "2 - 4 Cr") as numbers; null when it holds fewer than two numbers ("To fill").
     *
     * @return ?array{min: float, max: float}
     */
    public static function parseBand(?string $band): ?array
    {
        $s = mb_strtolower(trim((string) $band));
        if ($s === '' || preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*(crore|cr|lakhs?|lacs?|lac|l|million|mn|m|thousand|k)?(?![a-z])/u', $s, $m, PREG_SET_ORDER) < 2) {
            return null;
        }
        $factor = fn (string $unit): float => match ($unit) {
            'k', 'thousand' => 1e3,
            'l', 'lac', 'lacs', 'lakh', 'lakhs' => 1e5,
            'm', 'mn', 'million' => 1e6,
            'cr', 'crore' => 1e7,
            default => 1.0,
        };
        $a = (float) str_replace(',', '', $m[0][1]);
        $b = (float) str_replace(',', '', $m[1][1]);
        $ua = $m[0][2] ?? '';
        $ub = $m[1][2] ?? '';
        // "10-25 L" and "2 - 4 Cr": a start with no unit of its own, smaller than the end, is written in the end's unit. "50,000 - 1.5 L" is not (50,000 is already rupees).
        $borrow = $ua === '' && $ub !== '' && $a <= $b;
        $a *= $factor($ua !== '' ? $ua : ($borrow ? $ub : ''));
        $b *= $factor($ub);

        return ['min' => min($a, $b), 'max' => max($a, $b)];
    }

    /** Whether an offer says, in its own words, that it is a service. A whole word (after folding) in its name, what it does or its deployment model. */
    public static function offerSaysService(?string $name, ?string $whatItDoes, ?string $deployment): bool
    {
        return array_intersect(self::tokens((string) $name . ' ' . (string) $whatItDoes . ' ' . (string) $deployment), self::SERVICE_WORDS) !== [];
    }

    /** A name that looks like a test row. */
    public static function looksLikeJunk(string $name): bool
    {
        return array_intersect(self::tokens($name), self::JUNK_WORDS) !== [];
    }

    // ------------------------------------------------------------------ suggestions

    /**
     * One organisation's suggestions. No write is made.
     *
     * @return array{tenant: int, thresholds: array<string, float>, items: list<array<string, mixed>>, unmatchedSubProducts: list<array<string, mixed>>, binnedItems: list<array<string, mixed>>, decisions: list<array<string, mixed>>, notes: list<string>, counts: array<string, mixed>, serviceRule: string}
     */
    public function suggest(int $tenant): array
    {
        $notes = [];
        if ($tenant <= 0 || ! $this->has('g2g_product_master') || ! $this->has('crm_products') || ! $this->has('g2g_product_offers')) {
            return $this->result($tenant, [], [], [], [], ['the Product Master, the CRM catalogue or the Portfolio table is not on this host, so there is nothing to compare'], [], 0, 0);
        }

        $masters = $this->db->table('g2g_product_master')->where('sub_institute_id', $tenant)->whereIn('kind', [ProductMaster::KIND_ITEM, ProductMaster::KIND_SUB_PRODUCT])->orderBy('id')->get();
        $crmIds = $masters->where('kind', ProductMaster::KIND_ITEM)->pluck('crm_product_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $offerIds = $masters->where('kind', ProductMaster::KIND_SUB_PRODUCT)->pluck('offer_row_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $parentIds = $masters->pluck('parent_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        $crm = $this->byIds('crm_products', $crmIds, $tenant);
        $offers = $this->byIds('g2g_product_offers', $offerIds, $tenant);
        $products = array_filter($this->byIds('g2g_product_master', $parentIds, null), fn (object $p): bool => $p->kind === ProductMaster::KIND_PRODUCT && (int) $p->sub_institute_id === $tenant);
        $decisions = $this->decisionRows($tenant);
        $rejected = [];
        foreach ($decisions as $d) {
            if ($d->decision === 'different') {
                $rejected[(int) $d->item_master_id][(int) $d->survivor_master_id] = true;
            }
        }

        // ---- the items
        $skipped = ['archived' => 0, 'no catalogue item' => 0, 'binned' => 0];
        $items = [];
        foreach ($masters as $m) {
            if ($m->kind !== ProductMaster::KIND_ITEM) {
                continue;
            }
            if ($m->status !== ProductMaster::STATUS_ACTIVE) {
                $skipped['archived']++;

                continue;
            }
            $row = $m->crm_product_id !== null ? ($crm[(int) $m->crm_product_id] ?? null) : null;
            if ($row === null) {
                $skipped['no catalogue item']++;

                continue;
            }
            if ($row->deleted_at !== null) {
                $skipped['binned']++;

                continue;
            }
            $items[(int) $m->id] = $this->itemProfile($m, $row, $products);
        }
        foreach ($skipped as $why => $n) {
            if ($n > 0) {
                $notes[] = "{$n} item master row(s) were not considered: {$why}";
            }
        }
        $usage = $this->usage(array_map(fn (array $i): int => $i['crmId'], array_values($items)), $tenant);
        foreach ($items as $id => $_) {
            $items[$id]['usage'] = $usage[$items[$id]['crmId']] ?? $this->emptyUsage();
        }

        // ---- the candidates: Sub-products of the same Product with an offer and no catalogue pointer yet
        $subs = [];
        $serviceOffers = 0;
        $offerCount = 0;
        foreach ($masters as $m) {
            if ($m->kind !== ProductMaster::KIND_SUB_PRODUCT || $m->status !== ProductMaster::STATUS_ACTIVE || $m->crm_product_id !== null || $m->offer_row_id === null) {
                continue;
            }
            $offer = $offers[(int) $m->offer_row_id] ?? null;
            if ($offer === null) {
                $notes[] = "Sub-product {$m->id} points at offer {$m->offer_row_id}, which is not this organisation's, so it was not considered";

                continue;
            }
            $profile = $this->candidateProfile($m, $offer, $products);
            $offerCount++;
            $serviceOffers += $profile['isService'] ? 1 : 0;
            $subs[(int) $m->id] = $profile;
        }

        // ---- scores: every item against the Sub-products of its own Product, of its own kind (a service only against services)
        $scores = [];
        foreach ($items as $iid => $item) {
            if ($item['junk'] || $item['productId'] === null) {
                continue;
            }
            foreach ($subs as $cid => $sub) {
                if ($sub['productId'] !== $item['productId'] || $sub['isService'] !== ($item['type'] === 'service') || isset($rejected[$iid][$cid])) {
                    continue;
                }
                $scores[$iid][$cid] = $this->score($item, $sub);
            }
        }

        $rows = $this->verdicts($items, $subs, $scores, $rejected);

        // ---- Sub-products that no item names, for the second table
        $named = [];
        foreach ($rows as $r) {
            foreach ($r['alternatives'] as $alt) {
                $named[$alt['masterId']] = true;
            }
        }
        $unmatched = [];
        foreach ($subs as $cid => $sub) {
            if (! isset($named[$cid])) {
                $unmatched[] = ['masterId' => $cid, 'offerRowId' => $sub['offerRowId'], 'offerCode' => $sub['offerCode'], 'name' => $sub['name'], 'productId' => $sub['productId'], 'productName' => $sub['productName'], 'isService' => $sub['isService'], 'readiness' => $sub['readiness']];
            }
        }

        // ---- catalogue rows that are in the Bin, and live rows no master row points at (for the report; neither is paired)
        $binned = $this->binned($tenant);
        $withMaster = array_flip($masters->pluck('crm_product_id')->filter()->map(fn ($v) => (int) $v)->all());
        $orphans = $this->db->table('crm_products')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->filter(fn (int $id): bool => ! isset($withMaster[$id]))->values()->all();
        if ($orphans !== []) {
            $notes[] = 'live catalogue item(s) with no master row (run product-master:repair first, then they can be paired): ' . implode(', ', array_slice($orphans, 0, 20)) . (count($orphans) > 20 ? ', ...' : '');
        }

        $names = [];
        foreach ($masters as $m) {
            $names[(int) $m->id] = (string) $m->name;
        }

        return $this->result($tenant, $rows, $unmatched, $binned, $this->presentDecisions($decisions, $names), $notes, array_values($subs), $serviceOffers, $offerCount);
    }

    /**
     * The evidence for ONE pair a person is about to decide on, whether or not it was suggested. Null when either row is not loadable (not this organisation's, or
     * not an item and a Sub-product).
     *
     * @return ?array{score: float, parts: array<string, float>, reasons: list<string>, signals: string, typeMismatch: bool}
     */
    public function pair(int $tenant, int $itemMasterId, int $survivorMasterId): ?array
    {
        if ($tenant <= 0 || ! $this->has('g2g_product_master') || ! $this->has('crm_products') || ! $this->has('g2g_product_offers')) {
            return null;
        }
        $item = $this->db->table('g2g_product_master')->where('id', $itemMasterId)->where('sub_institute_id', $tenant)->where('kind', ProductMaster::KIND_ITEM)->first();
        $sub = $this->db->table('g2g_product_master')->where('id', $survivorMasterId)->where('sub_institute_id', $tenant)->where('kind', ProductMaster::KIND_SUB_PRODUCT)->first();
        if ($item === null || $sub === null || $item->crm_product_id === null || $sub->offer_row_id === null) {
            return null;
        }
        $crm = $this->db->table('crm_products')->where('id', $item->crm_product_id)->where('sub_institute_id', $tenant)->first();
        $offer = $this->db->table('g2g_product_offers')->where('id', $sub->offer_row_id)->where('sub_institute_id', $tenant)->first();
        if ($crm === null || $offer === null) {
            return null;
        }
        $products = array_filter($this->byIds('g2g_product_master', array_values(array_filter([(int) $item->parent_id, (int) $sub->parent_id])), null), fn (object $p): bool => $p->kind === ProductMaster::KIND_PRODUCT);
        $i = $this->itemProfile($item, $crm, $products);
        $c = $this->candidateProfile($sub, $offer, $products);
        $score = $this->score($i, $c);

        return [
            'score' => $score['score'], 'parts' => $score['parts'], 'reasons' => $score['reasons'], 'signals' => self::signalsText($score), 'typeMismatch' => $c['isService'] !== ($i['type'] === 'service'),
        ];
    }

    // ------------------------------------------------------------------ scoring

    /**
     * @param array<string, mixed> $i item profile
     * @param array<string, mixed> $c candidate profile
     * @return array{score: float, parts: array<string, float>, reasons: list<string>, identity: bool}
     */
    private function score(array $i, array $c): array
    {
        $parts = ['name' => 0.0, 'category' => 0.0, 'sku' => 0.0, 'words' => 0.0, 'text' => 0.0, 'acronym' => 0.0, 'price' => 0.0];
        $reasons = [];

        // NAME (identity): the same name, or one name wholly inside the other (the "<Sub-product> - <Edition>" pattern). Brand words are ignored for the second test.
        if ($i['nameTokens'] !== [] && $i['nameTokens'] === $c['nameTokens']) {
            $parts['name'] = self::W_NAME_SAME;
            $reasons[] = 'the names are the same (+' . self::W_NAME_SAME . ')';
        } elseif (($inner = self::containsRun($c['nameNoBrand'], $i['nameNoBrand']) ?? self::containsRun($i['nameNoBrand'], $c['nameNoBrand'])) !== null && count($inner) >= 2) {
            $parts['name'] = self::W_NAME_CONTAINS;
            $reasons[] = "one name holds the other: '" . implode(' ', $inner) . "' (+" . self::W_NAME_CONTAINS . ')';
        }

        // CATEGORY (identity): the item's category inside the Sub-product's name.
        if ($i['categoryContent'] !== []) {
            if (self::containsRun($c['nameNoBrand'], $i['categoryNoBrand']) !== null) {
                $parts['category'] = self::W_CATEGORY_PHRASE;
                $reasons[] = "the category '" . $i['category'] . "' is inside the Sub-product name (+" . self::W_CATEGORY_PHRASE . ')';
            } elseif (array_diff($i['categoryContent'], $c['nameContent']) === []) {
                $parts['category'] = self::W_CATEGORY_WORDS;
                $reasons[] = "every word of the category '" . $i['category'] . "' is in the Sub-product name (+" . self::W_CATEGORY_WORDS . ')';
            }
        }

        // SKU (identity): the offer id is a run of tokens of the SKU.
        if ($c['offerTokens'] !== [] && $i['skuTokens'] !== [] && (count($c['offerTokens']) >= 2 || mb_strlen($c['offerTokens'][0]) >= 4) && self::containsRun($i['skuTokens'], $c['offerTokens']) !== null) {
            $parts['sku'] = self::W_SKU;
            $reasons[] = "the SKU {$i['sku']} holds the Sub-product code {$c['offerCode']} (+" . self::W_SKU . ')';
        }

        // WORDS: content words the two NAMES share.
        $shared = array_values(array_intersect($i['nameContent'], $c['nameContent']));
        if ($shared !== []) {
            $parts['words'] = min(self::W_WORD_MAX, self::W_WORD * count($shared));
            $reasons[] = 'the names share: ' . implode(', ', $shared) . ' (+' . $parts['words'] . ')';
        }

        // TEXT: content words of the item (name and description) in the Sub-product's name, what it does and modules, not counted above.
        $sharedText = array_values(array_diff(array_intersect(array_unique(array_merge($i['nameContent'], $i['descContent'])), $c['textContent']), $shared));
        if ($sharedText !== []) {
            $parts['text'] = min(self::W_TEXT_MAX, self::W_TEXT * count($sharedText));
            $reasons[] = 'the descriptions share ' . count($sharedText) . ' word(s): ' . implode(', ', array_slice($sharedText, 0, 6)) . ' (+' . $parts['text'] . ')';
        }

        // ACRONYM: "LMS" on one side, "Learning Management System" on the other (and not already a plain shared word).
        $hit = self::acronymHit($i, $c) ?? self::acronymHit($c, $i);
        if ($hit !== null) {
            $parts['acronym'] = self::W_ACRONYM;
            $reasons[] = "'" . strtoupper($hit[0]) . "' stands for '" . $hit[1] . "' (+" . self::W_ACRONYM . ')';
        }

        // PRICE: a hint.
        if ($i['price'] !== null && $c['band'] !== null && $i['price'] >= $c['band']['min'] && $i['price'] <= $c['band']['max']) {
            $parts['price'] = self::W_PRICE;
            $reasons[] = 'the price lies inside the typical deal band (+' . self::W_PRICE . ')';
        }

        $total = min(100.0, round(array_sum($parts), 2));

        return ['score' => $total, 'parts' => $parts, 'reasons' => $reasons, 'identity' => $parts['name'] + $parts['category'] + $parts['sku'] > 0];
    }

    /**
     * Whether $needle sits, whole and in order, inside $hay (tokens side by side). Directional: the needle is the one that must fit inside the hay.
     *
     * @param list<string> $hay
     * @param list<string> $needle
     * @return ?list<string> the needle, when it is inside; null when it is empty, longer than the hay, or not there
     */
    private static function containsRun(array $hay, array $needle): ?array
    {
        $n = count($needle);
        if ($n === 0 || $n > count($hay)) {
            return null;
        }
        for ($start = 0; $start + $n <= count($hay); $start++) {
            if (array_slice($hay, $start, $n) === $needle) {
                return $needle;
            }
        }

        return null;
    }

    /**
     * An acronym of the FIRST profile spelled out in the SECOND profile's text; null when none, or when the acronym is already a plain word of the second.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return ?array{0: string, 1: string} acronym, the words that spell it
     */
    private static function acronymHit(array $a, array $b): ?array
    {
        foreach ($a['acronyms'] as $acro) {
            $n = mb_strlen($acro);
            if ($n < 2 || $n > 5 || in_array($acro, $b['allTokens'], true)) {
                continue;
            }
            $words = $b['rawWords'];
            for ($start = 0; $start < count($words); $start++) {
                $take = [];
                for ($k = $start; $k < count($words) && count($take) < $n; $k++) {
                    if (count($take) > 0 && in_array($words[$k], self::CONNECTORS, true) && $k + 1 < count($words)) {
                        continue;
                    }
                    $take[] = $words[$k];
                }
                if (count($take) === $n && implode('', array_map(fn (string $w): string => mb_substr($w, 0, 1), $take)) === $acro) {
                    return [$acro, implode(' ', $take)];
                }
            }
        }

        return null;
    }

    /** @param array{score: float, parts: array<string, float>, reasons: list<string>} $score */
    private static function signalsText(array $score): string
    {
        $bits = [];
        foreach ($score['parts'] as $name => $points) {
            if ($points > 0) {
                $bits[] = $name . ' +' . rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');
            }
        }

        return mb_substr($bits === [] ? 'no signal' : implode(', ', $bits), 0, 500);
    }

    // ------------------------------------------------------------------ profiles

    /**
     * @param array<int, object> $products the Products by id
     * @return array<string, mixed>
     */
    private function itemProfile(object $master, object $crm, array $products): array
    {
        $productId = $master->parent_id !== null && (int) $master->parent_id > 0 ? (int) $master->parent_id : (((int) ($crm->product_master_id ?? 0)) > 0 ? (int) $crm->product_master_id : null);
        $product = $productId !== null ? ($products[$productId] ?? null) : null;
        if ($product === null) {
            $productId = null;
        }
        $brand = self::brandWords($product);
        $name = (string) $crm->name;
        $category = trim((string) ($crm->category ?? ''));
        $description = (string) ($crm->description ?? '');
        $nameTokens = self::tokens($name);
        $categoryTokens = self::tokens($category);
        $sku = trim((string) ($crm->sku ?? ''));

        return [
            'masterId' => (int) $master->id, 'crmId' => (int) $crm->id, 'productNo' => (string) ($crm->product_no ?? ''), 'name' => $name, 'sku' => $sku !== '' ? $sku : null,
            'category' => $category !== '' ? $category : null, 'type' => ($crm->item_type ?? 'product') === 'service' ? 'service' : 'product',
            'price' => isset($crm->unit_price) && $crm->unit_price !== null ? (float) $crm->unit_price : null, 'currency' => $crm->currency ?? null,
            'productId' => $productId, 'productName' => $product !== null ? (string) $product->name : null, 'junk' => self::looksLikeJunk($name),
            'nameTokens' => $nameTokens, 'nameNoBrand' => array_values(array_filter($nameTokens, fn (string $t): bool => ! isset($brand[$t]))),
            'nameContent' => self::content($nameTokens, $brand), 'descContent' => self::content(self::tokens($description), $brand),
            'categoryNoBrand' => array_values(array_filter($categoryTokens, fn (string $t): bool => ! isset($brand[$t]))), 'categoryContent' => self::content($categoryTokens, $brand),
            'skuTokens' => self::tokens($sku), 'acronyms' => self::acronymsIn($name . ' ' . $description), 'rawWords' => self::rawWords($name . ' ' . $description),
            'allTokens' => array_merge($nameTokens, self::tokens($description)), 'usage' => $this->emptyUsage(),
        ];
    }

    /**
     * @param array<int, object> $products
     * @return array<string, mixed>
     */
    private function candidateProfile(object $master, object $offer, array $products): array
    {
        $productId = $master->parent_id !== null && (int) $master->parent_id > 0 ? (int) $master->parent_id : null;
        $product = $productId !== null ? ($products[$productId] ?? null) : null;
        $brand = self::brandWords($product);
        $name = (string) $offer->name;
        $what = (string) ($offer->what_it_does ?? '');
        $modules = (string) ($offer->modules_components ?? '');
        $nameTokens = self::tokens($name);
        $text = $name . ' ' . $what . ' ' . $modules;

        return [
            'masterId' => (int) $master->id, 'offerRowId' => (int) $offer->id, 'offerCode' => (string) ($offer->offer_id ?? ''), 'name' => $name, 'productId' => $productId,
            'productName' => $product !== null ? (string) $product->name : null, 'readiness' => $offer->readiness_status ?? null,
            'isService' => self::offerSaysService($name, $what, $offer->deployment_model ?? null),
            'nameTokens' => $nameTokens, 'nameNoBrand' => array_values(array_filter($nameTokens, fn (string $t): bool => ! isset($brand[$t]))), 'nameContent' => self::content($nameTokens, $brand),
            'textContent' => self::content(self::tokens($text), $brand), 'offerTokens' => self::tokens((string) ($offer->offer_id ?? '')), 'band' => self::parseBand($offer->typical_deal_band ?? null),
            'acronyms' => self::acronymsIn($name . ' ' . $what), 'rawWords' => self::rawWords($text), 'allTokens' => self::tokens($text),
        ];
    }

    /** Upper-case words of 2 to 5 letters in the ORIGINAL text, lower-cased ("LMS", "CCE"). @return list<string> */
    private static function acronymsIn(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])\p{Lu}{2,5}(?![\p{L}\p{N}])/u', $text, $m);

        return array_values(array_unique(array_map('mb_strtolower', $m[0])));
    }

    /** Every word, lower-cased, nothing dropped and nothing folded: the words an acronym is spelled from. @return list<string> */
    private static function rawWords(string $text): array
    {
        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    // ------------------------------------------------------------------ verdicts

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $subs
     * @param array<int, array<int, array<string, mixed>>> $scores item => candidate => score
     * @param array<int, array<int, true>> $rejected
     * @return list<array<string, mixed>>
     */
    private function verdicts(array $items, array $subs, array $scores, array $rejected): array
    {
        // Each item's Sub-products that reach the floor, best first.
        $ranked = [];
        foreach ($scores as $iid => $byCandidate) {
            $list = [];
            foreach ($byCandidate as $cid => $s) {
                if ($s['score'] >= self::FLOOR) {
                    $list[] = ['cid' => $cid, 'score' => $s['score']];
                }
            }
            usort($list, fn (array $x, array $y): int => $y['score'] <=> $x['score'] ?: $x['cid'] <=> $y['cid']);
            if ($list !== []) {
                $ranked[$iid] = $list;
            }
        }

        // Items whose best Sub-product is the same one, each scoring enough to be an edition of it.
        $claims = [];
        foreach ($ranked as $iid => $list) {
            if ($list[0]['score'] >= self::EDITION_MIN) {
                $claims[$list[0]['cid']][] = $iid;
            }
        }

        $rows = [];
        foreach ($items as $iid => $item) {
            $list = $ranked[$iid] ?? [];
            $best = $list[0] ?? null;
            $second = $list[1]['score'] ?? 0.0;
            $margin = $best !== null ? round($best['score'] - $second, 2) : 0.0;
            $verdict = self::NO_MATCH;
            $why = [];
            $sharedWith = [];

            if ($item['junk']) {
                $verdict = self::JUNK;
                $why[] = 'the name looks like a test row; removing it goes through the Recycle Bin (it is in use ' . $item['usage']['total'] . ' time(s))';
            } elseif ($item['productId'] === null) {
                $verdict = self::STRAY;
                $why[] = 'this CRM record has no Product, so there is no set of Sub-products to look in: give it a Product first';
            } elseif ($best === null) {
                $verdict = $item['type'] === 'service' ? self::SERVICE : self::NO_MATCH;
                $why[] = $item['type'] === 'service'
                    ? 'a service is compared with Sub-products that say they are a service, and none of this Product does'
                    : 'no Sub-product of this Product reaches a score of ' . self::FLOOR;
            } else {
                $cid = $best['cid'];
                $why = $scores[$iid][$cid]['reasons'];
                $group = $claims[$cid] ?? [];
                // The best score any OTHER item gets for the same Sub-product, whichever Sub-product that item prefers.
                $rival = 0.0;
                foreach ($scores as $other => $byCandidate) {
                    if ($other !== $iid && isset($byCandidate[$cid])) {
                        $rival = max($rival, $byCandidate[$cid]['score']);
                    }
                }

                if (count($group) >= 2 && in_array($iid, $group, true)) {
                    $verdict = self::EDITION;
                    $sharedWith = array_values(array_diff($group, [$iid]));
                    $why[] = 'CRM record(s) ' . implode(', ', array_map(fn (int $o): string => $o . ' "' . $items[$o]['name'] . '"', $sharedWith)) . ' have the same best Sub-product: they are cuts of one thing, choose which one is the pair';
                } elseif ($best['score'] >= self::HIGH && $margin >= self::MARGIN && $best['score'] - $rival >= self::MARGIN && $scores[$iid][$cid]['identity']) {
                    $verdict = self::MATCH_HIGH;
                } else {
                    $verdict = self::AMBIGUOUS;
                    if ($best['score'] < self::HIGH) {
                        $why[] = 'weak: the score is below ' . self::HIGH;
                    }
                    if ($margin < self::MARGIN && $second > 0) {
                        $why[] = 'close to the next Sub-product (' . $subs[$list[1]['cid']]['name'] . ', ' . $second . ')';
                    }
                    if ($best['score'] - $rival < self::MARGIN && $rival >= self::FLOOR) {
                        $why[] = 'another CRM record scores ' . $rival . ' for the same Sub-product';
                    }
                }
            }

            $rows[] = [
                'itemMasterId' => $iid, 'crmId' => $item['crmId'], 'productNo' => $item['productNo'], 'name' => $item['name'], 'sku' => $item['sku'], 'category' => $item['category'],
                'type' => $item['type'], 'price' => $item['price'], 'currency' => $item['currency'], 'state' => 'live', 'productId' => $item['productId'], 'productName' => $item['productName'],
                'usage' => $item['usage'], 'verdict' => $verdict,
                'candidate' => $best !== null && ! in_array($verdict, [self::JUNK, self::STRAY, self::SERVICE, self::NO_MATCH], true) ? $this->candidateView($subs[$best['cid']]) : null,
                'score' => $best['score'] ?? 0.0, 'margin' => $margin, 'parts' => $best !== null ? $scores[$iid][$best['cid']]['parts'] : [], 'reasons' => $why, 'sharedWith' => $sharedWith,
                'alternatives' => array_map(fn (array $a): array => ['masterId' => $a['cid'], 'offerCode' => $subs[$a['cid']]['offerCode'], 'name' => $subs[$a['cid']]['name'], 'score' => $a['score']], array_slice($list, 0, 3)),
                'rejected' => array_keys($rejected[$iid] ?? []),
            ];
        }

        $order = array_flip(self::VERDICTS);
        usort($rows, fn (array $a, array $b): int => [(string) $a['productName'], $order[$a['verdict']], -$a['score'], $a['name']] <=> [(string) $b['productName'], $order[$b['verdict']], -$b['score'], $b['name']]);

        return $rows;
    }

    /** @param array<string, mixed> $sub @return array<string, mixed> */
    private function candidateView(array $sub): array
    {
        return ['masterId' => $sub['masterId'], 'offerRowId' => $sub['offerRowId'], 'offerCode' => $sub['offerCode'], 'name' => $sub['name'], 'readiness' => $sub['readiness']];
    }

    // ------------------------------------------------------------------ reading

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $unmatched
     * @param list<array<string, mixed>> $binned
     * @param list<array<string, mixed>> $decisions
     * @param list<string> $notes
     * @param list<array<string, mixed>> $subs
     * @return array<string, mixed>
     */
    private function result(int $tenant, array $rows, array $unmatched, array $binned, array $decisions, array $notes, array $subs, int $serviceOffers, int $offerCount): array
    {
        $by = array_fill_keys(self::VERDICTS, 0);
        foreach ($rows as $r) {
            $by[$r['verdict']]++;
        }

        return [
            'tenant' => $tenant,
            'thresholds' => ['floor' => self::FLOOR, 'high' => self::HIGH, 'margin' => self::MARGIN, 'editionMin' => self::EDITION_MIN],
            'items' => $rows, 'unmatchedSubProducts' => $unmatched, 'binnedItems' => $binned, 'decisions' => $decisions, 'notes' => $notes,
            'counts' => ['items' => count($rows), 'byVerdict' => $by, 'subProducts' => count($subs), 'unmatchedSubProducts' => count($unmatched), 'binned' => count($binned), 'serviceOffers' => $serviceOffers, 'offers' => $offerCount],
            'serviceRule' => $serviceOffers . ' of ' . $offerCount . ' Sub-product offer(s) say they are a service (a whole word: ' . implode(', ', self::SERVICE_WORDS) . ', in the name, what it does or the deployment model)',
        ];
    }

    /** @return array<string, int> */
    private function emptyUsage(): array
    {
        $out = [];
        foreach (ProductPointers::toCrmProductInUse() as $e) {
            $out[self::USAGE_LABELS[$e['table']] ?? $e['table']] = 0;
        }
        $out['total'] = 0;

        return $out;
    }

    /**
     * Rows per catalogue item in every table that points at crm_products, inside the organisation (the document's, for a line). Never an input of a score.
     *
     * @param list<int> $crmIds
     * @return array<int, array<string, int>>
     */
    private function usage(array $crmIds, int $tenant): array
    {
        $out = [];
        foreach ($crmIds as $id) {
            $out[$id] = $this->emptyUsage();
        }
        if ($crmIds === []) {
            return $out;
        }
        foreach (ProductPointers::toCrmProductInUse() as $e) {
            $scope = $e['scope'];
            if (! $this->has($e['table']) || ! $this->column($e['table'], $e['column'])) {
                continue;
            }
            $label = self::USAGE_LABELS[$e['table']] ?? $e['table'];
            foreach (array_chunk($crmIds, self::CHUNK) as $chunk) {
                $q = $this->db->table($e['table'] . ' as r')->whereIn('r.' . $e['column'], $chunk);
                if (is_string($scope)) {
                    if (! $this->column($e['table'], $scope)) {
                        continue 2;
                    }
                    $q->where('r.' . $scope, $tenant);
                } else {
                    if (! $this->has($scope['parent']) || ! $this->column($e['table'], $scope['via']) || ! $this->column($scope['parent'], $scope['parent_tenant'])) {
                        continue 2;
                    }
                    $q->whereIn('r.' . $scope['via'], fn ($s) => $s->select($scope['parent_key'])->from($scope['parent'])->where($scope['parent_tenant'], $tenant));
                }
                foreach ($q->selectRaw('r.' . $e['column'] . ' as pid, COUNT(*) as n')->groupBy('r.' . $e['column'])->get() as $row) {
                    $out[(int) $row->pid][$label] += (int) $row->n;
                    $out[(int) $row->pid]['total'] += (int) $row->n;
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the organisation's catalogue rows that are in the Recycle Bin, with their usage */
    private function binned(int $tenant): array
    {
        $rows = $this->db->table('crm_products')->where('sub_institute_id', $tenant)->whereNotNull('deleted_at')->orderBy('id')->get();
        $usage = $this->usage($rows->pluck('id')->map(fn ($v) => (int) $v)->all(), $tenant);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'crmId' => (int) $r->id, 'productNo' => (string) ($r->product_no ?? ''), 'name' => (string) $r->name, 'sku' => $r->sku ?? null, 'type' => ($r->item_type ?? 'product') === 'service' ? 'service' : 'product',
                'price' => isset($r->unit_price) && $r->unit_price !== null ? (float) $r->unit_price : null, 'state' => 'binned', 'binnedAt' => (string) $r->deleted_at, 'usage' => $usage[(int) $r->id] ?? $this->emptyUsage(),
            ];
        }

        return $out;
    }

    /** @return list<object> */
    private function decisionRows(int $tenant): array
    {
        if (! $this->has('g2g_product_match_decisions')) {
            return [];
        }

        return $this->db->table('g2g_product_match_decisions')->where('sub_institute_id', $tenant)->orderBy('id')->get()->all();
    }

    /**
     * @param list<object> $rows
     * @param array<int, string> $names
     * @return list<array<string, mixed>>
     */
    private function presentDecisions(array $rows, array $names): array
    {
        $out = [];
        foreach ($rows as $d) {
            $out[] = [
                'id' => (int) $d->id, 'itemMasterId' => (int) $d->item_master_id, 'itemName' => $names[(int) $d->item_master_id] ?? null, 'survivorMasterId' => (int) $d->survivor_master_id,
                'survivorName' => $names[(int) $d->survivor_master_id] ?? null, 'decision' => (string) $d->decision, 'score' => $d->score !== null ? (float) $d->score : null, 'signals' => $d->signals,
                'nameKept' => $d->name_kept, 'decidedBy' => $d->decided_by !== null ? (int) $d->decided_by : null, 'decidedAt' => $d->decided_at,
            ];
        }

        return $out;
    }

    /**
     * The rows with these ids, keyed by id. `$tenant` limits a table that has an organisation column to that organisation (null: not limited; the caller filters).
     *
     * @param list<int> $ids
     * @return array<int, object>
     */
    private function byIds(string $table, array $ids, ?int $tenant): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $q = $this->db->table($table)->whereIn('id', $chunk);
            if ($tenant !== null) {
                $q->where('sub_institute_id', $tenant);
            }
            foreach ($q->get() as $row) {
                $out[(int) $row->id] = $row;
            }
        }

        return $out;
    }

    private function has(string $table): bool
    {
        return $this->present[$table] ??= Schema::connection($this->db->getName())->hasTable($table);
    }

    private function column(string $table, string $column): bool
    {
        return $this->present[$table . '.' . $column] ??= $this->has($table) && ColumnExists::has($table, $column, $this->db);
    }
}
