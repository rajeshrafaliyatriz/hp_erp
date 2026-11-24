<?php
/*
 * MARKET PROBE (preview, NOT the full engine): shows what kinds of opportunities a product's ICP finds in the live market.
 *
 *   php scripts/market-probe.php --icp=<ICP json exported from live> [--searches=8] [--pages=10] [--signals=5]
 *
 * What it does: takes an ICP exported read-only from live (JSON file; this process has NO live database connection), runs a few of the ICP's real
 * searches, reads the top pages (robots.txt respected, same fetcher as the pipeline), asks the AI to propose at most N opportunities from ONLY
 * those sources, then CHECKS each one: the quote must appear word-for-word on the page, and an evidence level (E0-E3) is assigned.
 * What it does NOT do: it is not the engine's own run. It does not use the qualification gate, deduplication, matching or the importer, and it
 * writes NO signal, run, source or company rows. Output: console + docs/signal-engine/probe/<name>.json.
 * The only database write is the AI call's own usage record (ai_usage_events) on the DEV database, written by the existing AI client.
 *
 * HARD LIMITS: searches <= 8, pages <= 12, AI attempts <= 2 (config signals.ai_attempts), signals <= 5. Refuses unless only the dev database is reachable.
 */
$root = dirname(__DIR__);
chdir($root);
foreach (['MAIL_MAILER' => 'log', 'QUEUE_CONNECTION' => 'sync', 'SIGNALS_EVIDENCE_DEPTH' => 'false'] as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
require $root . '/vendor/autoload.php';
if (is_file($root . '/tests/bootstrap-workcopy.php')) { require $root . '/tests/bootstrap-workcopy.php'; }
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Signals\Market\EvidenceMaturity;
use App\Domain\Signals\Market\SourceIndependence;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\Support\FetchFailure;
use App\Domain\Signals\Support\HostClasses;
use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\StructuredAi;

const MAX_SEARCHES = 8;
const MAX_PAGES = 12;
const MAX_SIGNALS = 5;
const TENANT = 6;

$opts = getopt('', ['icp:', 'searches::', 'pages::', 'signals::', 'name::']);
$icpFile = (string) ($opts['icp'] ?? '');
$nSearch = min(MAX_SEARCHES, max(1, (int) ($opts['searches'] ?? 8)));
$nPages = min(MAX_PAGES, max(1, (int) ($opts['pages'] ?? 10)));
$nSignals = min(MAX_SIGNALS, max(1, (int) ($opts['signals'] ?? 5)));
$name = preg_replace('/[^a-z0-9_-]/i', '', (string) ($opts['name'] ?? 'probe')) ?: 'probe';

// ── safety ───────────────────────────────────────────────────────────────────
$default = config('database.default');
if ((string) config("database.connections.$default.host") !== '202.47.117.220' || config('database.connections.db3.host') || config('database.connections.live.host')) {
    fwrite(STDERR, "REFUSING: this process must reach the development database only.\n");
    exit(2);
}
if (! is_file($icpFile)) { fwrite(STDERR, "REFUSING: --icp file not found.\n"); exit(2); }
$icp = json_decode((string) file_get_contents($icpFile), true);
$cfg = (array) ($icp['config'] ?? []);
if (($cfg['queries'] ?? []) === []) { fwrite(STDERR, "REFUSING: the ICP has no searches.\n"); exit(2); }

// ── 1. choose searches: spread across the product's offers ───────────────────
$byOffer = [];
foreach ($cfg['queries'] as $q) {
    $prefix = explode('-q', (string) ($q['id'] ?? ''), 2)[0] ?: 'x';
    $byOffer[$prefix][] = $q;
}
$chosen = [];
for ($round = 0; count($chosen) < $nSearch && $round < 10; $round++) {
    foreach ($byOffer as $list) {
        if (isset($list[$round]) && count($chosen) < $nSearch) { $chosen[] = $list[$round]; }
    }
}
echo "ICP: " . ($icp['product'] ?? '?') . ' / ' . ($icp['customer_model'] ?? '?') . ' (' . count($cfg['queries']) . " searches in the ICP; running " . count($chosen) . ")\n";

// ── 2. search ────────────────────────────────────────────────────────────────
$search = SearchProviderFactory::make();
if ($search === null || ! $search->isConfigured()) { fwrite(STDERR, "No search provider configured.\n"); exit(2); }
$results = []; $log = [];
foreach ($chosen as $i => $q) {
    try {
        $r = $search->search((string) $q['q'], (int) ($q['days'] ?? 45), 6, ['topic' => ($q['topic'] ?? 'news') === 'general' ? 'general' : 'news']);
        $log[] = ['q' => $q['q'], 'results' => count($r)];
        foreach ($r as $x) { $results[$x['url']] ??= $x + ['query' => $q['q']]; }
    } catch (Throwable $e) {
        $log[] = ['q' => $q['q'], 'error' => preg_match('/HTTP (\d{3})/', $e->getMessage(), $m) ? 'http_' . $m[1] : class_basename($e)];
    }
    usleep(900000);
}
echo 'searches: ' . json_encode(array_map(fn ($l) => ($l['results'] ?? $l['error']), $log)) . '; distinct results: ' . count($results) . "\n";

// ── 3. read pages ────────────────────────────────────────────────────────────
$fetcher = new SafeUrlFetcher();
$robots = new RobotsPolicy($fetcher);
$skipHosts = (array) config('signals.opportunities.skip_hosts', []);
$sources = []; $fetchLog = []; $read = 0;
$rank = fn ($u) => preg_match('/(^|\.)(gov\.in|nic\.in|gov|edu\.in|ac\.in|org\.in)$/', strtolower((string) parse_url($u, PHP_URL_HOST))) === 1 ? 0 : 1;
uksort($results, fn ($a, $b) => $rank($a) <=> $rank($b));   // stable for equal ranks in PHP 8
foreach ($results as $url => $x) {
    if (HostClasses::isUnreadableSocial($url, $skipHosts)) { $sources[] = $x + ['text' => (string) ($x['excerpt'] ?? ''), 'read' => false, 'fetch' => 'skipped_social']; continue; }
    $src = $x + ['text' => (string) ($x['excerpt'] ?? ''), 'read' => false, 'fetch' => 'snippet_only'];
    if ($read < $nPages) {
        try {
            if (! $robots->allows($url)) { $src['fetch'] = 'robots_disallowed'; }
            else {
                $page = $fetcher->fetch($url, 1500000, 10);
                $t = HtmlText::extract($page['body'])['text'];
                if (mb_strlen($t) >= 300) { $src['text'] = mb_substr($t, 0, 3500); $src['read'] = true; $src['fetch'] = 'read'; }
                else { $src['fetch'] = 'no_usable_text'; }
                $read++;
                usleep(600000);
            }
        } catch (Throwable $e) { $src['fetch'] = 'failed:' . FetchFailure::category($e); $read++; }
    }
    $sources[] = $src;
}
$sources = array_values($sources);
$fetchLog = array_count_values(array_column($sources, 'fetch'));
echo 'sources: ' . count($sources) . ', pages read: ' . count(array_filter($sources, fn ($s) => $s['read'])) . '; ' . json_encode($fetchLog) . "\n";

// ── 4. ask the AI, from ONLY these sources ───────────────────────────────────
$offers = implode('; ', array_map(fn ($k, $v) => "$k $v", array_keys((array) ($icp['offers'] ?? [])), (array) ($icp['offers'] ?? [])));
$model = (string) ($icp['customer_model'] ?? 'b2b');
$who = match ($model) {
    'b2g' => 'GOVERNMENT BUYERS: a specific department, mission, board, authority or agency that has a live tender, RFP, EOI, programme launch, circular or budgeted plan the seller could answer.',
    'b2c' => 'PARTNERS: organisations that could resell, implement or embed the seller\'s product for their own clients.',
    default => 'BUYERS: organisations with a reason to buy the seller\'s product now.',
};
$pains = array_slice(array_values(array_filter(array_map(fn ($t) => (string) ($t['pain'] ?? ''), (array) ($cfg['triggers'] ?? [])))), 0, 18);
$dq = array_map(fn ($d) => (string) ($d['label'] ?? ''), (array) ($cfg['disqualifiers'] ?? []));
$list = [];
foreach ($sources as $i => $s) {
    $list[] = '[' . $i . '] ' . ($s['title'] ?? '') . ' | ' . $s['url'] . ' | published: ' . ($s['published_at'] ?? 'unknown') . ' | ' . ($s['read'] ? 'PAGE TEXT' : 'SNIPPET ONLY') . "\n" . mb_substr(trim(preg_replace('/\s+/', ' ', $s['text'])), 0, 1500);
}
$system = 'You find real, current business opportunities for a seller, using ONLY the numbered sources given. Never invent a company, fact, date or quote. '
    . 'Every opportunity must be about ONE specific, named organisation (never a category such as "system integrators" or "schools"), cite one source number and include a QUOTE copied word for word from that source (at least 8 words). '
    . 'Skip opinion posts, general explainers, research papers and anything with no buyer. A source marked SNIPPET ONLY can support an opportunity only if the snippet itself names the organisation and the event. '
    . 'Returning fewer than requested, or none, is correct when the sources do not support more. Reply with JSON only.';
$user = 'SELLER: ' . ($cfg['label'] ?? ($icp['product'] ?? '')) . " offers: $offers.\n"
    . 'What the seller solves: ' . mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($cfg['pitch'] ?? ''))), 0, 500) . "\n"
    . "$who " . mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($cfg['industry_label'] ?? ''))), 0, 500) . "\n"
    . 'Buyer types: ' . implode(', ', array_slice(array_values(array_filter((array) $cfg['buyer_types'], fn ($b) => ! str_starts_with((string) $b, 'http'))), 0, 14)) . "\n"
    . "Triggers that matter:\n- " . implode("\n- ", $pains) . "\n"
    . 'Do NOT propose: ' . implode('; ', array_filter($dq)) . "\n\n"
    . 'Find up to ' . $nSignals . " opportunities. JSON: {\"signals\":[{\"source\":<number>,\"organisation\":\"\",\"kind\":\"government_body|partner|enterprise\",\"trigger\":\"short name of the trigger\",\"what_happened\":\"1-2 sentences, facts from the source only\",\"why_it_matters\":\"1-2 sentences linking it to the seller\",\"quote\":\"verbatim from the source\",\"event_date\":\"YYYY-MM-DD or null\",\"stated_or_inferred\":\"stated|inferred\"}],\"note\":\"\"}\n\nSOURCES:\n" . implode("\n\n", $list);

