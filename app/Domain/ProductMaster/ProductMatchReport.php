<?php

namespace App\Domain\ProductMaster;

/**
 * The dry-run report of `product-master:unify` as plain text (slice 15, track P2-D): what a person reads before confirming any pair. It never decides anything and it
 * is the same on screen and in the saved file.
 *
 * Per organisation: the thresholds in words, a summary (a count per verdict, the services finding), then ONE row per live catalogue item with the columns the plan asks for
 * (Product, CRM id, master id, product no, name, SKU, type, price, live or binned, the usage counts, the candidate Sub-product, the score of every signal, the margin, the
 * verdict) followed by the reasons in plain words; a second table of the Sub-products no item names; the catalogue rows in the Recycle Bin (with their usage: a quote line
 * may still point at one); the answers already given; and how to confirm a pair.
 *
 * Product names and prices are the only data in it: there is no personal data in the product tables.
 */
final class ProductMatchReport
{
    /** @var array<string, string> the short names of the signals, as the report prints them */
    private const SIGNAL = ['name' => 'name', 'category' => 'cat', 'sku' => 'sku', 'words' => 'words', 'text' => 'text', 'acronym' => 'acro', 'price' => 'price'];

    /** @var array<string, string> the short names of the usage counts */
    private const USAGE = [
        'opportunities' => 'opp', 'quotes' => 'quote', 'orders' => 'order', 'purchaseOrders' => 'po', 'invoices' => 'inv', 'tickets' => 'ticket', 'assets' => 'asset', 'faqs' => 'faq',
        'bundles' => 'bundle', 'campaigns' => 'campaign',
    ];

