<?php
/*
 * EVIDENCE-DEPTH PILOT (development database only; read-only; no writes anywhere except the result files under docs/signal-engine/pilot).
 *
 *   php scripts/evidence-pilot.php [--limit=40] [--sleep=1500]
 *
 * For each ICP-fit candidate (tenant 6, dev) it makes ONE real request to the page the signal cites, honouring robots.txt and the same SSRF
 * protections as the research pipeline, then replays both fetch strategies on that single response:
 *   A  current behaviour: HTML only (a PDF is refused as "non_html"), first-pass cap 600 KB
 *   B  evidence-depth flag: PDFs are read as well
 * No search provider, no AI model and no model tokens are used, so provider cost is zero; wall time and bytes are measured.
 * Platforms whose terms forbid automated reading (linkedin, youtube) and hosts the pipeline already skips (facebook, instagram, x, twitter, reddit, quora)
 * are NOT requested; they are reported as exceptions.
 */
$root = dirname(__DIR__);
chdir($root);
require $root . '/vendor/autoload.php';
if (is_file($root . '/tests/bootstrap-workcopy.php')) { require $root . '/tests/bootstrap-workcopy.php'; }
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Signals\Ingestion\DocumentExtractor;
use App\Domain\Signals\Market\EvidenceGrader;
use App\Domain\Signals\Market\EvidenceMaturity;
use App\Domain\Signals\Market\SourceIndependence;
use App\Domain\Signals\Support\FetchFailure;
use App\Domain\Signals\Support\HostClasses;
use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;

$opts = getopt('', ['limit::', 'sleep::']);
$limit = (int) ($opts['limit'] ?? 40);
$sleepMs = (int) ($opts['sleep'] ?? 1500);

// ── safety: dev only, read-only ───────────────────────────────────────────────
$default = config('database.default');
$host = (string) config("database.connections.$default.host");
if ($host !== '202.47.117.220' || config('database.connections.db3.host') || config('database.connections.live.host')) {
    fwrite(STDERR, "REFUSING: this process must reach the development database only.\n");
    exit(2);
}
$db = DB::connection($default);
$db->statement('SET SESSION TRANSACTION READ ONLY');
echo "dev host $host, database " . config("database.connections.$default.database") . ", tenant 6, tx_read_only=" . $db->selectOne('SELECT @@tx_read_only r')->r . "\n";

// ── candidates: ICP-fit = product fit set and (fit total >= min_fit when known), with a source URL ──
$rows = $db->select("SELECT o.id, o.source_url, o.source_title, o.claim_level, o.fetch_level, o.qualification, o.business_fit, o.assessment, o.observed_event,
        c.name AS company_name, c.website AS company_website
    FROM g2g_company_opportunities o LEFT JOIN g2g_companies c ON c.id = o.company_id
    WHERE o.is_sample = 0 AND o.sub_institute_id = 6 AND o.business_fit IS NOT NULL AND o.source_url IS NOT NULL AND o.source_url <> '' ORDER BY o.id");
$minFit = (int) config('signals.qualification.min_fit', 25);
$cand = [];
foreach ($rows as $r) {
    $a = json_decode((string) $r->assessment, true) ?: [];
    $fit = $a['fit']['total'] ?? null;
    if ($fit !== null && $fit < $minFit) {
        continue;
    }
    $cand[] = ['row' => $r, 'a' => $a];
}
$cand = array_slice($cand, 0, $limit);
echo count($cand) . " ICP-fit candidates with a cited URL\n";

$fetcher = new SafeUrlFetcher();
$robots = new RobotsPolicy($fetcher);
$grader = new EvidenceGrader();
$pdfOk = class_exists(\Smalot\PdfParser\Parser::class);
$skipHosts = array_values(array_map('strval', (array) config('signals.fetch.skip_hosts', HostClasses::UNREADABLE_SOCIAL)));
$termsHosts = ['linkedin.com', 'youtube.com', 'youtu.be'];   // platform terms forbid automated reading: not requested in the pilot
$norm = fn (string $s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)) ?? '');
$labelFor = fn (string $claim, string $trigger = '') => $claim === 'confirmed' ? 'New Opportunity / Relevant Requirement Found' : ($claim === 'inference' ? 'Needs Verification' : 'Monitoring');