echo "asking the AI (" . strlen($user) . " characters of prompt)…\n";
$ai = null; $aiError = null;
try {
    $ai = app(StructuredAi::class)->json(TENANT, [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 3000);
} catch (Throwable $e) { $aiError = class_basename($e) . ': ' . substr($e->getMessage(), 0, 160); }

// ── 5. check every proposed opportunity ──────────────────────────────────────
$norm = fn ($s) => preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s));
$out = [];
foreach ((array) ($ai['data']['signals'] ?? []) as $p) {
    $i = (int) ($p['source'] ?? -1);
    $s = $sources[$i] ?? null;
    if ($s === null) { $out[] = ['organisation' => $p['organisation'] ?? '', 'rejected' => 'cites a source that does not exist']; continue; }
    $quote = trim((string) ($p['quote'] ?? ''));
    $verified = $quote !== '' && str_word_count($quote) >= 6 && str_contains($norm($s['text']), trim($norm($quote)));
    $domains = [];
    foreach ($sources as $j => $o) { if ($j !== $i && stripos((string) $o['text'] . ' ' . ($o['title'] ?? ''), (string) $p['organisation']) !== false) { $domains[SourceIndependence::registrable(SourceIndependence::host($o['url']))] = true; } }
    $m = EvidenceMaturity::assess([
        'fetch_level' => $s['read'] ? 'page_text' : 'search_snippet', 'fetch_status' => $s['read'] ? 'read' : '', 'quote_verified' => $verified,
        'primary' => false, 'corroborating_domains' => count($domains), 'inference_reasoning' => (string) ($p['stated_or_inferred'] ?? '') === 'inferred' ? (string) ($p['why_it_matters'] ?? '') : '',
    ]);
    $out[] = ['organisation' => $p['organisation'] ?? '', 'kind' => $p['kind'] ?? '', 'trigger' => $p['trigger'] ?? '', 'what_happened' => $p['what_happened'] ?? '', 'why_it_matters' => $p['why_it_matters'] ?? '',
        'event_date' => $p['event_date'] ?? null, 'stated_or_inferred' => $p['stated_or_inferred'] ?? '', 'source_url' => $s['url'], 'source_host' => parse_url($s['url'], PHP_URL_HOST), 'page_read' => $s['read'],
        'quote' => $quote, 'quote_found_on_page' => $verified, 'evidence_level' => $m['level'], 'evidence_label' => $m['label'] ?? '', 'evidence_missing' => $m['missing'] ?? []];
}
@mkdir($root . '/docs/signal-engine/probe', 0775, true);
$report = ['name' => $name, 'ran_at' => date('c'), 'icp' => ['product' => $icp['product'] ?? null, 'customer_model' => $icp['customer_model'] ?? null], 'limits' => ['searches' => $nSearch, 'pages' => $nPages, 'signals' => $nSignals],
    'searches' => $log, 'fetch_summary' => $fetchLog, 'sources' => count($sources), 'ai_error' => $aiError, 'ai_note' => $ai['data']['note'] ?? null,
    'ai_tokens' => isset($ai['completion']) ? ['in' => $ai['completion']->inputTokens ?? null, 'out' => $ai['completion']->outputTokens ?? null] : null, 'signals' => $out];
file_put_contents($root . "/docs/signal-engine/probe/$name.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "\n================ RESULT ================\n";
if ($aiError) { echo "AI error: $aiError\n"; }
foreach ($out as $n => $o) {
    echo "\n#" . ($n + 1) . ' ' . ($o['organisation'] ?? '') . '  [' . ($o['kind'] ?? '') . ']  ' . ($o['evidence_level'] ?? '-') . ' / quote ' . (($o['quote_found_on_page'] ?? false) ? 'FOUND on page' : 'NOT found') . "\n";
    echo '   trigger: ' . ($o['trigger'] ?? '') . "\n   what: " . ($o['what_happened'] ?? ($o['rejected'] ?? '')) . "\n   why: " . ($o['why_it_matters'] ?? '') . "\n   source: " . ($o['source_url'] ?? '') . ' (page ' . (($o['page_read'] ?? false) ? 'read' : 'NOT read') . ")\n";
}
echo "\nsignals proposed: " . count($out) . "; note: " . ($ai['data']['note'] ?? '') . "\nsaved: docs/signal-engine/probe/$name.json\n";