    /**
     * @param list<array<string, mixed>> $suggestions one ProductMatchSuggester::suggest() result per organisation
     * @param array<string, mixed> $meta mode, connection, host, database, session, generated
     */
    public static function render(array $suggestions, array $meta): string
    {
        $o = [];
        $o[] = 'product-master:unify - ' . ($meta['mode'] ?? 'DRY RUN (nothing is written)');
        $o[] = sprintf('Read: connection %s (%s), host %s, database %s, session read-only: %s', $meta['connection'] ?? '-', $meta['driver'] ?? '-', $meta['host'] ?? '-', $meta['database'] ?? '-', $meta['session'] ?? '-');
        $o[] = 'Generated: ' . ($meta['generated'] ?? '-');
        $o[] = '';
        $o[] = 'How to read it. Each live catalogue item is compared ONLY with the Sub-products of its own Product that have an offer and no catalogue item yet. Nothing is merged by this';
        $o[] = 'report: a person confirms each pair (Same product / Different), and `--apply --map=<file>` merges only the pairs in the file.';
        $o[] = '  MATCH-HIGH one clear best Sub-product, ahead of the runner-up and of every other item   EDITION   two or more items have the same best Sub-product: choose which is the pair';
        $o[] = '  AMBIGUOUS  a best Sub-product exists but is weak, close to another, or contested         SERVICE   a service; no Sub-product that says it is a service fits';
        $o[] = '  NO-MATCH   nothing scores enough                                                         STRAY     the item has no Product, so there is nothing to look in';
        $o[] = '  JUNK?      the name looks like a test row (report only; removal goes through the Recycle Bin)';
        $o[] = 'Signals (score 0 to 100): name 45/35, cat(egory) 40/25, sku 30 are identity signals; words 12 each (max 24), text 4 each (max 16), acro(nym) 8, price 5 only support them.';

        foreach ($suggestions as $s) {
            $o[] = '';
            $o[] = str_repeat('=', 150);
            $t = $s['thresholds'];
            $o[] = sprintf('ORGANISATION %d   floor %s, high %s, margin %s, edition minimum %s', $s['tenant'], $t['floor'], $t['high'], $t['margin'], $t['editionMin']);
            $c = $s['counts'];
            $o[] = sprintf('Live catalogue items compared: %d.  Sub-products with an offer and no catalogue item: %d (%d of them named by no item).  Catalogue rows in the Recycle Bin: %d.', $c['items'], $c['subProducts'], $c['unmatchedSubProducts'], $c['binned']);
            $o[] = 'Verdicts: ' . implode(', ', array_map(fn (string $v): string => $v . ' ' . $c['byVerdict'][$v], ProductMatchSuggester::VERDICTS));
            $o[] = 'Services in the Portfolio data: ' . $s['serviceRule'];
            foreach ($s['notes'] as $note) {
                $o[] = 'Note: ' . $note;
            }

            $o[] = '';
            $o[] = 'TABLE 1 - live catalogue items, by Product';
            $o = array_merge($o, self::itemsTable($s['items']));

            $o[] = '';
            $o[] = 'TABLE 2 - Sub-products that no item names (they stay Portfolio-only; there is nothing to pair)';
            $o = array_merge($o, self::subProductsTable($s['unmatchedSubProducts']));

            $o[] = '';
            $o[] = 'TABLE 3 - catalogue rows in the Recycle Bin (never paired; a quote line may still point at one, so the picker must still show it on an old document)';
            $o = array_merge($o, self::binnedTable($s['binnedItems']));

            if ($s['decisions'] !== []) {
                $o[] = '';
                $o[] = 'ANSWERS ALREADY GIVEN';
                foreach ($s['decisions'] as $d) {
                    $o[] = sprintf('  [%s] item %d "%s" and Sub-product %d "%s"  score %s  (%s)  decided %s%s', strtoupper($d['decision']), $d['itemMasterId'], $d['itemName'] ?? '?', $d['survivorMasterId'], $d['survivorName'] ?? '?', $d['score'] ?? '-', $d['signals'] ?? '-', $d['decidedAt'] ?? '-', $d['nameKept'] ? ', kept the ' . $d['nameKept'] . ' name' : '');
                }
            }

            $o[] = '';
            $o[] = 'TO CONFIRM PAIRS: write a file, e.g. [{"item": <item master id>, "survivor": <Sub-product master id>, "name": "portfolio"}, ...] ("name" is the name that survives: portfolio or catalogue),';
            $o[] = sprintf('then `php artisan product-master:unify --tenant=%d --map=<file>` checks every pair without writing, and adding `--apply` merges them (a snapshot of every row is saved first; --rollback=<snapshot> undoes it).', $s['tenant']);
            $high = array_values(array_filter($s['items'], fn (array $r): bool => $r['verdict'] === ProductMatchSuggester::MATCH_HIGH));
            if ($high !== []) {
                $o[] = 'The MATCH-HIGH pairs, as a map to start from (a person still has to read each one):';
                $o[] = '  ' . json_encode(array_map(fn (array $r): array => ['item' => $r['itemMasterId'], 'survivor' => $r['candidate']['masterId'], 'name' => 'portfolio'], $high), JSON_UNESCAPED_SLASHES);
            } else {
                $o[] = 'No pair is MATCH-HIGH here, so there is no map to start from: the EDITION and AMBIGUOUS rows above need a person to choose.';
            }
        }

        return implode("\n", $o) . "\n";
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private static function itemsTable(array $items): array
    {
        if ($items === []) {
            return ['  (no live catalogue item)'];
        }
        $rows = [];
        foreach ($items as $r) {
            $rows[] = [
                (string) ($r['productName'] ?? '(none)'), (string) $r['crmId'], (string) $r['itemMasterId'], $r['productNo'], self::cut($r['name'], 46), $r['sku'] ?? '-', $r['type'],
                $r['price'] !== null ? number_format((float) $r['price'], 2, '.', '') : '-', $r['state'], self::usage($r['usage']),
                $r['candidate'] !== null ? $r['candidate']['masterId'] . ' ' . $r['candidate']['offerCode'] . ' ' . self::cut($r['candidate']['name'], 34) : '-',
                self::scores($r), $r['candidate'] !== null || $r['score'] > 0 ? (string) $r['margin'] : '-', $r['verdict'],
            ];
        }
        $out = self::table(['Product', 'CRM id', 'Master', 'Product no', 'Name', 'SKU', 'Type', 'Price', 'State', 'Usage', 'Candidate Sub-product', 'Scores', 'Margin', 'Verdict'], $rows);
        $out[] = '';
        $out[] = 'Why, in words:';
        foreach ($items as $r) {
            $out[] = sprintf('  item %d %s -> %s', $r['itemMasterId'], self::cut($r['name'], 60), $r['verdict']);
            foreach ($r['reasons'] as $why) {
                $out[] = '      - ' . $why;
            }
            foreach (array_slice($r['alternatives'], 1) as $alt) {
                $out[] = sprintf('      next: %s %s (%s)', $alt['offerCode'], self::cut($alt['name'], 50), $alt['score']);
            }
            if ($r['rejected'] !== []) {
                $out[] = '      marked Different before: Sub-product(s) ' . implode(', ', $r['rejected']);
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $subs
     * @return list<string>
     */
    private static function subProductsTable(array $subs): array
    {
        if ($subs === []) {
            return ['  (none)'];
        }
        $rows = [];
        foreach ($subs as $u) {
            $rows[] = [(string) ($u['productName'] ?? '(none)'), (string) $u['masterId'], (string) $u['offerCode'], self::cut((string) $u['name'], 52), $u['isService'] ? 'service' : 'product', (string) ($u['readiness'] ?? '-')];
        }

        return self::table(['Product', 'Master', 'Offer', 'Name', 'Type', 'Readiness'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $binned
     * @return list<string>
     */
    private static function binnedTable(array $binned): array
    {
        if ($binned === []) {
            return ['  (none)'];
        }
        $rows = [];
        foreach ($binned as $b) {
            $note = ProductMatchSuggester::looksLikeJunk($b['name']) ? 'JUNK? (a test row' . ($b['usage']['total'] > 0 ? ', but ' . $b['usage']['total'] . ' line(s) still use it' : '') . ')' : ($b['usage']['total'] > 0 ? 'in use: keep' : '');
            $rows[] = [(string) $b['crmId'], $b['productNo'], self::cut($b['name'], 46), $b['type'], $b['price'] !== null ? number_format((float) $b['price'], 2, '.', '') : '-', 'binned', self::usage($b['usage']), $note];
        }

        return self::table(['CRM id', 'Product no', 'Name', 'Type', 'Price', 'State', 'Usage', 'Note'], $rows);
    }

    /** @param array<string, int> $usage */
    private static function usage(array $usage): string
    {
        $bits = [];
        foreach (self::USAGE as $key => $short) {
            if (($usage[$key] ?? 0) > 0) {
                $bits[] = $short . ' ' . $usage[$key];
            }
        }

        return $bits === [] ? '0' : implode(', ', $bits) . ' (=' . $usage['total'] . ')';
    }

    /** @param array<string, mixed> $r */
    private static function scores(array $r): string
    {
        $bits = [];
        foreach (self::SIGNAL as $key => $short) {
            $points = (float) ($r['parts'][$key] ?? 0);
            if ($points > 0) {
                $bits[] = $short . ' ' . rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');
            }
        }

        return $bits === [] ? '-' : implode(' ', $bits) . ' = ' . rtrim(rtrim(number_format((float) $r['score'], 2, '.', ''), '0'), '.');
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 3) . '...' : $text;
    }

    /**
     * A plain fixed-width table.
     *
     * @param list<string> $head
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private static function table(array $head, array $rows): array
    {
        $width = array_map('mb_strlen', $head);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $width[$i] = max($width[$i], mb_strlen($cell));
            }
        }
        $line = function (array $cells) use ($width): string {
            $parts = [];
            foreach ($cells as $i => $cell) {
                $parts[] = $cell . str_repeat(' ', $width[$i] - mb_strlen($cell));
            }

            return '  ' . rtrim(implode(' | ', $parts));
        };
        $out = [$line($head), '  ' . str_repeat('-', array_sum($width) + 3 * (count($width) - 1))];
        foreach ($rows as $row) {
            $out[] = $line($row);
        }

        return $out;
    }
}
