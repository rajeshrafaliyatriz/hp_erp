/**
 * X-21 — REAL BROWSER VERIFICATION for the readiness-gate screen (X-07d).
 *
 * A payload containing `losing` is not the same as a customer reading it before
 * they click. This drives a real browser: real login, real render, real click.
 *
 * IT MANUFACTURES ONE at_risk GATE AND PUTS IT BACK. No real gate is currently
 * at_risk, so the confirm dialog - the whole point of the screen - is otherwise
 * unreachable. The row is snapshotted, altered, and restored in a `finally`, so
 * an exception mid-run still restores it. Shared remote database.
 *
 * Run:  node readiness.js [--attach]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const http = require('http');
const path = require('path');

const HP_ERP = path.resolve(__dirname, '../../../..');
const API = 'http://localhost:8000';
const APP = 'http://localhost:3000';
const PASSWORD = process.env.G2G_SEED_PASSWORD || 'G2GDemo@2026';
const attach = process.argv.includes('--attach');

let pass = 0, fail = 0;
function report(status, name, detail) {
  if (status === 'PASS') pass++; else fail++;
  console.log(`  ${status.padEnd(7)} ${name.padEnd(52)} ${detail || ''}`);
}

/** Snapshot / mutate / restore, done in PHP so it uses the app's own connection. */
function php(code) {
  return execFileSync('php', ['-r', code], { cwd: HP_ERP, encoding: 'utf8' }).trim();
}

const BOOT = "require 'vendor/autoload.php'; $a=require 'bootstrap/app.php';"
  + "$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
  + "use Illuminate\\Support\\Facades\\DB;";

async function waitFor(url, ms) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    const ok = await new Promise((res) => {
      const r = http.get(url, (x) => { x.resume(); res(true); });
      r.on('error', () => res(false));
      r.setTimeout(2000, () => { r.destroy(); res(false); });
    });
    if (ok) return true;
    await new Promise((r) => setTimeout(r, 1000));
  }
  return false;
}

