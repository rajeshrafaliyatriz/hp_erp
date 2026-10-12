<?php
// Exercises /api/gtm with real Sanctum tokens (created then deleted). Run from D:\hp_erp:
//   php http.php <scenario>        (scenario = a sibling file name without .php)
// SAFE ON SHARED DEV DATA: every row a scenario creates is named "Verify ..." or hangs off such a row, and
// gtm_verify_cleanup() deletes ONLY those. It never deletes by "id greater than a baseline", because people
// use the same tenant in the browser while suites run.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array']); // throttle counters live in-process so a run can reset them

use App\Models\auth\tbluserModel as U;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function call($kernel, $m, $u, $tok = null, $body = [])
{
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'] + ($tok ? ['HTTP_AUTHORIZATION' => "Bearer $tok"] : []);
    $r = Illuminate\Http\Request::create('/api' . $u, $m, $m === 'GET' ? $body : [], [], [], $server, $m === 'GET' ? null : json_encode($body));
    $res = $kernel->handle($r);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

/** Delete exactly what a scenario created: rows named "Verify ..." and everything attached to them. */
function gtm_verify_cleanup(string $startedAt, ?string $acct3Stage = null): void
{
    $has = fn ($t) => Schema::hasTable($t);
    $accts = DB::table('gtm_accounts')->where('name', 'like', 'Verify %')->pluck('id');
    $contacts = DB::table('gtm_contacts')->where(fn ($w) => $w->where('full_name', 'like', 'Verify %')->orWhere('full_name', 'No Signal')->orWhereIn('account_id', $accts))->pluck('id');
    $deals = DB::table('gtm_deals')->where(fn ($w) => $w->where('name', 'like', 'Verify %')->orWhereIn('account_id', $accts))->pluck('id');
    $msgs = DB::table('gtm_outreach_messages')->where(fn ($w) => $w->whereIn('contact_id', $contacts)->orWhere('subject', 'like', 'Verify %'))->pluck('id');

    if ($has('gtm_outreach_attempts')) { DB::table('gtm_outreach_attempts')->whereIn('message_id', $msgs)->delete(); }
    DB::table('gtm_outreach_messages')->whereIn('id', $msgs)->delete();
    DB::table('gtm_activities')->where(fn ($w) => $w->whereIn('account_id', $accts)->orWhereIn('contact_id', $contacts)->orWhereIn('deal_id', $deals))->delete();
    // system rows a test caused on a REAL account (e.g. a won deal flips its stage) - narrow patterns, test window only
    DB::table('gtm_activities')->where('type', 'system')->where('created_at', '>=', $startedAt)->where(fn ($w) => $w->where('subject', 'like', 'Stage changed:%')->orWhere('subject', 'like', 'Deal %'))->delete();
    DB::table('gtm_deal_stage_history')->whereIn('deal_id', $deals)->delete();
    DB::table('gtm_deals')->whereIn('id', $deals)->delete();
    if ($has('gtm_campaigns')) {
        $camps = DB::table('gtm_campaigns')->where('name', 'like', 'Verify %')->pluck('id');
        DB::table('gtm_campaign_members')->where(fn ($w) => $w->whereIn('campaign_id', $camps)->orWhereIn('account_id', $accts)->orWhereIn('contact_id', $contacts))->delete();
        DB::table('gtm_campaigns')->whereIn('id', $camps)->delete();
        DB::table('gtm_segments')->where('name', 'like', 'Verify %')->delete();
    }
    if ($has('gtm_renewals')) { DB::table('gtm_renewals')->where(fn ($w) => $w->where('name', 'like', 'Verify %')->orWhereIn('account_id', $accts))->delete(); DB::table('gtm_health_snapshots')->whereIn('account_id', $accts)->delete(); }
    if ($has('gtm_workflows')) {
        $wf = DB::table('gtm_workflows')->where('name', 'like', 'Verify %')->pluck('id');
        $runs = DB::table('gtm_workflow_runs')->whereIn('workflow_id', $wf)->pluck('id');
        DB::table('gtm_workflow_run_steps')->whereIn('run_id', $runs)->delete();
        DB::table('gtm_workflow_runs')->whereIn('id', $runs)->delete();
        DB::table('gtm_workflows')->whereIn('id', $wf)->delete();
    }
    if ($has('gtm_suppressions')) { DB::table('gtm_suppressions')->where('value', 'like', 'verify%')->delete(); DB::table('gtm_inbound_events')->where('provider', 'like', 'verify%')->delete(); }
    // AI analyses/runs a test caused: by subject, plus the test window for the agent kinds (icp_fit is real scoring and stays)
    DB::table('gtm_analyses')->where(fn ($w) => $w->where(fn ($x) => $x->where('subject_type', 'deal')->whereIn('subject_id', $deals))->orWhere(fn ($x) => $x->where('subject_type', 'contact')->whereIn('subject_id', $contacts))->orWhere(fn ($x) => $x->where('subject_type', 'account')->whereIn('subject_id', $accts)))->delete();
    DB::table('gtm_analyses')->where('created_at', '>=', $startedAt)->whereIn('kind', ['outreach_draft', 'methodology_score', 'discovery_analysis', 'deal_coach', 'pipeline_review', 'customer_review', 'signal_prioritisation', 'account_research'])->delete();
    $agentIds = DB::table('agentic_agents')->where('sub_module', 'gtm')->pluck('id');
    DB::table('agentic_agent_runs')->whereIn('agent_id', $agentIds)->where('started_at', '>=', $startedAt)->delete();
    DB::table('gtm_contacts')->whereIn('id', $contacts)->delete();
    DB::table('gtm_accounts')->whereIn('id', $accts)->delete();
    if ($acct3Stage !== null) { DB::table('gtm_accounts')->where('id', 3)->update(['stage' => $acct3Stage]); }
}

$scenario = $argv[1] ?? 'score';
$startedAt = now()->subSeconds(2)->format('Y-m-d H:i:s');
$admin = U::find(28);
$employee = U::where('sub_institute_id', 6)->where('user_profile_id', 17)->first();
$other = U::where('sub_institute_id', '!=', 6)->whereNotNull('sub_institute_id')->where('user_profile_id', 16)->first();
$mk = fn ($u) => $u->createToken('gtm-verify')->plainTextToken;

try {
    $A = $mk($admin); $E = $mk($employee); $O = $other ? $mk($other) : null;
    require __DIR__ . '/' . $scenario . '.php';
} finally {
    DB::table('personal_access_tokens')->where('name', 'gtm-verify')->delete();
}
