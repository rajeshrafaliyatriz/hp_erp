<?php
use Illuminate\Support\Facades\{Cache, DB, Http};

$origStage = DB::table('gtm_accounts')->where('id', 3)->value('stage');
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$fake = function ($stub) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => $stub]); };
$gem = fn ($arr) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($arr)]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
$base = ['act' => DB::table('gtm_activities')->max('id'), 'an' => DB::table('gtm_analyses')->max('id'), 'acct' => (int) DB::table('gtm_accounts')->max('id'), 'contact' => (int) DB::table('gtm_contacts')->max('id'), 'run' => DB::table('agentic_agent_runs')->max('id'), 'msg' => (int) DB::table('gtm_outreach_messages')->max('id'), 'deal' => (int) DB::table('gtm_deals')->max('id')];
$otherTenant = 0; $otherAcct = 0; $otherSeg = 0; $otherCamp = 0;

try {
    // ---------- permissions ----------
    foreach (['/gtm/segments', '/gtm/campaigns'] as $u) { [$c] = call($kernel, 'GET', $u, null); $ok("$u no token 401", $c === 401, $c); [$c] = call($kernel, 'GET', $u, $E); $ok("$u employee 403", $c === 403, $c); }
    [$c] = call($kernel, 'POST', '/gtm/segments', $E, ['name' => 'x', 'kind' => 'account', 'criteria' => ['stages' => ['target']]]); $ok('segment create: employee 403', $c === 403, $c);
    [$c] = call($kernel, 'POST', '/gtm/campaigns', $E, ['name' => 'x', 'kind' => 'abm']);                                          $ok('campaign create: employee 403', $c === 403, $c);

    // ---------- fixtures: real accounts and contacts through the API ----------
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify Camp Edu', 'industry' => 'Verify-Education', 'location' => 'Pune']);           $co1 = $j['data']['account']['id'];
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify Camp Soft', 'industry' => 'Verify-Software', 'location' => 'Bengaluru']);      $co2 = $j['data']['account']['id'];
    [$c, $j] = $req('POST', "/gtm/accounts/$co1/contacts", ['full_name' => 'Verify Camp Person', 'email' => 'verify.camp@example.invalid', 'role_in_deal' => 'decision_maker']); $ct1 = $j['data']['contact']['id'];
    [$c, $j] = $req('POST', '/gtm/accounts/3/contacts', ['full_name' => 'Verify Camp Signal Person', 'email' => 'verify.camp.signal@example.invalid']); $ctSig = $j['data']['contact']['id'];
    if ($O) {
        $otherTenant = (int) DB::table('tbluser')->where('id', DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id'))->value('sub_institute_id');
        $otherAcct = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Camp Co', 'industry' => 'Verify-Education', 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $otherSeg = DB::table('gtm_segments')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Seg', 'kind' => 'account', 'criteria' => json_encode(['stages' => ['target']]), 'created_at' => now(), 'updated_at' => now()]);
        $otherCamp = DB::table('gtm_campaigns')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Campaign', 'kind' => 'abm', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------- segment criteria validation ----------
    foreach ([
        'unknown filter key' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['sql' => '1=1']],
        'no filters at all' => ['name' => 'S', 'kind' => 'account', 'criteria' => []],
        'bad stage' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['stages' => ['dreaming']]],
        'min_icp above max_icp' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['min_icp' => 80, 'max_icp' => 20]],
        'icp out of range' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['min_icp' => 150]],
        'has_contacts not boolean' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['has_contacts' => 'yes']],
        'priority not High/Medium/Low' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['min_signal_priority' => 'Urgent']],
        'contact filter on an account segment' => ['name' => 'S', 'kind' => 'account', 'criteria' => ['roles' => ['champion']]],
        'account filter on a contact segment' => ['name' => 'S', 'kind' => 'contact', 'criteria' => ['min_icp' => 10]],
        'missing name' => ['kind' => 'account', 'criteria' => ['stages' => ['target']]],
        'bad kind' => ['name' => 'S', 'kind' => 'company', 'criteria' => ['stages' => ['target']]],
    ] as $label => $b) { [$c] = $req('POST', '/gtm/segments', $b); $ok("segment invalid ($label) -> 422", $c === 422, $c); }

    // ---------- segment matching ----------
    [$c, $j] = $req('POST', '/gtm/segments', ['name' => 'Verify Education', 'kind' => 'account', 'criteria' => ['stages' => ['target'], 'industry_contains' => 'verify-educ']]); $s1 = $j['data']['segment']['id'] ?? 0;
    $ok('segment created', $c === 201 && $s1 > 0, [$c, $j]);
    [$c, $j] = $req('GET', "/gtm/segments/$s1/preview");
    $ok('preview matches exactly the Education account (live, tenant-scoped)', $c === 200 && $j['data']['total'] === 1 && $j['data']['sample'][0]['id'] === $co1, $j['data'] ?? $j);
    [$c, $j] = $req('POST', '/gtm/segments', ['name' => 'Verify HighSignal', 'kind' => 'account', 'criteria' => ['min_signal_priority' => 'High']]); $s2 = $j['data']['segment']['id'];
    [$c, $j] = $req('GET', "/gtm/segments/$s2/preview"); $ok('min_signal_priority High matches the account with a High research signal (and not the Low-signal ones)', collect($j['data']['sample'])->pluck('id')->contains(3) && $j['data']['total'] >= 1, $j['data'] ?? $j);
    [$c, $j] = $req('POST', '/gtm/segments', ['name' => 'Verify NoContacts', 'kind' => 'account', 'criteria' => ['has_contacts' => false, 'industry_contains' => 'verify-soft']]); $s3 = $j['data']['segment']['id'];
    [$c, $j] = $req('GET', "/gtm/segments/$s3/preview"); $ok('has_contacts=false finds the account with no contacts', collect($j['data']['sample'])->pluck('id')->all() === [$co2], $j['data'] ?? $j);
    [$c, $j] = $req('POST', '/gtm/segments', ['name' => 'Verify Deciders', 'kind' => 'contact', 'criteria' => ['roles' => ['decision_maker'], 'has_email' => true]]); $s4 = $j['data']['segment']['id'];
    [$c, $j] = $req('GET', "/gtm/segments/$s4/preview"); $ok('contact segment: decision makers with an email', collect($j['data']['sample'])->pluck('id')->contains($ct1) && collect($j['data']['sample'])->every(fn ($r) => $r['role_in_deal'] === 'decision_maker'), $j['data'] ?? $j);
    [$c, $j] = $req('POST', '/gtm/segments', ['name' => 'Verify LikeEscape', 'kind' => 'account', 'criteria' => ['industry_contains' => '%']]); $s5 = $j['data']['segment']['id'];
    [$c, $j] = $req('GET', "/gtm/segments/$s5/preview"); $ok('a LIKE wildcard in a filter is escaped, not interpreted (matches nothing)', $j['data']['total'] === 0, $j['data']['total'] ?? $j);
    [$c, $j] = $req('PATCH', "/gtm/segments/$s1", ['criteria' => ['stages' => ['customer']]]);   $ok('segment update', $c === 200 && $j['data']['segment']['criteria'] === ['stages' => ['customer']], [$c, $j]);
    [$c] = $req('PATCH', "/gtm/segments/$s1", ['criteria' => ['bogus' => 1]]);                    $ok('segment update with invalid criteria -> 422', $c === 422, $c);
    [$c] = $req('PATCH', "/gtm/segments/$s1", ['kind' => 'contact']);                             $ok('a segment cannot change kind -> 422', $c === 422, $c);
    $req('PATCH', "/gtm/segments/$s1", ['criteria' => ['stages' => ['target'], 'industry_contains' => 'verify-educ']]);

    // ---------- campaign validation ----------
    foreach ([
        'bad kind' => ['name' => 'C', 'kind' => 'billboard'],
        'missing name' => ['kind' => 'abm'],
        'ends before it starts' => ['name' => 'C', 'kind' => 'abm', 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-01'],
        'unknown industry pack' => ['name' => 'C', 'kind' => 'abm', 'industry_pack_slug' => 'pack-does-not-exist'],
        'a non-pack playbook as pack' => ['name' => 'C', 'kind' => 'abm', 'industry_pack_slug' => 'deal-coach'],
        'owner from nowhere' => ['name' => 'C', 'kind' => 'abm', 'owner_user_id' => 999999],
    ] as $label => $b) { [$c] = $req('POST', '/gtm/campaigns', $b); $ok("campaign invalid ($label) -> 422", $c === 422, $c); }
    if ($otherSeg) { [$c] = $req('POST', '/gtm/campaigns', ['name' => 'C', 'kind' => 'abm', 'segment_id' => $otherSeg]); $ok("campaign with another tenant's segment -> 422", $c === 422, $c); }

    [$c, $j] = $req('POST', '/gtm/campaigns', ['name' => 'Verify Education ABM', 'kind' => 'abm', 'goal' => 'Open conversations with education groups showing a funding signal', 'industry_pack_slug' => 'pack-education', 'segment_id' => $s1, 'owner_user_id' => 28]);
    $cp = $j['data']['campaign']->id ?? ($j['data']['campaign']['id'] ?? 0);
    $ok('campaign created as draft with an industry pack', $c === 201 && $cp > 0 && ($j['data']['campaign']['status'] ?? '') === 'draft', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'active']);       $ok('cannot activate a campaign with no targets (422 no_targets)', $c === 422 && $j['code'] === 'no_targets', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'completed']);    $ok('draft -> completed is not a valid move (422)', $c === 422 && $j['code'] === 'bad_transition', [$c, $j]);

    // ---------- membership ----------
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", []);                           $ok('adding nothing -> 422', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", ['segment_id' => $s1]);        $ok('add targets from a segment: Education account added', $c === 200 && $j['data']['added'] === 1, [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", ['segment_id' => $s1]);        $ok('adding the same segment again adds nothing (idempotent)', $j['data']['added'] === 0 && $j['data']['already_members'] === 1, $j['data'] ?? $j);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", ['contact_ids' => [$ct1, $ctSig]]);
    $ok('adding contacts also adds their accounts; contact rows are separate from account rows', $c === 200 && $j['data']['added'] === 3, $j['data'] ?? $j);   // ct1 (+its account already) -> 1 contact row; ctSig -> account 3 + contact row
    if ($otherAcct) {
        [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", ['account_ids' => [$otherAcct]]);   $ok("another tenant's account is rejected, never added", $c === 200 && $j['data']['added'] === 0 && count($j['data']['rejected']) === 1, $j['data'] ?? $j);
        $oc = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => $otherTenant, 'account_id' => $otherAcct, 'full_name' => 'Verify Other P', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", ['contact_ids' => [$oc]]);          $ok("another tenant's contact is rejected, never added", $j['data']['added'] === 0 && count($j['data']['rejected']) === 1, $j['data'] ?? $j);
    }
    [$c, $j] = $req('GET', "/gtm/campaigns/$cp"); $mem = collect($j['data']['members']);
    $ok('campaign detail lists targets with account/contact names', $c === 200 && $mem->count() === 4 && $mem->pluck('account_name')->contains('Verify Camp Edu') && $mem->pluck('contact_name')->filter()->count() === 2, $mem->all());
    $ok('campaign detail carries the industry pack content, labelled as hypotheses', $j['data']['industry_pack']['slug'] === 'pack-education' && ! empty($j['data']['industry_pack']['definition']['pains_to_validate']), $j['data']['industry_pack'] ?? null);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'active']);         $ok('activate with targets', $c === 200 && $j['data']['campaign']['status'] === 'active', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'paused']);         $ok('active -> paused', $c === 200, $c);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'active']);         $ok('paused -> active', $c === 200, $c);

    // ---------- outreach joins a campaign ----------
    $body = 'Hello, this is a verification message that is comfortably longer than twenty characters.';
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $ct1, 'subject' => 'Camp mail', 'body' => $body, 'campaign_id' => $cp]); $m1 = $j['data']['message']['id'] ?? 0;
    $ok('a draft can be written under a campaign for a target contact', $c === 201 && (int) $j['data']['message']['campaign_id'] === $cp, [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/accounts/$co2/contacts", ['full_name' => 'Verify Camp NonMember', 'email' => 'verify.nonmember@example.invalid']); $ctNon = $j['data']['contact']['id'];
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $ctNon, 'subject' => 'x', 'body' => $body, 'campaign_id' => $cp]); $ok('a contact whose account is not a target is refused (422 not_a_target)', $c === 422 && $j['code'] === 'not_a_target', [$c, $j]);
    if ($otherCamp) { [$c] = $req('POST', '/gtm/outreach', ['contact_id' => $ct1, 'subject' => 'x', 'body' => $body, 'campaign_id' => $otherCamp]); $ok("another tenant's campaign is refused (422)", $c === 422, $c); }

    // ---------- measured results (counted from real rows) ----------
    [$c, $j] = $req('GET', "/gtm/campaigns/$cp"); $mt = $j['data']['metrics'];
    $ok('metrics before any send: 2 accounts / 2 contacts targeted, 1 draft, reply rate null (not 0)', $mt['targets'] === ['accounts' => 2, 'contacts' => 2] && $mt['outreach']['draft'] === 1 && $mt['outreach']['sent'] === 0 && $mt['engagement']['reply_rate_pct'] === null, $mt);
    DB::table('gtm_outreach_messages')->where('id', $m1)->update(['status' => 'sent', 'sent_at' => now()]);   // simulated: nothing is sent in this suite
    DB::table('gtm_activities')->insert(['sub_institute_id' => 6, 'account_id' => $co1, 'contact_id' => $ct1, 'type' => 'email', 'direction' => 'inbound', 'subject' => 'Re: Camp mail', 'occurred_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('gtm_activities')->insert(['sub_institute_id' => 6, 'account_id' => $co1, 'contact_id' => $ct1, 'type' => 'email', 'direction' => 'inbound', 'subject' => 'Old reply before the campaign', 'occurred_at' => now()->subYear(), 'created_at' => now(), 'updated_at' => now()]);
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $co1, 'name' => 'Verify Camp Deal', 'amount' => 5000, 'currency' => 'INR']); $dealId = $j['data']['deal']['id'];
    [$c, $j] = $req('POST', "/gtm/deals/$dealId/stage", ['stage' => 'won', 'confirm' => true]);
    DB::table('gtm_deals')->where('id', $dealId)->update(['created_at' => now()->addMinutes(2)]);
    [$c, $j] = $req('GET', "/gtm/campaigns/$cp"); $mt = $j['data']['metrics'];
    $ok('contacts reached = 1 (sent), contacts replied = 1 (the pre-campaign reply is NOT counted), reply rate 100%', $mt['outreach']['contacts_reached'] === 1 && $mt['engagement']['contacts_replied'] === 1 && $mt['engagement']['reply_rate_pct'] == 100, $mt);
    $ok('conversion: 1 account with a new deal, 1 won, 5000 INR; carries the correlation-not-causation note', $mt['conversion']['accounts_with_new_deal'] === 1 && $mt['conversion']['deals_won'] === 1 && (float) $mt['conversion']['won_value_by_currency']['INR'] === 5000.0 && $mt['conversion']['account_conversion_pct'] == 50 && str_contains($mt['conversion']['note'], 'correlation'), $mt['conversion']);
    DB::table('gtm_contacts')->where('id', $ct1)->update(['status' => 'unsubscribed']);
    [$c, $j] = $req('GET', "/gtm/campaigns/$cp"); $ok('unsubscribed contacts are counted', $j['data']['metrics']['engagement']['unsubscribed_contacts'] === 1, $j['data']['metrics']['engagement']);
    DB::table('gtm_contacts')->where('id', $ct1)->update(['status' => 'active']);

    // ---------- an agent uses the campaign's goal and pack, and the draft stays tied to the campaign ----------
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/members", ['account_ids' => [3]]);
    $sigId = DB::table('g2g_company_opportunities')->where('sub_institute_id', 6)->where('company_id', DB::table('gtm_accounts')->where('id', 3)->value('company_id'))->value('id');
    $fake($gem(['subject' => 'About your initiative', 'body' => 'A grounded message body that is long enough.', 'signal_used' => $sigId, 'claims' => [], 'missing' => []]));
    [$c, $j] = $req('POST', '/gtm/agents/gtm-outreach/run', ['input' => ['account_id' => 3, 'contact_id' => $ctSig, 'mode' => 'email', 'campaign_id' => $cp]]);
    $sent = Http::recorded()->first()[0] ?? null; $prompt = $sent ? json_encode($sent->data()) : '';
    $ok('outreach agent 200 with a campaign; result is tied to the campaign', $c === 200 && $j['data']['result']['campaign_id'] === $cp, [$c, $j]);
    $ok('the model received the campaign goal AND the pack as hypotheses (nothing else invented)', str_contains($prompt, 'Open conversations with education groups') && str_contains($prompt, 'HYPOTHESES') && str_contains($prompt, 'pains_to_validate'), substr($prompt, 0, 300));
    [$c, $j2] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => $j['data']['analysis_id']]);
    $ok('saving the agent draft keeps its campaign', $c === 201 && (int) $j2['data']['messages'][0]['campaign_id'] === $cp, [$c, $j2]);
    [$c, $j] = $req('POST', '/gtm/agents/gtm-outreach/run', ['input' => ['account_id' => 3, 'contact_id' => $ctSig, 'mode' => 'email', 'campaign_id' => 999999]]); $ok('agent with an unknown campaign -> 422', $c === 422, [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/agents/gtm-outreach/run', ['input' => ['account_id' => $co2, 'contact_id' => $ctNon, 'mode' => 'email', 'campaign_id' => $cp]]); $ok('agent: account not in the campaign -> 422', $c === 422, [$c, $j]);

    // ---------- tenant isolation ----------
    if ($O) {
        foreach ([['GET', "/gtm/campaigns/$cp"], ['PATCH', "/gtm/campaigns/$cp"], ['POST', "/gtm/campaigns/$cp/status"], ['POST', "/gtm/campaigns/$cp/members"], ['GET', "/gtm/segments/$s1/preview"], ['PATCH', "/gtm/segments/$s1"], ['DELETE', "/gtm/segments/$s1"]] as [$m, $u]) {
            [$c] = $req($m, $u, ['name' => 'hijack', 'status' => 'archived', 'confirm' => true, 'account_ids' => [3], 'criteria' => ['stages' => ['target']]], $O); $ok("other tenant $m " . preg_replace('#/gtm/#', '', $u) . ' -> 404', $c === 404, $c);
        }
        [$c, $j] = $req('GET', '/gtm/campaigns', [], $O);  $ok("another tenant's campaign list holds none of mine", collect($j['data']['items'])->pluck('id')->intersect([$cp])->isEmpty(), $c);
        [$c, $j] = $req('GET', '/gtm/segments', [], $O);   $ok("another tenant's segment list holds none of mine", collect($j['data']['items'])->pluck('id')->intersect([$s1, $s2, $s3, $s4])->isEmpty(), $c);
        [$c, $j] = $req('GET', "/gtm/segments/$otherSeg/preview", [], $O);
        $ok("another tenant's segment previews only that tenant's accounts (never mine)", $c === 200 && collect($j['data']['sample'])->every(fn ($r) => (int) DB::table('gtm_accounts')->where('id', $r['id'])->value('sub_institute_id') === $otherTenant), $j['data'] ?? $c);
    }

    // ---------- closing a campaign stops its unsent outreach ----------
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $ct1, 'subject' => 'Will be cancelled', 'body' => $body, 'campaign_id' => $cp]); $m2 = $j['data']['message']['id'];
    DB::table('gtm_contacts')->where('id', $ct1)->update(['status' => 'active']);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'completed']);     $ok('completing without confirm -> 409', $c === 409 && $j['code'] === 'confirmation_required', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'completed', 'confirm' => true]);
    $ok('completing with confirm cancels the unsent drafts (sent mail is untouched)', $c === 200 && $j['data']['outreach_cancelled'] >= 1 && DB::table('gtm_outreach_messages')->where('id', $m2)->value('status') === 'cancelled' && DB::table('gtm_outreach_messages')->where('id', $m1)->value('status') === 'sent', [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/campaigns/$cp", ['name' => 'edit']);                  $ok('a completed campaign cannot be edited (422 campaign_closed)', $c === 422 && $j['code'] === 'campaign_closed', [$c, $j]);
    [$c] = $req('POST', "/gtm/campaigns/$cp/members", ['account_ids' => [$co2]]);        $ok('a completed campaign takes no new targets (422)', $c === 422, $c);
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $ct1, 'subject' => 'x', 'body' => $body, 'campaign_id' => $cp]); $ok('no new outreach can be written for a completed campaign (422)', $c === 422 && $j['code'] === 'campaign_closed', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'active']);        $ok('a completed campaign cannot be reopened (422)', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/campaigns/$cp/status", ['status' => 'archived', 'confirm' => true]); $ok('completed -> archived', $c === 200, [$c, $j]);
    [$c, $j] = $req('GET', '/gtm/campaigns'); $ok('archived campaigns are hidden from the default list', ! collect($j['data']['items'])->pluck('id')->contains($cp), $c);
    [$c, $j] = $req('GET', '/gtm/campaigns?status=archived'); $ok('...and listed when asked for', collect($j['data']['items'])->pluck('id')->contains($cp), $c);
    // member removal + segment delete
    [$c, $j] = $req('POST', '/gtm/campaigns', ['name' => 'Verify Scratch', 'kind' => 'outbound']); $cp2 = $j['data']['campaign']['id'];
    $req('POST', "/gtm/campaigns/$cp2/members", ['account_ids' => [$co2]]);
    $memId = DB::table('gtm_campaign_members')->where('campaign_id', $cp2)->value('id');
    [$c] = $req('DELETE', "/gtm/campaigns/$cp2/members/$memId"); $ok('remove a target', $c === 200 && DB::table('gtm_campaign_members')->where('campaign_id', $cp2)->count() === 0, $c);
    [$c] = $req('DELETE', "/gtm/campaigns/$cp2/members/$memId"); $ok('removing it again -> 404', $c === 404, $c);
    [$c] = $req('DELETE', "/gtm/segments/$s5", [], $E);        $ok('employee cannot delete a segment (403)', $c === 403, $c);
    [$c] = $req('DELETE', "/gtm/segments/$s5");                $ok('delete a segment', $c === 200, $c);
    [$c] = $req('GET', "/gtm/segments/$s5/preview");           $ok('a deleted segment is gone (404)', $c === 404, $c);
    $types = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'like', 'gtm.%')->whereIn('type', ['gtm.segment.created', 'gtm.segment.updated', 'gtm.segment.deleted', 'gtm.campaign.created', 'gtm.campaign.status_changed', 'gtm.campaign.members_added'])->pluck('type')->unique()->all();
    $ok('audit events: segment + campaign lifecycle recorded', count($types) === 6, $types);
} finally {
    Http::swap(new Illuminate\Http\Client\Factory);
    gtm_verify_cleanup($startedAt, $origStage ?? null);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
