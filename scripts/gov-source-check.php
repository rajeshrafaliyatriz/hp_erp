<?php
/*
 * GOVERNMENT / TENDER SOURCE READABILITY CHECK. No database, no search provider, no AI, nothing written except the result file.
 *
 *   php scripts/gov-source-check.php
 *
 * For each candidate public source it asks the pipeline's own rules: does robots.txt allow our bot, and does one normal page request return readable
 * text? One robots check and at most one page request per source, 1.2 s apart, SSRF protections and size limits of the production fetcher.
 * Output: docs/signal-engine/gov-source-readability.json
 */
$root = dirname(__DIR__);
chdir($root);
require $root . '/vendor/autoload.php';
if (is_file($root . '/tests/bootstrap-workcopy.php')) { require $root . '/tests/bootstrap-workcopy.php'; }
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Signals\Support\FetchFailure;
use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;

$candidates = [
    // [group, name, url]
    ['tender_portal', 'Central Public Procurement Portal (CPPP)', 'https://eprocure.gov.in/eprocure/app'],
    ['tender_portal', 'Central e-tenders', 'https://etenders.gov.in/eprocure/app'],
    ['tender_portal', 'GeM home', 'https://gem.gov.in/'],
    ['tender_portal', 'GeM bids', 'https://bidplus.gem.gov.in/all-bids'],
    ['tender_portal', 'Gujarat nProcure', 'https://www.nprocure.com/'],
    ['tender_portal', 'Maharashtra tenders', 'https://mahatenders.gov.in/nicgep/app'],
    ['tender_portal', 'Tamil Nadu tenders', 'https://tntenders.gov.in/nicgep/app'],
    ['tender_portal', 'Kerala e-tenders', 'https://etenders.kerala.gov.in/nicgep/app'],
    ['tender_portal', 'Indian Railways e-procurement', 'https://www.ireps.gov.in/'],
    ['tender_portal', 'Gujarat Informatics Ltd', 'https://gil.gujarat.gov.in/'],
    ['ministry_news', 'PIB press releases', 'https://pib.gov.in/allRel.aspx'],
    ['ministry_news', 'MeitY', 'https://www.meity.gov.in/'],
    ['ministry_news', 'NITI Aayog', 'https://www.niti.gov.in/'],
    ['ministry_news', 'Digital India', 'https://www.digitalindia.gov.in/'],
    ['ministry_news', 'Skill India', 'https://www.skillindia.gov.in/'],
    ['ministry_news', 'Ministry of Skill Development (MSDE)', 'https://www.msde.gov.in/'],
    ['ministry_news', 'DoPT (personnel and HR policy)', 'https://dopt.gov.in/'],
    ['ministry_news', 'Gujarat Government portal', 'https://gujaratindia.gov.in/'],
    ['agency', 'C-DAC', 'https://www.cdac.in/'],
    ['agency', 'NIELIT', 'https://www.nielit.gov.in/'],
    ['agency', 'NIC', 'https://www.nic.in/'],
    ['agency', 'MyGov', 'https://www.mygov.in/'],
    ['aggregator', 'TenderDetail', 'https://www.tenderdetail.com/'],
    ['aggregator', 'Tenders On Time', 'https://www.tendersontime.com/'],
    ['aggregator', 'BidAssist', 'https://bidassist.com/'],
    ['aggregator', 'Tender247', 'https://www.tender247.com/'],
];

$fetcher = new SafeUrlFetcher();
$robots = new RobotsPolicy($fetcher);
$out = [];
foreach ($candidates as [$group, $name, $url]) {
    $rec = ['group' => $group, 'name' => $name, 'url' => $url, 'host' => parse_url($url, PHP_URL_HOST)];
    $t0 = microtime(true);
    try {
        if (! $robots->allows($url)) {
            $rec['outcome'] = 'robots_disallowed';
        } else {
            $page = $fetcher->fetch($url, 1500000, 12, true);
            $text = HtmlText::extract($page['body'])['text'];
            $len = mb_strlen($text);
            $rec['text_chars'] = $len;
            $rec['bytes'] = strlen($page['body']);
            $rec['mentions_tender'] = preg_match_all('/tender|bid|rfp|eoi|procure/i', $text);
            $rec['outcome'] = $len >= 500 ? 'readable' : 'little_text_probably_needs_javascript_or_login';
        }
    } catch (Throwable $e) {
        $rec['outcome'] = 'failed:' . FetchFailure::category($e);
    }
    $rec['seconds'] = round(microtime(true) - $t0, 1);
    $out[] = $rec;
    echo str_pad($rec['outcome'], 48) . ' ' . $name . "\n";
    usleep(1200000);
}
$counts = [];
foreach ($out as $r) { $counts[$r['outcome']] = ($counts[$r['outcome']] ?? 0) + 1; }
file_put_contents($root . '/docs/signal-engine/gov-source-readability.json', json_encode(['checked_at' => date('c'), 'counts' => $counts, 'sources' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\n" . json_encode($counts) . "\n";