$results = [];
$totalBytes = 0;
foreach ($cand as $i => $c) {
    $r = $c['row']; $a = $c['a'];
    $url = (string) $r->source_url;
    $type = SourceIndependence::sourceType($url);
    $reg = SourceIndependence::registrable(SourceIndependence::host($url));
    $rec = ['id' => $r->id, 'type' => $type, 'host' => SourceIndependence::host($url), 'stored_level' => EvidenceMaturity::fromStored($r->claim_level, $r->fetch_level),
        'stored_claim' => $r->claim_level, 'stored_label' => $r->qualification];
    $quote = $a['provenance']['public_fact']['quote'] ?? null;
    $rec['has_quote'] = (bool) $quote;
    $t0 = microtime(true);

    if (HostClasses::isUnreadableSocial($url, $skipHosts)) {
        $rec += ['A' => 'skipped_social', 'B' => 'skipped_social', 'exception' => 'pipeline skips this host (login wall)'];
    } elseif (in_array($reg, $termsHosts, true)) {
        $rec += ['A' => 'not_requested', 'B' => 'not_requested', 'exception' => 'platform terms forbid automated reading; not requested in the pilot'];
    } else {
        try {
            if (! $robots->allows($url)) {
                $rec += ['A' => 'robots_disallowed', 'B' => 'robots_disallowed', 'exception' => 'robots.txt disallows our bot'];
            } else {
                $page = $fetcher->fetch($url, 3000000, 12, true, ['application/pdf']);
                $isPdf = ($page['content_type'] ?? '') === 'application/pdf';
                $bytes = strlen($page['body']);
                $totalBytes += $bytes;
                $text = '';
                if ($isPdf) {
                    if ($pdfOk) {
                        try { $text = trim(implode("\n", array_column((new DocumentExtractor())->extract($page['body'], 'pdf')['segments'], 'text'))); } catch (\Throwable) { $text = ''; }
                    }
                } else {
                    $text = HtmlText::extract($page['body'])['text'];
                }
                $stored = mb_strlen((string) ($a['provenance']['public_fact']['quote'] ?? '')) + mb_strlen((string) $r->source_title);
                $readB = mb_strlen($text) >= 200;
                $rec['bytes'] = $bytes; $rec['text_chars'] = mb_strlen($text); $rec['content_type'] = $page['content_type'] ?? null;
                $rec['A'] = $isPdf ? 'failed:non_html' : ($readB ? 'read' : 'no_usable_text');
                $rec['B'] = $readB ? 'read' : ($isPdf ? ($pdfOk ? 'pdf_no_text' : 'pdf_reader_unavailable') : 'no_usable_text');
                $rec['is_pdf'] = $isPdf;
                $pageNorm = $norm(mb_substr($text, 0, 200000));
                $name = (string) ($r->company_name ?? '');
                $rec['name_on_page'] = $name !== '' && str_contains($pageNorm, $norm($name));
                $rec['quote_verified'] = $quote ? str_contains($pageNorm, $norm((string) $quote)) : null;
                $rec['_text_for_passage'] = $readB ? mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 200) : null;
            }
        } catch (\Throwable $e) {
            $k = FetchFailure::category($e);
            $rec += ['A' => 'failed:' . $k, 'B' => 'failed:' . $k, 'exception' => 'fetch failed: ' . $k];
        }
    }
    $rec['ms'] = (int) round((microtime(true) - $t0) * 1000);

    // Evidence maturity after strategy B. Quote verification is only possible where the signal stored a verbatim quote (a handful); everyone
    // else is capped at E1 here, which is honest: a page read is E1, and nothing is promoted on a name match alone.
    $readB = ($rec['B'] ?? '') === 'read';
    $primary = $grader->isPrimary($url, ['name' => (string) ($r->company_name ?? ''), 'website' => $r->company_website]);
    $m = EvidenceMaturity::assess([
        'fetch_level' => $readB ? 'page_text' : 'search_snippet', 'fetch_status' => $readB ? 'ok' : preg_replace('/^failed:/', '', (string) ($rec['B'] ?? '')),
        'quote_verified' => $rec['quote_verified'] ?? false, 'primary' => $primary,
        'corroborating_domains' => (int) ($a['evidence']['sources_naming_school'] ?? 1), 'inference_reasoning' => $a['evidence']['inference_reasoning'] ?? null,
    ]);
    $g = $grader->grade(['quote_verified' => $rec['quote_verified'] ?? false, 'corroborating_domains' => (int) ($a['evidence']['sources_naming_school'] ?? 1), 'inference_reasoning' => $a['evidence']['inference_reasoning'] ?? null], $readB ? 'page_text' : 'search_snippet', $url, ['name' => (string) ($r->company_name ?? ''), 'website' => $r->company_website]);
    $rec['primary'] = $primary;
    $rec['level_after'] = $m['level'];
    $rec['claim_after'] = $g['claim_level'];
    $rec['label_after'] = $labelFor($g['claim_level']);
    $results[] = $rec;
    printf("[%2d/%d] %-10s %-24s A=%-22s B=%-22s %s -> %s\n", $i + 1, count($cand), $type, mb_substr($rec['host'], 0, 24), $rec['A'], $rec['B'], $rec['stored_level'], $rec['level_after']);
    usleep($sleepMs * 1000);
}

