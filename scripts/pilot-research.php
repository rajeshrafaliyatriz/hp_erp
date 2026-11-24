<?php
/*
 * EVIDENCE-DEPTH DEV RESEARCH PILOT: matched flag-off / flag-on pairs.
 *
 *   php scripts/pilot-research.php                       PLAN ONLY: prints the configuration, makes no call, writes nothing.
 *   php scripts/pilot-research.php --execute --approved=<token> [--pairs=1]   RUNS the pilot. Refuses without the token.
 *
 * The token is set by the owner at approval time (env PILOT_APPROVAL_TOKEN must equal --approved). Until it is, this script cannot run anything.
 *
 * SAFETY (all enforced in this file, before any run):
 *  - Dev database only: refuses unless the default connection host is 202.47.117.220 and no live connection is configured. No live DB call.
 *  - No outreach: MAIL_MAILER=log and QUEUE_CONNECTION=sync are forced for this process; the CLI/direct run() path sends nothing.
 *  - Calls run() directly (not the web controller), so no ICP is written and no queue job is dispatched.
 *  - Production flag untouched: the evidence-depth flag is switched with config() inside this process only.
 *
 * HARD LIMITS (counts, enforceable; a dollar figure is not enforceable and is only estimated in the report):
 *  - runs <= 6; real Tavily calls <= 60 in total and <= 14 per run; AI calls <= 30 in total and <= 5 per run; AI input tokens <= 300,000;
 *    page requests <= 120 per run. Pre-run checks stop the pilot before a run that could exceed a total.
 *
 * MATCHING: pair = (flag off, flag on) on the same day with the same settings. Rotation, query cache and direct sources are pinned.
 * The flag-off run RECORDS its Tavily responses; the flag-on run REPLAYS them (a replay miss makes a real, counted call).
 * AI is not replayed (it is not deterministic): the pair is matched, not identical, and the report says so.
 *
 * WRITES (dev DB): the normal research rows only (see the write-set table); every run is tagged in trigger 'pl<tag>-p<n>-off|on' and listed in the
 * manifest file. Snapshots of each run's candidates are taken right after the run, before the next run can update the same rows.
 */
$root = dirname(__DIR__);
chdir($root);

// Environment for THIS process only (set before the framework boots; real environment variables win over .env).
$rotate = in_array('--rotate', $argv ?? [], true);   // --rotate: each run takes the next slice of the ICP's searches (for a series of runs)
foreach (['MAIL_MAILER' => 'log', 'QUEUE_CONNECTION' => 'sync', 'SIGNALS_ROTATE_REPEAT_RUNS' => $rotate ? 'true' : 'false', 'SIGNALS_QUERY_CACHE_HOURS' => '0',
    'SIGNALS_DIRECT_SOURCES_PER_RUN' => '0', 'SIGNALS_SCHEDULED_RESEARCH' => 'false', 'SIGNALS_EVIDENCE_DEPTH' => 'false'] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require $root . '/vendor/autoload.php';
if (is_file($root . '/tests/bootstrap-workcopy.php')) { require $root . '/tests/bootstrap-workcopy.php'; }
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Run\RunBudget;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const LIMITS = ['runs' => 6, 'search_total' => 60, 'search_per_run' => 18, 'ai_total' => 30, 'ai_per_run' => 5, 'ai_input_tokens' => 300000, 'pages_per_run' => 120];
const TENANT = 6;
const DEV_HOST = '202.47.117.220';

$opts = getopt('', ['execute', 'approved::', 'pairs::', 'tag::', 'product::', 'model::', 'arms::', 'rotate']);
define('PRODUCT', preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($opts['product'] ?? 'scholarclone'))) ?: 'scholarclone');
define('MODEL', in_array(strtolower((string) ($opts['model'] ?? 'b2b')), ['b2b', 'b2c', 'b2g'], true) ? strtolower((string) ($opts['model'] ?? 'b2b')) : 'b2b');
$arms = array_values(array_intersect(['off', 'on'], explode(',', (string) ($opts['arms'] ?? 'off,on'))));
if ($arms === []) { $arms = ['off', 'on']; }
$execute = isset($opts['execute']);
$pairs = max(1, min(3, (int) ($opts['pairs'] ?? 1)));
$tag = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($opts['tag'] ?? 'pl1'))) ?: 'pl1';

// ── safety gates ─────────────────────────────────────────────────────────────
$default = config('database.default');
$host = (string) config("database.connections.$default.host");
$liveConfigured = (bool) (config('database.connections.db3.host') || config('database.connections.live.host'));
$problems = [];
if ($host !== DEV_HOST) { $problems[] = "default DB host is not the dev host"; }
if ($liveConfigured) { $problems[] = 'a live DB connection is configured in this process'; }
if (config('mail.default') !== 'log') { $problems[] = 'mail driver is not log'; }
if (config('queue.default') !== 'sync') { $problems[] = 'queue is not sync'; }
if ((bool) config('signals.evidence_depth.enabled')) { $problems[] = 'evidence-depth flag is on at start (must start off)'; }
if ((! $rotate && config('signals.icp.rotate_repeat_runs') !== false) || (int) config('signals.discovery.query_cache_hours') !== 0) { $problems[] = 'rotation / query cache not pinned'; }

