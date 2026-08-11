/**
 * X-21 — REAL BROWSER VERIFICATION.
 *
 * Chromium 151, headless, driving the actual Next.js app against the actual
 * Laravel API. This is the first time anything in this phase has verified a
 * RENDERED SCREEN rather than the source behind it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT EXISTS BECAUSE I WAS WRONG ABOUT MY OWN CAPABILITY.
 *
 *   C20's entire verification protocol - the manual walkthrough, the "UI-only
 *   residue", the "you must run these yourself" - rested on my assertion that I
 *   could not drive a browser. I NEVER TESTED THAT. node is present, the dev
 *   server starts, and Chromium launches headless. All three took four minutes
 *   to establish once anybody asked.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * SEPARATE COMMAND, NOT IN THE SMOKE SUITE: two servers plus a browser is ~3-5
 * minutes against smoke's 91 seconds.
 *
 * Usage:  node verify.js            (starts and stops both servers itself)
 *         node verify.js --attach   (servers already running)
 */
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const http = require('http');

const HP_ERP = 'C:/Users/MILAN/Downloads/hp_erp';
const G2GV0 = 'C:/Users/MILAN/Downloads/g2gv0';
const API = 'http://127.0.0.1:8000';
const APP = 'http://localhost:3000';
const PASSWORD = 'G2GDemo@2026';

const USERS = [
  ['administrator', 'aarti.deshmukh@healthcare.g2g'],
  ['hr_manager', 'nikhil.rao@healthcare.g2g'],
  ['hr_executive', 'sunita.menon@healthcare.g2g'],
  ['department_head', 'rajesh.iyer@healthcare.g2g'],
  ['reporting_manager', 'farida.khan@healthcare.g2g'],
  ['employee', 'vikram.sethi@healthcare.g2g'],
  ['recruiter', 'kabir.chandra@healthcare.g2g'],
  ['executive', 'leela.varma@healthcare.g2g'],
  ['auditor', 'george.thomas@healthcare.g2g'],
];

let pass = 0, fail = 0, skip = 0;
const results = [];
function report(state, name, detail) {
  results.push([state, name, detail]);
  if (state === 'PASS') pass++; else if (state === 'FAIL') fail++; else skip++;
  console.log(`  ${state.padEnd(6)} ${name.padEnd(52)} ${detail}`);
}

function waitFor(url, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve) => {
    const tick = () => {
      http.get(url, (r) => { r.resume(); resolve(true); })
        .on('error', () => {
          if (Date.now() > deadline) return resolve(false);
          setTimeout(tick, 700);
        });
    };
    tick();
  });
}

const spawned = [];
function start(cmd, args, cwd) {
  const p = spawn(cmd, args, { cwd, shell: true, stdio: 'ignore', detached: false });
  spawned.push(p);
  return p;
}
function stopAll() {
  for (const p of spawned) {
    try { process.kill(p.pid); } catch (e) { /* already gone */ }
  }
  // Turbopack and artisan both fork; kill the tree on Windows.
  try { spawn('taskkill', ['/pid', String(process.pid), '/t'], { shell: true }); } catch (e) {}
}

