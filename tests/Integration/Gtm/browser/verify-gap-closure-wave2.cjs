// Live browser verification for Gap-Closure Wave 2: lead scoring rules,
// round-robin assignment, and their effect on a real lead created through
// the UI. Self-contained, same shape as the Wave 1 script.
//
// Run: node tests/Integration/Gtm/browser/verify-gap-closure-wave2.cjs
const { chromium } = require('playwright-core')
const fs = require('fs')
const S = JSON.parse(fs.readFileSync(__dirname + '/sessions.json', 'utf8'))
const BASE = 'http://localhost:3000'
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
  page.on('response', (r) => { const u = r.url(); if (u.includes('/api/')) { log.api.push(r.status() + ' ' + r.request().method() + ' ' + u.replace(/^https?:\/\/[^/]+/, '').replace(/[?&]token=[^&]*/, '').slice(0, 130)) } })
}
function shot(page, name) {
  return page.screenshot({ path: `${__dirname}/shots/wave2-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
}
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

const COUNTRY_VALUE = 'E2EWaveTwoland'
const RELOAD_TIMEOUT = 40000

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
  const dialogText = async () => (await page.locator('[role="dialog"]').first().innerText().catch(() => '')).replace(/\s+/g, ' ')

  // ── 1. Master Fields -> Lead Scoring & Assignment: add a rule ───────────
  await page.goto(`${BASE}/module/crm/master-fields`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error master-fields', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Master Fields'), { timeout: 30000, label: 'master fields page loaded' })
  await page.getByRole('button', { name: 'Lead Scoring & Assignment' }).click({ timeout: 10000 }).catch((e) => console.log('tab click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Lead Scoring'), { timeout: 15000, label: 'lead routing tab visible' })
  await shot(page, '01-master-fields-tab')

  // Wait for the rules section's own async load() to finish (its field/operator options
  // come from the API response) before opening the form - clicking "Add rule" the instant
  // the static heading renders means the field picker has nothing to show yet.
  await pollUntil(async () => !(await bodyText()).includes('Loading scoring rules'), { timeout: 15000, label: 'scoring rules section finished loading' })
  await page.getByRole('button', { name: 'Add rule' }).click({ timeout: 10000 }).catch((e) => console.log('add rule click error', String(e).slice(0, 150)))
  // The field picker's default selection is whichever field LeadScorer::ALLOWED_FIELDS lists
  // first (lead_source, not industry) - don't assume its starting label. A plain
  // `[role="combobox"]` locator isn't enough either: the page's own global "Find a screen…"
  // search box also carries role="combobox" and sits earlier in the DOM, so `.first()` was
  // silently focusing THAT instead (confirmed via a screenshot - the search bar lit up with
  // a focus ring, zero options ever rendered). Scope explicitly to the Lead Scoring section.
  const fieldCombo = page.locator('h2:has-text("Lead Scoring") + div [role="combobox"]').first()
  await fieldCombo.waitFor({ state: 'visible', timeout: 8000 }).catch((e) => console.log('field combo wait error', String(e).slice(0, 150)))
  await fieldCombo.click({ timeout: 8000 }).catch((e) => console.log('field combo click error', String(e).slice(0, 150)))
  await page.waitForTimeout(300)
  await shot(page, '01b-field-combo-opened')
  const optionCount = await page.locator('[role="option"]').count()
  const optionTexts = await page.locator('[role="option"]').allInnerTexts()
  console.log('option count:', optionCount, 'texts:', JSON.stringify(optionTexts))
  await page.locator('[role="option"]').filter({ hasText: 'Country' }).first().click({ timeout: 5000 }).catch((e) => console.log('country option click error', String(e).slice(0, 150)))
  const valueInput = page.locator('input[placeholder="e.g. Technology"]').first()
  await valueInput.fill(COUNTRY_VALUE).catch((e) => console.log('value fill error', String(e).slice(0, 150)))
  // The points input defaults to "10" - target it by that value rather than by type, since
  // the value field above is also type="number" once a numeric-only operator is selected.
  const pointsInput = page.locator('input[value="10"]').first()
  await pointsInput.fill('40').catch((e) => console.log('points fill error', String(e).slice(0, 150)))
  await shot(page, '02-add-rule-form-filled')
  // Two "Add" buttons exist on this page at once (this rule form's submit, and the
  // Assignment section's "add a person" submit, further down the same tab) - scope to
  // the rule form specifically, the first "Add" button in DOM order.
  await page.getByRole('button', { name: 'Add' }).first().click({ timeout: 10000 }).catch((e) => console.log('add submit error', String(e).slice(0, 150)))
  const ruleAdded = await pollUntil(async () => (await bodyText()).includes(`If Country is "${COUNTRY_VALUE}"`), { timeout: 15000, label: 'rule appears in the list' })
  await shot(page, '03-rule-added')
  check('Scoring rule "Country is E2EWaveTwoland -> +40" appears in the list', !!ruleAdded)

  // ── 2. Assignment rotation: add the admin user, enable it ───────────────
  const addPersonCombo = page.locator('[role="combobox"]').filter({ hasText: 'Add a person' }).first()
  await addPersonCombo.click({ timeout: 8000 }).catch((e) => console.log('add person combo click error', String(e).slice(0, 150)))
  const firstPersonOption = page.locator('[role="option"]').first()
  const firstPersonName = await firstPersonOption.innerText().catch(() => '')
  await firstPersonOption.click({ timeout: 5000 }).catch((e) => console.log('person option click error', String(e).slice(0, 150)))
  // The rule form is closed again by now (it collapses on its own successful submit), so the
  // one remaining "Add" button on the page is this one - no scoping needed, but staying
  // explicit about which one for the same reason as above.
  await page.getByRole('button', { name: 'Add' }).last().click({ timeout: 10000 }).catch((e) => console.log('add member submit error', String(e).slice(0, 150)))
  const memberAdded = await pollUntil(async () => (await bodyText()).includes(firstPersonName.trim()), { timeout: 15000, label: 'rotation member appears' })
  check('Rotation member added to the list', !!memberAdded, firstPersonName.trim())

  // Click the label text, not .check() on the input directly - Playwright's own
  // post-click state assertion races this component's controlled re-render; the real
  // outcome is verified properly below via the actual confirmation message.
  await page.getByText('Enabled', { exact: true }).click({ timeout: 8000 }).catch((e) => console.log('enable toggle error', String(e).slice(0, 150)))
  const enabledOk = await pollUntil(async () => (await bodyText()).includes('Auto-assignment turned on'), { timeout: 15000, label: 'auto-assignment turned on confirmation' })
  await shot(page, '04-rotation-enabled')
  check('Turning on auto-assignment succeeds', !!enabledOk)

  // ── 3. Create a real lead through the UI, matching the rule, no assignee ─
  await page.goto(`${BASE}/module/crm/marketing/leads`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error leads', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Add Lead'), { timeout: 30000, label: 'leads list loaded' })
  await page.getByRole('button', { name: 'Add Lead' }).click({ timeout: 10000 }).catch((e) => console.log('add lead click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  await page.locator('#lead-last-name').fill('E2E Wave2 Browser Lead').catch((e) => console.log('fill last name error', String(e).slice(0, 150)))
  await page.locator('#lead-country').fill(COUNTRY_VALUE).catch((e) => console.log('fill country error', String(e).slice(0, 150)))
  const assignedToPlaceholder = await page.locator('[role="dialog"] [role="combobox"]').filter({ hasText: 'auto-assign' }).count()
  check('Assigned To shows the "leave blank to auto-assign" placeholder on a new lead', assignedToPlaceholder > 0, String(assignedToPlaceholder))
  await shot(page, '05-add-lead-filled')
  await page.locator('[role="dialog"]').getByRole('button', { name: 'Create Lead' }).click({ timeout: 10000 }).catch((e) => console.log('create lead submit error', String(e).slice(0, 150)))
  const leadCreated = await pollUntil(async () => !(await page.locator('[role="dialog"]').first().isVisible().catch(() => false)), { timeout: RELOAD_TIMEOUT, label: 'create lead dialog closes' })
  if (!leadCreated) console.log('dialog text at timeout:', (await dialogText()).slice(0, 300))
  check('Creating a lead with no assignee (auto-assign rotation on) succeeds', !!leadCreated)

  // ── 4. Confirm the score and auto-assignment landed correctly ───────────
  const rowFound = await pollUntil(async () => (await bodyText()).includes('E2E Wave2 Browser Lead'), { timeout: 20000, label: 'new lead appears in the list' })
  await shot(page, '06-leads-list-with-score')
  check('New lead appears in the Leads list', !!rowFound);

  const leadRow = page.locator('tr', { hasText: 'E2E Wave2 Browser Lead' }).first();
  const rowText = await leadRow.innerText().catch(() => '');
  check('Leads list row shows a Score of 40', /\b40\b/.test(rowText), rowText.replace(/\s+/g, ' ').slice(0, 200));

  await leadRow.click({ timeout: 10000 }).catch((e) => console.log('row click error', String(e).slice(0, 150)))
  // The list page's own row already contains the lead's name, so that text alone is not
  // proof navigation happened (an earlier version of this check passed vacuously while
  // still sitting on the list page) - wait for both the URL to actually change AND a
  // detail-page-only heading that never appears on the list.
  await pollUntil(() => page.url().includes('/marketing/leads/'), { timeout: 15000, label: 'URL navigates to the lead detail route' })
  await pollUntil(async () => (await bodyText()).includes('Lead Information'), { timeout: 20000, label: 'lead detail page loaded (Lead Information section)' })
  const detailText = await bodyText()
  await shot(page, '07-lead-detail-score')
  check('Lead detail page shows "40 pts"', detailText.includes('40 pts'), detailText.includes('40 pts') ? '' : detailText.slice(0, 400))
  check('Lead detail page explains the score via the Country rule', detailText.includes('Country is') && detailText.includes(COUNTRY_VALUE), '')
  check(`Lead was auto-assigned to ${firstPersonName.trim() || '(unknown)'}`, detailText.includes(firstPersonName.trim()), firstPersonName.trim())

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
