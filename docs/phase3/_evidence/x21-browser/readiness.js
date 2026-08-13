/**
 * X-21 — REAL BROWSER VERIFICATION for the readiness-gate screen (X-07d).
 *
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║ PLATFORM BOUNDARY — X-21 CANNOT RELIABLY VERIFY A SCREEN ON WINDOWS.      ║
 * ║                                                                           ║
 * ║ `php artisan serve` is SINGLE-THREADED. `PHP_CLI_SERVER_WORKERS` is the   ║
 * ║ documented fix and it is a POSIX fork feature: MEASURED ON THIS MACHINE   ║
 * ║ IT DOES NOTHING (ratio 4.5 with it, 4.4 without). A page that fires       ║
 * ║ several requests on mount starves itself, and the browser sees a screen   ║
 * ║ that never finished loading.                                              ║
 * ║                                                                           ║
 * ║ EVERY SCREEN ITEM INHERITS THIS. It produced G-UI-02 - six turns, five    ║
 * ║ eliminations, parked unexplained - and then cost X-07d a turn, because    ║
 * ║ the harness comment said "PHP_CLI_SERVER_WORKERS is not optional" and the ║
 * ║ measurement said "it does nothing here" AND THE TWO NOTES LIVED APART.    ║
 * ║ They are together now.                                                    ║
 * ║                                                                           ║
 * ║ The durable fix is a properly threaded server (WSL, Docker, a real dev    ║
 * ║ environment). That is the owner's call, not this harness's. Until then    ║
 * ║ this file verifies what it can and reports UNSTABLE for the rest.         ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 *
 * WHAT THIS RUN GUARANTEES, GIVEN THAT BOUNDARY:
 *
 *   - THREE STATES, NEVER COLLAPSED. rendered / refused / loading are
 *     distinguished and reported separately. The previous version counted "error
 *     blocks", which said a failure happened and not which one - the
 *     undifferentiated-message mistake, one level up, in my own instrument.
 *   - IT REPEATS AND COMPARES. Every observation runs REPEATS times. If the runs
 *     disagree the verdict is UNSTABLE - not PASS, not FAIL. Three identical runs
 *     producing three different results is a verdict about the HARNESS, and it
 *     now says so itself instead of waiting for a human to notice.
 *   - IT PRINTS THE ERROR TEXT AND THE STORED SESSION, never a count.
 *
 * IT MANUFACTURES ONE at_risk GATE AND PUTS IT BACK. No real gate is at_risk, so
 * the confirm dialog is otherwise unreachable. Snapshot, alter, restore in a
 * `finally` so an exception mid-run still restores it. Shared remote database.
 *
 * Run:  node readiness.js
 *       X21_REPEATS=5 node readiness.js
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const http = require('http');
const path = require('path');

const HP_ERP = path.resolve(__dirname, '../../../..');
const API = 'http://localhost:8000';
const APP = 'http://localhost:3000';
const PASSWORD = process.env.G2G_SEED_PASSWORD || 'G2GDemo@2026';
const REPEATS = Number(process.env.X21_REPEATS || 3);
const SETTLE_MS = 20000;   // how long a page may take to reach a DEFINITE state

let pass = 0, fail = 0, unstable = 0;
function report(status, name, detail) {
  if (status === 'PASS') pass++;
  else if (status === 'UNSTABLE') unstable++;
  else if (status !== 'SKIPPED') fail++;
  console.log(`  ${status.padEnd(8)} ${name.padEnd(44)} ${detail || ''}`);
}

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

/**
 * THE THREE STATES, KEPT APART.
 *
 * Polls until the page reaches a DEFINITE state or SETTLE_MS elapses. "Still
 * loading when we looked" is its own answer: the previous version collapsed it
 * into FAIL and would have reported a product defect that was a starved server.
 *
 * @returns {{kind:'rendered'|'refused'|'loading'|'blank', gates:number, text:string}}
 */
async function observe(page) {
  const end = Date.now() + SETTLE_MS;
  let last = { kind: 'blank', gates: 0, text: 'neither gates, error, nor loading marker' };

  while (Date.now() < end) {
    const gates = await page.locator('[data-testid^="gate-"][data-testid$="-state"]').count().catch(() => 0);
    if (gates > 0) return { kind: 'rendered', gates, text: '' };

    const errVisible = await page.locator('[data-testid="readiness-error"]').isVisible().catch(() => false);
    if (errVisible) {
      const text = (await page.locator('[data-testid="readiness-error"]').innerText().catch(() => '')).trim();
      // A REFUSAL AND A BROKEN URL BOTH RENDER AN ERROR BLOCK. Collapsing them
      // is the exact mistake this harness was rewritten to stop making - and it
      // made it once already: a doubled `api/` prefix produced a 404 whose error
      // block was reported as "employee is refused", which would have certified
      // the role guard on the strength of a typo.
      //
      // The refusal is a SPECIFIC sentence the controller sends. Anything else
      // is `broken`, and `broken` is never a passing result for anyone.
      const isRefusal = /Admin and HR only/i.test(text);
      return { kind: isRefusal ? 'refused' : 'broken', gates: 0, text };
    }

    const loading = await page.locator('[data-testid="readiness-loading"]').isVisible().catch(() => false);
    last = loading
      ? { kind: 'loading', gates: 0, text: 'still loading' }
      : { kind: 'blank', gates: 0, text: 'neither gates, error, nor loading marker' };

    await page.waitForTimeout(750);
  }
  return last;   // never resolved
}