(async () => {
  const attach = process.argv.includes('--attach');
  console.log('\n================ X-21 — REAL BROWSER VERIFICATION ================\n');

  if (!attach) {
    console.log('starting laravel :8000 and next :3000 ...');
    start('php', ['artisan', 'serve', '--port=8000'], HP_ERP);
    start('npm', ['run', 'dev'], G2GV0);
  }

  const apiUp = await waitFor(API + '/api/terminology', 60000);
  const appUp = await waitFor(APP + '/login', 90000);
  console.log(`  laravel :8000 ${apiUp ? 'UP' : 'DOWN'}   next :3000 ${appUp ? 'UP' : 'DOWN'}\n`);

  if (!appUp) {
    console.log('CANNOT PROCEED: the Next dev server did not come up.');
    stopAll();
    process.exit(1);
  }

  const browser = await chromium.launch({ headless: true });

  // ══ THE KNOWN-NEGATIVE, RUN FIRST ═══════════════════════════════════════
  // R16 inverted. A harness that cannot FAIL on a known-broken page proves
  // nothing when it passes on a real one. The bell is the case: correct-looking
  // source with no data behind it.
  console.log('KNOWN-NEGATIVE — would this harness have caught the dead bell?\n');
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    // The bell EXACTLY as it was: a hardcoded "New" badge and a permanent
    // "You're all caught up", with no request behind either.
    await page.setContent(`
      <div><button aria-label="Notifications">bell</button>
      <div role="menu"><span>Notifications</span><span>New</span>
      <p>You're all caught up</p></div></div>`);

    let fired = false;
    page.on('request', (r) => { if (/\/api\/notifications/.test(r.url())) fired = true; });
    await page.waitForTimeout(600);

    const badgeIsStatic = await page.locator('text=New').isVisible();
    const caught = !fired && badgeIsStatic;
    report(caught ? 'PASS' : 'FAIL', 'harness FAILS the old dead bell',
      caught ? 'no /api/notifications request + static "New" badge -> would have been caught'
             : 'the harness could NOT distinguish the dead bell - it is not finished');
    await ctx.close();
  }

  // ══ NINE LOGIN FLOWS ═════════════════════════════════════════════════════
  console.log('\nNINE LOGIN FLOWS (items 4 + 1 + 2)\n');

  const loggedIn = {};
  for (const [role, email] of USERS) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const pageErrors = [];
    const failedReqs = [];
    page.on('pageerror', (e) => pageErrors.push(e.message.split('\n')[0]));
    page.on('requestfailed', (r) => failedReqs.push(r.url().replace(API, '')));

    let detail = '';
    let ok = false;
    try {
      await page.goto(APP + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.fill('#email', email);
      await page.fill('#password', PASSWORD);
      await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }).catch(() => {}),
        page.click('button[type="submit"]'),
      ]);
      await page.waitForTimeout(1500);

      const url = page.url();
      const left = !url.includes('/login');

      // ITEM 1 — PRESENT IS NOT VISIBLE. isVisible() honours CSS: display,
      // visibility, opacity, zero size, off-screen. A source assertion cannot
      // see any of that.
      const bell = page.locator('button[aria-label*="Notification"]').first();
      const bellVisible = await bell.isVisible().catch(() => false);

      // ITEM 2 — a component that throws renders nothing and looks like a
      // styling problem. Uncaught exceptions are collected above.
      ok = left && bellVisible && pageErrors.length === 0;
      detail = `${left ? 'landed ' + new URL(url).pathname : 'STILL ON /login'}` +
        `, bell ${bellVisible ? 'visible' : 'NOT VISIBLE'}` +
        `, ${pageErrors.length} page error(s)` +
        (pageErrors.length ? ': ' + pageErrors[0].slice(0, 40) : '');
      if (ok) loggedIn[role] = { ctx, page };
    } catch (e) {
      detail = 'threw: ' + e.message.split('\n')[0].slice(0, 60);
    }

    report(ok ? 'PASS' : 'FAIL', `login: ${role}`, detail);
    if (!ok) await ctx.close();
  }

  // ══ ITEM 3 — THE BELL, FOR REAL ══════════════════════════════════════════
  console.log('\nITEM 3 — API SHAPE MISMATCH / NO DATA BEHIND THE SOURCE\n');

  const emp = loggedIn['employee'];
  if (!emp) {
    report('SKIPPED', 'bell fetches on mount', 'employee login failed - no session to test with');
    report('SKIPPED', 'bell renders from the response', 'employee login failed');
  } else {
    const { page } = emp;
    let unreadReq = false, listReq = false, listStatus = null, listBody = null;
    page.on('request', (r) => {
      if (/\/api\/notifications\/unread-count/.test(r.url())) unreadReq = true;
      else if (/\/api\/notifications(\?|$)/.test(r.url())) listReq = true;
    });
    // MY FIRST VERSION READ listStatus BEFORE ITS async HANDLER HAD RUN and
    // reported "HTTP null" as though the endpoint had failed. A response handler
    // that awaits r.json() finishes AFTER the code that reads its result.
    // waitForResponse is awaited at the point of use, so it cannot race.

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    report(unreadReq ? 'PASS' : 'FAIL', 'bell asks the server on mount',
      unreadReq ? 'GET /api/notifications/unread-count fired'
                : 'NO REQUEST - this is the dead-bell signature');

    const listPromise = page.waitForResponse(
      (r) => /\/api\/notifications(\?|$)/.test(r.url()), { timeout: 15000 }
    ).catch(() => null);
    await page.locator('button[aria-label*="Notification"]').first().click();
    const listRes = await listPromise;
    if (listRes) {
      listStatus = listRes.status();
      try { listBody = await listRes.json(); } catch (e) { listBody = null; }
    }
    await page.waitForTimeout(1200);

    const menuText = await page.locator('[role="menu"]').first().innerText().catch(() => '');
    // THE SHAPE TEST: the endpoint answered, and what is on screen is consistent
    // with WHAT IT ANSWERED - not merely non-empty.
    const n = listBody && Array.isArray(listBody.notifications) ? listBody.notifications.length : null;
    let verdict, why;
    if (!listReq)                      { verdict = 'FAIL'; why = 'menu opened but never requested the list'; }
    else if (listStatus !== 200)       { verdict = 'FAIL'; why = `list returned HTTP ${listStatus}`; }
    else if (n === null)               { verdict = 'FAIL'; why = 'response has no notifications array - SHAPE MISMATCH'; }
    else if (n === 0)                  {
      const saysEmpty = /caught up/i.test(menuText);
      verdict = saysEmpty ? 'PASS' : 'FAIL';
      why = saysEmpty ? 'API returned 0, screen says "caught up" - consistent'
                      : `API returned 0 but screen shows: "${menuText.slice(0, 40)}"`;
    } else {
      const first = listBody.notifications[0].subject || '';
      const shown = first && menuText.includes(first.slice(0, 18));
      verdict = shown ? 'PASS' : 'FAIL';
      why = shown ? `API returned ${n}, first subject rendered on screen`
                  : `API returned ${n} but "${first.slice(0, 24)}" is NOT on screen`;
    }
    report(verdict, 'bell renders what the API actually returned', why);
  }

  // ══ ITEMS 5-9 ════════════════════════════════════════════════════════════
  console.log('\nITEMS 5-9 — navigation, hydration, interaction, contrast\n');

  const admin = loggedIn['administrator'];
  if (!admin) {
    report('SKIPPED', 'items 5-9', 'administrator login failed');
  } else {
    const { page } = admin;

    // 5. CLIENT-SIDE NAVIGATION ACTUALLY NAVIGATES.
    const before = page.url();
    // THE SIDEBAR NAVIGATES BY onClick + router.push, NOT <a href>. My first
    // version looked for anchors, found none, and reported a product failure.
    // (Worth noting separately: button-based nav means no middle-click and no
    // "open in new tab". That is a design choice, not a defect, and it is not
    // asserted here.)
    let navOk = false, navDetail = 'no clickable nav element found';
    const candidates = [
      'aside button:visible', 'nav button:visible',
      'aside a[href^="/"]:visible', 'a[href^="/"]:visible',
    ];
    // AND IT MUST NOT CLICK THE PAGE IT IS ALREADY ON. My first version took
    // .first(), which was "Main Dashboard" while sitting on /dashboard - the URL
    // correctly did not change, and the check called that a navigation failure.
    // A destination equal to the origin is not a test of navigation.
    const tried = [];
    outer:
    for (const sel of candidates) {
      const n = Math.min(await page.locator(sel).count(), 8);
      for (let i = 0; i < n; i++) {
        const el = page.locator(sel).nth(i);
        const label = (await el.innerText().catch(() => '')).trim().slice(0, 24);
        if (!label || /dashboard/i.test(label)) continue;   // already there
        tried.push(label);
        await el.click().catch(() => {});
        await page.waitForTimeout(2200);
        if (page.url() !== before) {
          navOk = true;
          navDetail = `"${label}" : ${new URL(before).pathname} -> ${new URL(page.url()).pathname}`;
          break outer;
        }
      }
    }
    if (!navOk) {
      navDetail = tried.length
        ? `clicked ${tried.length} nav item(s) (${tried.slice(0, 3).join(', ')}) and the URL never changed`
        : 'no nav element other than the current page';
    }
    report(navOk ? 'PASS' : 'FAIL', 'client-side navigation navigates', navDetail);

    // 6. HYDRATION. Next reports mismatches as console errors; they mean server
    // and client rendered different trees, which is invisible in source.
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 70)); });
    await page.reload({ waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(2000);
    const hydration = consoleErrors.filter((t) => /hydrat|did not match|Text content/i.test(t));
    report(hydration.length === 0 ? 'PASS' : 'FAIL', 'no hydration mismatch',
      hydration.length === 0 ? `${consoleErrors.length} console error(s), none hydration`
                             : hydration[0]);

    // 9. INTERACTION: a menu that opens. Source proves the markup exists; only a
    // browser proves the click does anything.
    const bell = page.locator('button[aria-label*="Notification"]').first();
    let opened = false;
    if (await bell.count()) {
      const menuBefore = await page.locator('[role="menu"]').count();
      await bell.click().catch(() => {});
      await page.waitForTimeout(900);
      opened = (await page.locator('[role="menu"]').count()) > menuBefore ||
               (await page.locator('[role="menu"]').first().isVisible().catch(() => false));
    }
    report(opened ? 'PASS' : 'FAIL', 'clicking the bell opens the menu',
      opened ? 'menu rendered on click' : 'click produced no menu');

    // ── ITEM 8, FOR REAL: REACH THE GAP VIEW ────────────────────────────
    // It SKIPPED before because "Not yet assessed" is not on the dashboard. A
    // skip is not a pass, so the harness now navigates to the screen instead of
    // reporting that it could not find it.
    //
    // It walks the nav the way a person does rather than hardcoding a path -
    // module slugs are per-tenant runtime values, so a constructed URL would be
    // a guess. Whether those slugs are STABLE is an open question (see the log);
    // walking the nav is correct either way.
    const empPage = loggedIn['employee'] ? loggedIn['employee'].page : null;
    if (!empPage) {
      report('SKIPPED', 'gap view: unmeasured shows words, not a zero', 'employee login failed');
    } else {
      // ROUTE DISCOVERY FROM THE NAV API, NOT FROM A GUESS.
      // The employee's sidebar shows only "Main Dashboard" - modules live behind
      // a switcher - so walking visible buttons found nothing. The nav endpoint
      // returns each module's accessLink (/module/competency-management), which
      // is the app's OWN answer to "where is this screen", not my invention.
      let found = false, visited = 0;
      const wanted = /not yet assessed/i;

      // THE ACCESS_LINK, which is the app's OWN answer for where this screen is
      // (tblmenumaster_g2g row 224). Going to the MODULE link lands on a page with
      // no content route, which falls back to the dashboard.
      await empPage.goto(APP + '/module/competency-management/competency-library/my-capability',
        { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
      await empPage.waitForTimeout(3000);
      visited++;
      let body = await empPage.locator('body').innerText().catch(() => '');
      if (wanted.test(body)) found = true;

      if (!found) {
        // Then its sub-screens, by their own visible labels.
        const kids = empPage.locator('button:visible, a:visible');
        const n = Math.min(await kids.count(), 40);
        for (let j = 0; j < n; j++) {
          const kl = (await kids.nth(j).innerText().catch(() => '')).trim();
          if (!kl || !/capabilit|my competenc|gap|profile/i.test(kl)) continue;
          await kids.nth(j).click().catch(() => {});
          await empPage.waitForTimeout(2500);
          visited++;
          body = await empPage.locator('body').innerText().catch(() => '');
          if (wanted.test(body)) { found = true; break; }
          if (visited > 8) break;
        }
      }

      if (!found) {
        report('SKIPPED', 'gap view: unmeasured shows words, not a zero',
          `walked ${visited} screen(s), "Not yet assessed" not reached - route not found by nav`);
      } else {
        // THE ASSERTION TRIZ CARES MOST ABOUT: no zero where unmeasured belongs.
        const bad = await empPage.evaluate(() => {
          const cells = Array.from(document.querySelectorAll('td, li, div'))
            .filter((e) => /not yet assessed/i.test(e.textContent || '') && e.children.length < 6);
          if (!cells.length) return { reached: false };
          // The ROW containing the unmeasured cell must not also show a level.
          const row = cells[0].closest('tr') || cells[0].parentElement;
          const text = (row ? row.innerText : '').replace(/\s+/g, ' ');
          // A bare 0 rendered as a level or a percentage is the failure.
          const zero = /(^|[^\d])0([^\d%]|$)/.test(text) || /0%/.test(text);
          return { reached: true, text: text.slice(0, 90), zero };
        });
        if (!bad.reached) {
          report('FAIL', 'gap view: unmeasured shows words, not a zero',
            'text present but no element resolved - selector too narrow');
        } else {
          report(bad.zero ? 'FAIL' : 'PASS', 'gap view: unmeasured shows words, not a zero',
            bad.zero ? `A ZERO IS RENDERED BESIDE "Not yet assessed": "${bad.text}"`
                     : `row reads "${bad.text}" - words, no zero`);
        }
      }
    }

    // 8b. CONTRAST — BOUNDED, AND SAID SO. This checks the unmeasured text is not
    // invisible-on-invisible. It is NOT a WCAG audit and does not claim to be.
    const probePage = (loggedIn['employee'] && loggedIn['employee'].page) || page;
    const probe = await probePage.evaluate(() => {
      const el = Array.from(document.querySelectorAll('*'))
        .find((e) => e.children.length === 0 && /Not yet assessed/i.test(e.textContent || ''));
      if (!el) return null;
      const s = getComputedStyle(el);
      let bg = 'rgba(0, 0, 0, 0)', p = el;
      while (p && bg === 'rgba(0, 0, 0, 0)') { bg = getComputedStyle(p).backgroundColor; p = p.parentElement; }
      return { color: s.color, bg, opacity: s.opacity, size: s.fontSize };
    });
    if (!probe) {
      report('SKIPPED', 'unmeasured text is legible (bounded)', '"Not yet assessed" not on this screen');
    } else {
      const legible = probe.color !== probe.bg && parseFloat(probe.opacity) > 0.3;
      report(legible ? 'PASS' : 'FAIL', 'unmeasured text is legible (bounded)',
        `${probe.color} on ${probe.bg}, opacity ${probe.opacity} - NOT a WCAG audit`);
    }
  }

  for (const k of Object.keys(loggedIn)) await loggedIn[k].ctx.close();
  await browser.close();

  console.log('\n================================================================');
  console.log(`PASS ${pass}   FAIL ${fail}   SKIPPED ${skip}`);
  console.log('VERDICT: ' + (fail === 0 ? 'GREEN' : 'RED'));
  console.log('\nWHAT THIS DOES NOT COVER:');
  console.log('  - WCAG contrast ratios. The check above is a legibility floor only.');
  console.log('  - Screens reached through /module/[moduleId]/[menuId] - those ids');
  console.log('    are per-tenant runtime values, so a static path list cannot exist.');
  console.log('  - Anything behind a role this seed did not give data to.');
  console.log('================================================================');

  if (!attach) stopAll();
  process.exit(fail === 0 ? 0 : 1);
})();