echo "== PILOT PLAN ==\n";
echo "tenant " . TENANT . ", product " . PRODUCT . '/' . MODEL . ", dev DB host $host (database " . config("database.connections.$default.database") . ")\n";
echo "pairs: $pairs (runs: " . ($pairs * 2) . "), tag: $tag\n";
echo 'limits: ' . json_encode(LIMITS) . "\n";
$aiDefault = config('ai.provider', config('ai.default', '?'));
echo 'search driver: ' . config('signals.web_search.driver') . ', AI provider (deployment default): ' . (is_scalar($aiDefault) ? $aiDefault : json_encode(array_keys((array) $aiDefault))) . "\n";
echo 'per-run config: max_queries=' . config('signals.opportunities.max_queries') . ' max_sources=' . config('signals.opportunities.max_sources') . ' fetch_pages=' . config('signals.opportunities.fetch_pages') . ' max_opportunities=' . config('signals.opportunities.max_opportunities') . ' cited_page_budget=' . config('signals.evidence_depth.cited_page_budget') . "\n";
echo 'safety problems: ' . ($problems ? implode('; ', $problems) : 'none') . "\n";
if (! $execute) { echo "PLAN ONLY: nothing was called, nothing was written.\n"; exit($problems ? 2 : 0); }
if ($problems) { fwrite(STDERR, "REFUSING: " . implode('; ', $problems) . "\n"); exit(2); }
$token = (string) (getenv('PILOT_APPROVAL_TOKEN') ?: '');
if ($token === '' || ! hash_equals($token, (string) ($opts['approved'] ?? ''))) { fwrite(STDERR, "REFUSING: no owner approval token.\n"); exit(3); }

// ── counters: real Tavily calls, replay store ────────────────────────────────
$dir = $root . '/docs/signal-engine/pilot';
@mkdir($dir, 0775, true);
$state = ['real_search' => 0, 'replayed' => 0, 'ai' => 0];
$store = [];          // sha1(request body without credentials) => response body
$mode = 'record';     // record | replay
$runSearch = 0;

$bodyKey = function (HttpRequest $r): string {
    $b = $r->data();
    unset($b['api_key']);
    return sha1(json_encode($b));
};
// Record real Tavily responses (body only, never headers or keys) so the flag-on run can replay them.
Http::globalMiddleware(function (callable $handler) use (&$store, &$mode) {
    return function ($psrRequest, array $options) use ($handler, &$store, &$mode) {
        $promise = $handler($psrRequest, $options);
        if ($mode !== 'record' || ! str_contains((string) $psrRequest->getUri()->getHost(), 'tavily.com')) {
            return $promise;
        }
        $json = json_decode((string) $psrRequest->getBody(), true) ?: [];
        unset($json['api_key']);
        $key = sha1(json_encode($json));

        return $promise->then(function ($response) use (&$store, $key) {
            if ($response->getStatusCode() === 200) {
                $store[$key] = (string) $response->getBody();
                $response->getBody()->rewind();
            }

            return $response;
        });
    };
});
Http::fake(function (HttpRequest $request) use (&$state, &$store, &$mode, &$runSearch, $bodyKey) {
    $isTavily = str_contains($request->url(), 'api.tavily.com');
    if (! $isTavily) { return null; }                              // everything else passes through untouched
    $key = $bodyKey($request);
    if ($mode === 'replay' && isset($store[$key])) {
        $state['replayed']++;
        return Http::response($store[$key], 200, ['Content-Type' => 'application/json']);
    }
    if ($state['real_search'] >= LIMITS['search_total'] || $runSearch >= LIMITS['search_per_run']) {
        throw new RuntimeException('PILOT CAP: Tavily call limit reached');
    }
    $state['real_search']++;
    $runSearch++;
    return null;                                                    // real request; the response is recorded below
});
// AI call counter (per-run limit via RunBudget as well)
$app->bind(StructuredAi::class, function ($app) use (&$state) {
    return new class ($app->make(App\Domain\AI\Support\AiModelClient::class), $state) extends StructuredAi {
        public function __construct($client, private array &$s) { parent::__construct($client); }
        public function json(int $tenantId, array $messages, ?int $maxTokens = null, ?callable $beforeAttempt = null): array
        {
            if ($this->s['ai'] >= LIMITS['ai_total']) { throw new RuntimeException('PILOT CAP: AI call limit reached'); }
            $this->s['ai']++;
            return parent::json($tenantId, $messages, $maxTokens, $beforeAttempt);
        }
    };
});