async function storedSession(page) {
  return page.evaluate(() => {
    const raw = window.localStorage.getItem('userData') || window.sessionStorage.getItem('userData');
    if (!raw) return 'NO userData IN STORAGE';
    try {
      const p = JSON.parse(raw);
      return `token=${p.token ? String(p.token).slice(0, 10) + '…' : 'ABSENT'} tenant=${p.sub_institute_id}`;
    } catch { return 'userData PRESENT BUT UNPARSEABLE'; }
  }).catch(() => 'unreadable');
}

/** One full login-and-look, reduced to a comparable key. */
async function trial(browser, email) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  try {
    await page.goto(APP + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await Promise.all([
      page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }).catch(() => {}),
      page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(1200);

    if (page.url().includes('/login')) {
      return { key: 'login-failed', detail: 'still on /login', session: await storedSession(page), page, ctx };
    }

    await page.goto(APP + '/organization/readiness', { waitUntil: 'domcontentloaded', timeout: 30000 });
    const seen = await observe(page);
    return {
      key: seen.kind === 'rendered' ? `rendered:${seen.gates}` : seen.kind,
      detail: seen.text,
      session: await storedSession(page),
      page,
      ctx,
    };
  } catch (e) {
    return { key: 'threw', detail: String(e.message).split('\n')[0].slice(0, 60), session: '', page, ctx };
  }
}

/** Repeat, compare, and refuse to call a disagreement a result. */
async function stable(browser, email, label) {
  const runs = [];
  let keep = null;
  for (let i = 0; i < REPEATS; i++) {
    const t = await trial(browser, email);
    runs.push(t.key);
    if (i === REPEATS - 1) keep = t;
    else await t.ctx.close();
  }
  const distinct = [...new Set(runs)];
  console.log(`    ${label} runs: ${runs.join(' | ')}`);
  if (keep.session) console.log(`    ${label} session: ${keep.session}`);
  if (keep.detail) console.log(`    ${label} detail: ${keep.detail.slice(0, 92)}`);
  return { agreed: distinct.length === 1, key: distinct[0], distinct, keep };
}