(async () => {
  console.log('\n======== X-21 — READINESS GATE SCREEN (X-07d) ========\n');

  if (!attach) {
    console.log('Expecting servers already running. Start them with verify.js or');
    console.log('run this with --attach once :8000 and :3000 are up.');
  }
  const apiUp = await waitFor(API + '/api/terminology', 20000);
  const appUp = await waitFor(APP + '/login', 20000);
  console.log(`  laravel :8000 ${apiUp ? 'UP' : 'DOWN'}   next :3000 ${appUp ? 'UP' : 'DOWN'}\n`);
  if (!apiUp || !appUp) { console.log('servers not up - aborting rather than reporting false failures'); process.exit(1); }

  // ── who is the admin, and which tenant are they in ────────────────────────
  // TENANT 3, NOT "the first administrator found". The first run picked tenant 1's
  // real administrator and failed to log in - that account has a real password.
  // Tenant 3 is the seeded tenant whose 9 role logins were verified when it was
  // built, so it is the only one where a browser login is a test of the SCREEN
  // rather than a test of whether we know somebody's password.
  const TENANT = 3;
  const who = JSON.parse(php(BOOT
    + `$p=DB::table('tbluserprofilemaster')->where('sub_institute_id',${TENANT})->where('role_key','administrator')->first();`
    // THE SEEDED ACCOUNT SPECIFICALLY. Tenant 3 also holds a non-seeded admin
    // (healthcare@gmail.com) with a real password, and taking "the first admin"
    // picked it twice. The seed's accounts are the @healthcare.g2g domain and
    // they are the only ones whose credential is known.
    + `$u=DB::table('tbluser')->where('sub_institute_id',${TENANT})->where('user_profile_id',$p->id)`
    + `->where('email','like','%@healthcare.g2g')->first(['id','email']);`
    + "echo json_encode(['tenant'=>" + TENANT + ",'email'=>$u->email]);"));
  console.log(`  admin ${who.email} in tenant ${who.tenant}\n`);

  // ── SNAPSHOT, then manufacture one at_risk gate ───────────────────────────
  const snapshot = php(BOOT
    + `$r=DB::table('tenant_readiness_gate')->where('sub_institute_id',${who.tenant})->where('gate_key','reporting_coverage')->first();`
    + "echo json_encode($r);");
  console.log('  snapshot taken of reporting_coverage');

  const browser = await chromium.launch();
  try {
    php(BOOT
      + `DB::table('tenant_readiness_gate')->where('sub_institute_id',${who.tenant})->where('gate_key','reporting_coverage')`
      + "->update(['state'=>'at_risk','at_risk_since'=>now()->subDays(30),'warning_days'=>14,"
      + "'acknowledged_by'=>null,'acknowledged_at'=>null]);");
    report('PASS', 'fixture: one gate set at_risk, 30 days elapsed', 'warning period over');

    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(e.message.split('\n')[0]));

    await page.goto(APP + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.fill('#email', who.email);
    await page.fill('#password', PASSWORD);
    await Promise.all([
      page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }).catch(() => {}),
      page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(1500);
    report(page.url().includes('/login') ? 'FAIL' : 'PASS', 'admin logs in', new URL(page.url()).pathname);

    await page.goto(APP + '/organization/readiness', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(2500);

    // 1. the page rendered from real data, not an error state
    const err = await page.locator('[data-testid="readiness-error"]').count();
    const gates = await page.locator('[data-testid^="gate-"][data-testid$="-state"]').count();
    report(err === 0 && gates >= 5 ? 'PASS' : 'FAIL', 'screen renders gates for the admin',
      `${gates} gate(s), ${err} error block(s)`);

    // 2. PRESENT IS NOT VISIBLE - the acknowledge button must be on screen
    const ackBtn = page.locator('[data-testid="gate-reporting_coverage-ack"]');
    const ackVisible = await ackBtn.isVisible().catch(() => false);
    report(ackVisible ? 'PASS' : 'FAIL', 'at_risk gate offers the turn-off button', ackVisible ? 'visible' : 'not visible');

    // 3. THE DIALOG CARRIES THE LOSS, THE DAYS AND THE REASON
    if (ackVisible) {
      await ackBtn.click();
      await page.waitForTimeout(600);
      const losing = (await page.locator('[data-testid="confirm-losing"]').innerText().catch(() => '')).trim();
      const reason = (await page.locator('[data-testid="confirm-reason"]').innerText().catch(() => '')).trim();
      const days = (await page.locator('[data-testid="confirm-days"]').innerText().catch(() => '')).trim();

      report(losing.length > 40 && /manager/i.test(losing) ? 'PASS' : 'FAIL',
        'dialog states WHAT IS LOST', losing.slice(0, 58));
      report(reason.length > 20 ? 'PASS' : 'FAIL', 'dialog states WHY it is at risk', reason.slice(0, 58));
      report(/day/i.test(days) ? 'PASS' : 'FAIL', 'dialog states the warning period', days.slice(0, 58));

      // KNOWN-NEGATIVE: a generic dialog would pass a mere "is there text?" check.
      const generic = /are you sure/i.test(losing + reason + days);
      report(!generic ? 'PASS' : 'FAIL', 'dialog is NOT a generic are-you-sure',
        generic ? 'generic phrasing found' : 'names the specific consequence');

      await page.locator('[data-testid="confirm-cancel"]').click();
      await page.waitForTimeout(400);
      const stillAtRisk = php(BOOT
        + `echo DB::table('tenant_readiness_gate')->where('sub_institute_id',${who.tenant})->where('gate_key','reporting_coverage')->value('state');`);
      report(stillAtRisk === 'at_risk' ? 'PASS' : 'FAIL', 'cancel writes nothing', `state=${stillAtRisk}`);
    }

    report(errs.length === 0 ? 'PASS' : 'FAIL', 'no uncaught page errors', errs.slice(0, 2).join(' | '));

    // 4. THE ROLE GUARD, IN THE BROWSER. Employee must be refused, not shown gates.
    const who2 = JSON.parse(php(BOOT
      + `$p=DB::table('tbluserprofilemaster')->where('sub_institute_id',${who.tenant})->where('role_key','employee')->first();`
      + `$u=DB::table('tbluser')->where('sub_institute_id',${who.tenant})->where('user_profile_id',$p->id)`
      + `->where('email','like','%@healthcare.g2g')->first(['email']);`
      + "echo json_encode(['email'=>$u->email ?? null]);"));

    if (who2.email) {
      const ctx2 = await browser.newContext();
      const p2 = await ctx2.newPage();
      await p2.goto(APP + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
      await p2.fill('#email', who2.email);
      await p2.fill('#password', PASSWORD);
      await Promise.all([
        p2.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }).catch(() => {}),
        p2.click('button[type="submit"]'),
      ]);
      await p2.goto(APP + '/organization/readiness', { waitUntil: 'domcontentloaded', timeout: 30000 });
      await p2.waitForTimeout(2500);
      const refused = await p2.locator('[data-testid="readiness-error"]').isVisible().catch(() => false);
      const sees = await p2.locator('[data-testid^="gate-"][data-testid$="-state"]').count();
      report(refused && sees === 0 ? 'PASS' : 'FAIL', 'employee is refused IN THE BROWSER',
        `error shown=${refused}, gates visible=${sees}`);
      await ctx2.close();
    } else {
      report('SKIPPED', 'employee is refused IN THE BROWSER', 'no employee account in this tenant');
    }
  } finally {
    // ── RESTORE. Runs even if the browser half threw. ────────────────────────
    const s = JSON.parse(snapshot);
    php(BOOT
      + `DB::table('tenant_readiness_gate')->where('sub_institute_id',${who.tenant})->where('gate_key','reporting_coverage')->update(`
      + `['state'=>'${s.state}','at_risk_since'=>` + (s.at_risk_since ? `'${s.at_risk_since}'` : 'null')
      + `,'warning_days'=>${s.warning_days},'acknowledged_by'=>` + (s.acknowledged_by ?? 'null')
      + `,'acknowledged_at'=>` + (s.acknowledged_at ? `'${s.acknowledged_at}'` : 'null') + ']);');
    const back = php(BOOT
      + `$r=DB::table('tenant_readiness_gate')->where('sub_institute_id',${who.tenant})->where('gate_key','reporting_coverage')->first();`
      + "echo $r->state.'|'.($r->at_risk_since ?? 'null');");
    console.log(`\n  RESTORED: state|at_risk_since = ${back}   (was ${s.state}|${s.at_risk_since ?? 'null'})`);
    await browser.close();
  }

  console.log(`\n  PASS ${pass}   FAIL ${fail}\n`);
  process.exit(fail ? 1 : 0);
})();