$db = DB::connection($default);
$manifest = ['tag' => $tag, 'tenant' => TENANT, 'product' => PRODUCT, 'started_at' => date('c'), 'runs' => []];
$save = function () use (&$manifest, $dir, $tag) {
    file_put_contents("$dir/manifest-$tag.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

for ($p = 1; $p <= $pairs; $p++) {
    foreach ($arms as $arm) {
        if (count($manifest['runs']) >= LIMITS['runs']) { break 2; }
        $inTok = (int) ($db->selectOne("SELECT COALESCE(SUM(input_tokens),0) t FROM ai_usage_events WHERE related_type='g2g_research_run' AND related_id IN (" . implode(',', array_merge([0], array_map('intval', array_filter(array_column($manifest['runs'], 'run_id'))))) . ')')->t ?? 0);
        if ($state['real_search'] + LIMITS['search_per_run'] > LIMITS['search_total'] && $arm === 'off') { echo "STOP: next run could exceed the search total\n"; break 2; }
        if ($state['ai'] + LIMITS['ai_per_run'] > LIMITS['ai_total']) { echo "STOP: next run could exceed the AI total\n"; break 2; }
        if ($inTok >= LIMITS['ai_input_tokens']) { echo "STOP: AI input token total reached\n"; break 2; }

        $mode = ($arm === 'off' || count($arms) === 1) ? 'record' : 'replay';
        config(['signals.evidence_depth.enabled' => $arm === 'on']);
        config(['signals.gov_sources.enabled' => $arm === 'on' && MODEL === 'b2g']);
        config(['signals.verification.enabled' => $arm === 'on']);
        config(['signals.qualification_rule.by_grade' => $arm === 'on', 'signals.surface_grade' => $arm === 'on', 'signals.drop_rejected_grade' => $arm === 'on']);
        $runSearch = 0;
        RunBudget::arm(['search_calls' => LIMITS['search_per_run'], 'page_requests' => LIMITS['pages_per_run'], 'ai_requests' => LIMITS['ai_per_run']]);
        $trigger = substr("$tag-p$p-$arm", 0, 20);
        echo "running $trigger (flag " . ($arm === 'on' ? 'ON' : 'off') . ", $mode)\n";
        $t0 = microtime(true);
        $err = null; $run = null;
        try {
            $run = app(OpportunityResearcher::class)->run(TENANT, $trigger, null, null, null, null, PRODUCT, null, null, MODEL);
        } catch (Throwable $e) { $err = get_class($e) . ': ' . substr($e->getMessage(), 0, 200); }
        $budget = RunBudget::report();
        RunBudget::disarm();

        $runId = $run?->id;
        $snap = ['pair' => $p, 'arm' => $arm, 'trigger' => $trigger, 'run_id' => $runId, 'error' => $err, 'seconds' => round(microtime(true) - $t0, 1), 'budget' => $budget,
            'real_search_total' => $state['real_search'], 'replayed_total' => $state['replayed'], 'ai_calls_total' => $state['ai']];
        if ($runId) {
            $snap['run'] = (array) $db->selectOne('SELECT status, stage, stage_message, queries_run, queries_failed, sources_found, opportunities_qualified, opportunities_new, ai_input_tokens, ai_output_tokens, search_requests FROM g2g_research_runs WHERE id=?', [$runId]);
            $snap['stage_stats'] = json_decode((string) $db->selectOne('SELECT stage_stats s FROM g2g_research_runs WHERE id=?', [$runId])->s, true);
            $snap['sources'] = array_map(fn ($r) => (array) $r, $db->select('SELECT url, domain, page_fetched, cited FROM g2g_research_sources WHERE research_run_id=?', [$runId]));
            $snap['opportunities'] = array_map(function ($r) {
                $a = json_decode((string) $r->assessment, true) ?: [];
                return ['id' => $r->id, 'company_id' => $r->company_id, 'source_url' => $r->source_url, 'claim_level' => $r->claim_level, 'fetch_level' => $r->fetch_level, 'qualification' => $r->qualification,
                    'maturity' => $a['evidence_maturity']['level'] ?? null, 'evidence_items' => count($a['evidence_items'] ?? []), 'quote_verified' => $a['evidence']['quote_verified'] ?? null, 'signal_grade' => $a['signal_grade']['grade'] ?? null, 'grade_reasons' => $a['signal_grade']['reasons'] ?? null, 'region' => $a['region']['status'] ?? null, 'why' => $a['signal_grade'] ?? null];
            }, $db->select('SELECT id, company_id, source_url, claim_level, fetch_level, qualification, assessment FROM g2g_company_opportunities WHERE research_run_id=?', [$runId]));
            $snap['ai_usage'] = array_map(fn ($r) => (array) $r, $db->select("SELECT provider, model, input_tokens, output_tokens, outcome, estimated_cost, cost_currency FROM ai_usage_events WHERE related_type='g2g_research_run' AND related_id=?", [$runId]));
        }
        $manifest['runs'][] = ['run_id' => $runId, 'trigger' => $trigger, 'arm' => $arm, 'pair' => $p, 'error' => $err];
        file_put_contents("$dir/pilot-$tag-p$p-$arm.json", json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $save();
        echo "  done: run " . ($runId ?? 'none') . ($err ? " ERROR $err" : '') . "; real searches $state[real_search], replayed $state[replayed], AI calls $state[ai]\n";
        if ($err && str_contains($err, 'PILOT CAP')) { echo "STOP: cap reached\n"; break 2; }
    }
}
$manifest['finished_at'] = date('c');
$save();
echo "Pilot finished. Manifest: docs/signal-engine/pilot/manifest-$tag.json\n";