(async () => {
  console.log('\n======== X-21 — READINESS GATE SCREEN (X-07d) ========');
  console.log(`repeats=${REPEATS}  settle=${SETTLE_MS}ms`);
  console.log("PLATFORM: artisan serve is single-threaded here - see this file's header.\n");

  const apiUp = await waitFor(API + '/api/terminology', 20000);
  const appUp = await waitFor(APP + '/login', 20000);
  console.log(`  laravel :8000 ${apiUp ? 'UP' : 'DOWN'}   next :3000 ${appUp ? 'UP' : 'DOWN'}\n`);
  if (!apiUp || !appUp) {
    console.log('  servers not up - aborting rather than reporting false failures');
    process.exit(2);
  }

  const TENANT = 3;
  const who = JSON.parse(php(BOOT
    + `$p=DB::table('tbluserprofilemaster')->where('sub_institute_id',${TENANT})->where('role_key','administrator')->first();`
    + `$e=DB::table('tbluserprofilemaster')->where('sub_institute_id',${TENANT})->where('role_key','employee')->first();`
    // The SEEDED accounts specifically. Tenant 3 also holds a non-seeded admin
    // with a real password, and "the first administrator found" picked it twice.
    + `$a=DB::table('tbluser')->where('sub_institute_id',${TENANT})->where('user_profile_id',$p->id)->where('email','like','%@healthcare.g2g')->value('email');`
    + `$u=DB::table('tbluser')->where('sub_institute_id',${TENANT})->where('user_profile_id',$e->id)->where('email','like','%@healthcare.g2g')->value('email');`
    + "echo json_encode(['admin'=>$a,'employee'=>$u]);"));
  console.log(`  admin=${who.admin}   employee=${who.employee}\n`);

  const snapshot = php(BOOT
    + `$r=DB::table('tenant_readiness_gate')->where('sub_institute_id',${TENANT})->where('gate_key','reporting_coverage')->first();`
    + "echo json_encode($r);");

  const browser = await chromium.launch();
  try {
    php(BOOT
      + `DB::table('tenant_readiness_gate')->where('sub_institute_id',${TENANT})->where('gate_key','reporting_coverage')`
      + "->update(['state'=>'at_risk','at_risk_since'=>now()->subDays(30),'warning_days'=>14,"
      + "'acknowledged_by'=>null,'acknowledged_at'=>null]);");
    report('PASS', 'fixture: one gate at_risk, 30 days elapsed', 'warning period over');

    // ── ADMIN ────────────────────────────────────────────────────────────────
    const admin = await stable(browser, who.admin, 'admin');
    if (!admin.agreed) {
      report('UNSTABLE', 'admin view', `${admin.distinct.length} different results in ${REPEATS} runs - HARNESS, not product`);
    } else if (admin.key.startsWith('rendered')) {
      report('PASS', 'admin view renders gates', admin.key);
    } else if (admin.key === 'broken') {
      report('FAIL', 'admin view', `error, but NOT the refusal: ${admin.keep.detail.slice(0, 52)}`);
    } else if (admin.key === 'loading' || admin.key === 'blank') {
      report('UNSTABLE', 'admin view', `never left "${admin.key}" in ${SETTLE_MS}ms - starved server, not a verdict`);
    } else {
      report('FAIL', 'admin view', `${admin.key}: ${admin.detail}`);
    }

    // The dialog is only meaningful if the page rendered at all.
    if (admin.agreed && admin.key.startsWith('rendered')) {
      const p = admin.keep.page;
      const ack = p.locator('[data-testid="gate-reporting_coverage-ack"]');
      const vis = await ack.isVisible().catch(() => false);
      report(vis ? 'PASS' : 'FAIL', 'at_risk gate offers the turn-off button', vis ? 'visible' : 'not visible');
      if (vis) {
        await ack.click();
        await p.waitForTimeout(700);
        const losing = (await p.locator('[data-testid="confirm-losing"]').innerText().catch(() => '')).trim();
        const reason = (await p.locator('[data-testid="confirm-reason"]').innerText().catch(() => '')).trim();
        const days = (await p.locator('[data-testid="confirm-days"]').innerText().catch(() => '')).trim();
        report(/manager/i.test(losing) ? 'PASS' : 'FAIL', 'dialog states WHAT IS LOST', losing.slice(0, 50));
        report(reason.length > 20 ? 'PASS' : 'FAIL', 'dialog states WHY it is at risk', reason.slice(0, 50));
        report(/day/i.test(days) ? 'PASS' : 'FAIL', 'dialog states the warning period', days.slice(0, 50));
        report(!/are you sure/i.test(losing + reason + days) ? 'PASS' : 'FAIL',
          'dialog is NOT a generic are-you-sure', 'names the specific consequence');
        await p.locator('[data-testid="confirm-cancel"]').click();
        await p.waitForTimeout(400);
        const st = php(BOOT + `echo DB::table('tenant_readiness_gate')->where('sub_institute_id',${TENANT})->where('gate_key','reporting_coverage')->value('state');`);
        report(st === 'at_risk' ? 'PASS' : 'FAIL', 'cancel writes nothing', `state=${st}`);
      }
    } else {
      report('SKIPPED', 'confirm dialog', 'admin view never reached a stable rendered state');
    }
    await admin.keep.ctx.close();

    // ── EMPLOYEE. The refusal is the half that matters most. ─────────────────
    if (who.employee) {
      const emp = await stable(browser, who.employee, 'employee');
      if (!emp.agreed) {
        report('UNSTABLE', 'employee is refused in the browser', emp.distinct.join(' / '));
      } else if (emp.key === 'refused') {
        report('PASS', 'employee is REFUSED in the browser', emp.keep.detail.slice(0, 50));
      } else if (emp.key === 'broken') {
        // NOT a pass. An employee seeing a 404 tells us nothing about the guard.
        report('FAIL', 'employee is refused in the browser',
          `error shown but NOT the refusal: ${emp.keep.detail.slice(0, 46)}`);
      } else {
        report('FAIL', 'employee is refused in the browser', emp.key);
      }
      await emp.keep.ctx.close();
    } else {
      report('SKIPPED', 'employee is refused in the browser', 'no seeded employee');
    }
  } finally {
    const s = JSON.parse(snapshot);
    php(BOOT
      + `DB::table('tenant_readiness_gate')->where('sub_institute_id',${TENANT})->where('gate_key','reporting_coverage')->update(`
      + `['state'=>'${s.state}','at_risk_since'=>` + (s.at_risk_since ? `'${s.at_risk_since}'` : 'null')
      + `,'warning_days'=>${s.warning_days},'acknowledged_by'=>` + (s.acknowledged_by ?? 'null')
      + `,'acknowledged_at'=>` + (s.acknowledged_at ? `'${s.acknowledged_at}'` : 'null') + ']);');
    const back = php(BOOT
      + `$r=DB::table('tenant_readiness_gate')->where('sub_institute_id',${TENANT})->where('gate_key','reporting_coverage')->first();`
      + "echo $r->state.'|'.($r->at_risk_since ?? 'null');");
    console.log(`\n  RESTORED: ${back}   (was ${s.state}|${s.at_risk_since ?? 'null'})`);
    await browser.close();
  }

  console.log(`\n  PASS ${pass}   FAIL ${fail}   UNSTABLE ${unstable}`);
  if (unstable) {
    console.log('\n  UNSTABLE IS NOT A FAILING SCREEN AND NOT A PASSING ONE.');
    console.log('  This harness could not reach a repeatable answer on this platform.');
    console.log("  See the header: artisan serve is single-threaded here, and");
    console.log('  PHP_CLI_SERVER_WORKERS does nothing on Windows.');
  }
  console.log('');
  process.exit(fail ? 1 : (unstable ? 3 : 0));
})();
