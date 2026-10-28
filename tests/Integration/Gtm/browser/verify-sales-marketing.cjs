// Dev-only, one-off verification for the Sales & Marketing unification work.
// Logs in as admin (tenant 6, via lib.js's token-injection, no password),
// then drives the real browser through: sidebar structure, GTM deal ->
// Convert to CRM, Signals -> Promote to CRM Lead, the Organization's new
// cross-link tab, and a department's new Org Signals tab.
//
// Run: node tests/Integration/Gtm/browser/verify-sales-marketing.cjs
// Needs: both dev servers up (Laravel :8000, Next :3000), sessions.json
// present (php tests/Integration/Gtm/browser/mint.php > sessions.json).
//
// Self-contained (does not require ./lib.js): the repo's package.json has
// "type": "module", so a plain .js require() of that file fails the same
// way; copying its tiny helpers here avoids touching shared test infra.
const { chromium } = require('playwright-core')
const fs = require('fs')
const S = JSON.parse(fs.readFileSync(__dirname + '/sessions.json', 'utf8'))
const BASE = 'http://localhost:3001'
const launch = () => chromium.launch({ executablePath: process.env.USERPROFILE + '/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe', headless: true })
const ctxFor = async (browser, role) => {
  const s = S[role]
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const userData = { user_id: s.user_id, user_name: s.email, first_name: s.name.split(' ')[0], last_name: s.name.split(' ').slice(1).join(' '), user_email: s.email, user_image: null, user_profile_name: s.profile_name, user_profile_id: s.profile_id, role_key: s.role_key, sub_institute_id: s.sub_institute_id, birthdate: null, employee_no: null, org_name: 'E2E', org_id: 1, org_short_code: 'E2E', org_logo: null, org_type: 'school', year_title: null, syear: s.syear, start_date: null, end_date: null, org_user: null, token: s.token }
  const session = { user: { id: String(s.user_id), email: s.email, name: s.name, role: s.role_key, profileName: s.profile_name, profileId: String(s.profile_id), subInstituteId: String(s.sub_institute_id), syear: String(s.syear), initials: 'E2' }, isAuthenticated: true, isLoading: false }
  await ctx.addCookies([{ name: 'gtg-session', value: '1', url: BASE }])
  await ctx.addInitScript(([ud, ss]) => { try { localStorage.setItem('userData', ud); localStorage.setItem('gtg-session', ss); localStorage.setItem('gtg-remember', '1') } catch (e) {} }, [JSON.stringify(userData), JSON.stringify(session)])
  return ctx
}
const watch = (page, log) => {
  page.on('console', (m) => { if (m.type() === 'error') log.console.push(m.text().slice(0, 200)) })
  page.on('pageerror', (e) => log.pageerrors.push(String(e).slice(0, 200)))
  page.on('response', (r) => { const u = r.url(); if (u.includes('/api/')) { log.api.push(r.status() + ' ' + r.request().method() + ' ' + u.replace(/^https?:\/\/[^/]+/, '').replace(/[?&]token=[^&]*/, '').slice(0, 110)) } })
}

const DEAL_NAME = 'E2E Verify Deal'
const SIGNAL_TITLE = 'E2E Verify hiring surge'
const DEPARTMENT_ID = 87