// ── aggregate ────────────────────────────────────────────────────────────────
$pct = fn ($a, $b) => $b ? round(100 * $a / $b, 1) : 0.0;
$n = count($results);
$eligible = array_filter($results, fn ($x) => ! in_array($x['A'], ['skipped_social', 'not_requested'], true));
$readA = count(array_filter($results, fn ($x) => $x['A'] === 'read'));
$readB = count(array_filter($results, fn ($x) => $x['B'] === 'read'));
$byType = [];
foreach ($results as $x) {
    $t = $x['type'];
    $byType[$t]['n'] = ($byType[$t]['n'] ?? 0) + 1;
    $byType[$t]['read_A'] = ($byType[$t]['read_A'] ?? 0) + ($x['A'] === 'read' ? 1 : 0);
    $byType[$t]['read_B'] = ($byType[$t]['read_B'] ?? 0) + ($x['B'] === 'read' ? 1 : 0);
    $byType[$t]['outcomes'][$x['B']] = ($byType[$t]['outcomes'][$x['B']] ?? 0) + 1;
}
$dist = fn (string $k) => array_count_values(array_column($results, $k));
$ms = array_column($results, 'ms'); sort($ms);
$summary = [
    'generated_at' => gmdate('c'), 'database' => "dev $host / " . config("database.connections.$default.database"), 'tenant' => 6, 'candidates' => $n,
    'pdf_reader_available' => $pdfOk, 'requests_with_page_fetch' => count(array_filter($results, fn ($x) => isset($x['bytes']))), 'total_bytes' => $totalBytes,
    'eligible' => count($eligible), 'read_rate_eligible_A' => $pct($readA, count($eligible)), 'read_rate_eligible_B' => $pct($readB, count($eligible)),
    'read_rate_all_A' => $pct($readA, $n), 'read_rate_all_B' => $pct($readB, $n),
    'by_type' => $byType, 'outcomes_A' => $dist('A'), 'outcomes_B' => $dist('B'),
    'level_stored' => $dist('stored_level'), 'level_after' => $dist('level_after'), 'claim_after' => $dist('claim_after'),
    'quote_verifiable' => count(array_filter($results, fn ($x) => $x['has_quote'])), 'quote_verified' => count(array_filter($results, fn ($x) => ($x['quote_verified'] ?? null) === true)),
    'name_on_page_of_read' => count(array_filter($results, fn ($x) => ($x['B'] === 'read') && ! empty($x['name_on_page']))),
    'ms_median' => $ms[intdiv(count($ms), 2)] ?? null, 'ms_p90' => $ms[(int) floor(count($ms) * 0.9)] ?? null, 'ms_total' => array_sum($ms),
    'provider_cost' => ['search_calls' => 0, 'ai_calls' => 0, 'tokens' => 0],
    'exceptions' => array_values(array_map(fn ($x) => ['id' => $x['id'], 'host' => $x['host'], 'type' => $x['type'], 'why' => $x['exception']], array_filter($results, fn ($x) => isset($x['exception'])))),
];
foreach ($results as &$x) { unset($x['_text_for_passage']); }
unset($x);
$dir = $root . '/docs/signal-engine/pilot';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/pilot-results.json', json_encode(['summary' => $summary, 'candidates' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\n" . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