function shot(page, name) {
  return page.screenshot({ path: `${__dirname}/shots/verify-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
}

// Polls fn() (sync or async) until it returns truthy, or the timeout elapses.
// Used in place of fixed waitForTimeout()s throughout - this dev server's
// first-compile + API round-trip times vary a lot, and a fixed delay either
// wastes time or (as happened repeatedly before this rewrite) checks the DOM
// before the real state change has rendered, reporting a false failure.
async function pollUntil(fn, { timeout = 25000, interval = 400, label = '' } = {}) {
  const start = Date.now()
  let last
  while (Date.now() - start < timeout) {
    try { last = await fn() } catch { last = undefined }
    if (last) return last
    await new Promise((r) => setTimeout(r, interval))
  }
  console.log(`pollUntil timed out: ${label}`)
  return last
}

;(async () => {
  const b = await launch()
  const ctx = await ctxFor(b, 'admin')
  const page = await ctx.newPage()
  const log = { console: [], pageerrors: [], api: [] }
  watch(page, log)
  page.on('dialog', (d) => d.accept())

  const results = []
  const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? ' - ' + detail : ''}`) }
  const bodyText = async () => (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ')

  // 1. Sidebar structure
  await page.goto(`${BASE}/gtm/deals`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error', String(e).slice(0, 150)))
  // Collapsed by default (pre-existing, unrelated UI default - see gtg-app-shell.tsx's
  // own comment on sidebarCollapsed); expand it once, the preference persists for later navs.
  await pollUntil(() => page.getByRole('button', { name: 'Expand sidebar' }).click({ timeout: 2000 }).then(() => true).catch(() => false), { timeout: 15000, label: 'expand sidebar button' })
  const sidebarOk = await pollUntil(async () => (await bodyText()).includes('Sales & Marketing'), { timeout: 20000, label: 'sidebar shows Sales & Marketing' })
  // "Sales & Marketing" is a collapsible tree item - its children (GTM Agents, Signals) are
  // only in the DOM once it's expanded. It's already active/open on a /gtm/* page per the
  // active-route highlighting, but click it explicitly so this check doesn't depend on that.
  await page.getByText('Sales & Marketing', { exact: true }).first().click({ timeout: 5000 }).catch(() => {})
  await pollUntil(async () => (await bodyText()).includes('GTM Agents'), { timeout: 10000, label: 'sidebar expands to show GTM Agents' })
  await shot(page, '01-gtm-deals-page')
  const sidebarText = await bodyText()
  check('Sidebar shows "Sales & Marketing"', !!sidebarOk)
  check('Sidebar shows "GTM Agents"', sidebarText.includes('GTM Agents'))
  check('Sidebar shows "Signals" branch', sidebarText.includes('Signals'))

  // 2. Open the deal, move to won if needed, convert to CRM
  // The board hides won/lost deals by default - tick "Include won / lost" first since this
  // deal may already be won from a previous run of this script.
  await page.getByText('Include won / lost', { exact: false }).click({ timeout: 10000 }).catch((e) => console.log('include won/lost checkbox error', String(e).slice(0, 150)))
  const dealCard = page.getByText(DEAL_NAME, { exact: false }).first()
  await dealCard.waitFor({ state: 'visible', timeout: 30000 }).catch((e) => console.log('deal wait error', String(e).slice(0, 150)))
  await dealCard.click({ timeout: 15000 }).catch((e) => console.log('deal click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
  await shot(page, '02-deal-dialog-open')

  const stageSelect = page.locator('select').first()
  const currentStage = await pollUntil(() => stageSelect.inputValue().catch(() => null), { timeout: 10000, label: 'stage select has a value' })
  console.log('current deal stage:', currentStage)
  if (currentStage !== 'won') {
    await stageSelect.selectOption('won').catch((e) => console.log('select won error', String(e).slice(0, 150)))
    await pollUntil(() => stageSelect.inputValue().then((v) => v === 'won').catch(() => false), { timeout: 15000, label: 'stage becomes won' })
  }
  await shot(page, '03-deal-won')

  const convertBtn = page.getByRole('button', { name: /Convert to CRM Opportunity/i })
  const convertVisible = await pollUntil(() => convertBtn.isVisible().catch(() => false), { timeout: 15000, label: 'Convert to CRM Opportunity button visible' })
  check('Convert to CRM Opportunity button visible once won', !!convertVisible)
  if (convertVisible) {
    await convertBtn.click().catch((e) => console.log('convert click error', String(e).slice(0, 150)))
  }
  const convertedOk = await pollUntil(async () => (await page.locator('[role="dialog"]').first().innerText().catch(() => '')).includes('Converted to CRM'), { timeout: 20000, label: 'dialog shows Converted to CRM' })
  await shot(page, '04-after-convert-click')
  const dealDialogText = (await page.locator('[role="dialog"]').first().innerText().catch(() => '')).replace(/\s+/g, ' ')
  check('Deal dialog shows "Converted to CRM" after click', !!convertedOk, dealDialogText.slice(0, 250))

  // Capture the CRM organization id from the "View Organization" link
  const orgHref = await page.locator('a:has-text("View Organization")').first().getAttribute('href').catch(() => null)
  console.log('org link href:', orgHref)
  const orgId = orgHref ? orgHref.split('/').pop() : null
  check('Captured a CRM organization id from the link', !!orgId, String(orgId))

  await page.keyboard.press('Escape').catch(() => {})
  await page.waitForTimeout(300)

  // 3. Signals -> Promote to CRM Lead
  await page.goto(`${BASE}/signals`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error signals', String(e).slice(0, 150)))
  const signalFound = await pollUntil(async () => (await bodyText()).includes(SIGNAL_TITLE), { timeout: 30000, label: 'signal feed shows test signal' })
  await shot(page, '05-signals-feed')
  check('Signals feed mentions the test signal', !!signalFound)

  // Scope to the card containing our specific test signal (its outer div carries
  // "rounded-2xl", a distinguishing class on that one container - see signals-feed-view.tsx),
  // not just "first Promote button on the page".
  const signalCard = page.locator('div.rounded-2xl').filter({ hasText: SIGNAL_TITLE }).first()
  const promoteBtn = signalCard.getByRole('button', { name: /Promote to CRM Lead/i })
  const promoteVisible = await pollUntil(() => promoteBtn.isVisible().catch(() => false), { timeout: 10000, label: 'Promote to CRM Lead button visible on test signal card' })
  check('A "Promote to CRM Lead" button is visible on the test signal', !!promoteVisible)
  if (promoteVisible) {
    await promoteBtn.click().catch((e) => console.log('promote click error', String(e).slice(0, 150)))
  }
  const promotedOk = await pollUntil(async () => (await signalCard.innerText().catch(() => '')).includes('Promoted to CRM'), { timeout: 15000, label: 'signal card shows Promoted to CRM' })
  await shot(page, '06-after-promote-click')
  check('Shows "Promoted to CRM" after click', !!promotedOk)

  // 4. CRM organization cross-link tab
  if (orgId) {
    await page.goto(`${BASE}/module/crm/marketing/organizations/${orgId}`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error org', String(e).slice(0, 150)))
    const crossTab = page.getByRole('button', { name: /GTM & Signals/i }).or(page.getByText('GTM & Signals', { exact: false }))
    await pollUntil(() => crossTab.first().isVisible().catch(() => false), { timeout: 20000, label: 'GTM & Signals tab visible' })
    await shot(page, '07-org-detail-page')
    await crossTab.first().click({ timeout: 10000 }).catch((e) => console.log('cross tab click error', String(e).slice(0, 150)))
    const crossLinkOk = await pollUntil(async () => { const t = await bodyText(); return t.includes('E2E Verify Co') || t.includes('Also in GTM') }, { timeout: 15000, label: 'cross-link tab shows GTM account' })
    await shot(page, '08-org-cross-links-tab')
    const crossLinkText = await bodyText()
    check('Cross-link tab shows the linked GTM account', !!crossLinkOk, crossLinkText.slice(0, 350))
  } else {
    check('Cross-link tab check skipped (no org id captured)', false)
  }

  // 5. Department detail -> Org Signals tab
  await page.goto(`${BASE}/organization/departments/${DEPARTMENT_ID}`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error dept', String(e).slice(0, 150)))
  const orgSignalsTab = page.getByRole('button', { name: /Org Signals/i }).or(page.getByText('Org Signals', { exact: false }))
  await pollUntil(() => orgSignalsTab.first().isVisible().catch(() => false), { timeout: 20000, label: 'Org Signals tab visible' })
  await shot(page, '09-department-detail')
  await orgSignalsTab.first().click({ timeout: 10000 }).catch((e) => console.log('org signals tab click error', String(e).slice(0, 150)))
  const deptSignalOk = await pollUntil(async () => (await bodyText()).includes('E2E Verify staffing gap'), { timeout: 20000, label: 'department tab shows test org signal' })
  await shot(page, '10-department-org-signals-tab')
  const deptSignalsText = await bodyText()
  check('Org Signals tab shows the department-scoped signal', !!deptSignalOk, deptSignalsText.slice(0, 350))
  check('Org Signals tab does NOT show the old org-wide opportunities feed', !deptSignalsText.includes('Company opportunities') && !deptSignalsText.includes('Match Offers'))

  console.log('\n=== console errors ===', JSON.stringify(log.console.slice(0, 10)))
  console.log('=== page errors ===', JSON.stringify(log.pageerrors.slice(0, 10)))
  console.log('=== non-2xx API calls ===', JSON.stringify(log.api.filter((x) => !x.startsWith('2')).slice(0, 20)))

  const failed = results.filter((r) => !r.ok)
  console.log(`\n=== SUMMARY: ${results.length - failed.length}/${results.length} passed ===`)
  if (failed.length) console.log('FAILED:', JSON.stringify(failed, null, 2))

  await page.close()
  await b.close()
  process.exit(failed.length ? 1 : 0)
})()
