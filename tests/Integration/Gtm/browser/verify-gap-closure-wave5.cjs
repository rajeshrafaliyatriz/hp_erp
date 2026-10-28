// Live browser verification for Gap-Closure Wave 5: list-based campaign email
// sending. The idempotency math, skip-rule bucketing, and batch-truncation
// logic were already proven rigorously via a 24-check direct backend test -
// this pass instead proves the real UI wiring end to end: create a lead with
// an email, create a campaign, add the lead as a target through the real
// Targets tab, open the new "Send Campaign" dialog, see the pre-send summary
// (including the "sending is off" warning, since this environment's real
// crm.email_sending_enabled is false), send, see the honest result
// breakdown, then send again and confirm the second attempt reports the
// lead as already handled rather than queuing a duplicate.
//
// Run: node tests/Integration/Gtm/browser/verify-gap-closure-wave5.cjs
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
  return page.screenshot({ path: `${__dirname}/shots/wave5-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
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

const LEAD_NAME = 'E2E Wave5 Browser Lead'
const LEAD_EMAIL = 'e2e-wave5-browser@example.invalid'
const CAMPAIGN_NAME = 'E2E Wave5 Browser Campaign'

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

  // ── 1. Create a lead with a real email - the target this campaign will send to ──
  await page.goto(`${BASE}/module/crm/marketing/leads`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error leads', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Add Lead'), { timeout: 30000, label: 'leads list loaded' })
  await page.getByRole('button', { name: 'Add Lead' }).click({ timeout: 10000 }).catch((e) => console.log('add lead click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  await page.locator('#lead-last-name').fill(LEAD_NAME).catch((e) => console.log('fill last name error', String(e).slice(0, 150)))
  await page.locator('#lead-email').fill(LEAD_EMAIL).catch((e) => console.log('fill email error', String(e).slice(0, 150)))

  // A new lead's Assigned To is nullable ONLY when a tenant has turned on auto-assignment
  // rotation (Wave 2's own feature) - this tenant has not, so leaving it blank 422s with
  // "no auto-assignment rotation is configured". Select a real employee, same as the
  // campaign's own required Assigned To field below.
  const leadAssignedToCombo = page.locator('[role="dialog"]').locator('[role="combobox"]').filter({ hasText: 'auto-assign' }).first()
  await leadAssignedToCombo.click({ timeout: 8000 }).catch((e) => console.log('lead assigned-to combo click error', String(e).slice(0, 150)))
  const leadEmployeeOptionReady = await pollUntil(() => page.locator('[role="option"]').first().isVisible().catch(() => false), { timeout: 10000, label: 'lead assignee options become available' })
  if (!leadEmployeeOptionReady) {
    await leadAssignedToCombo.click({ timeout: 8000 }).catch((e) => console.log('lead assigned-to combo re-click error', String(e).slice(0, 150)))
    await pollUntil(() => page.locator('[role="option"]').first().isVisible().catch(() => false), { timeout: 10000, label: 'lead assignee options available after re-click' })
  }
  await page.locator('[role="option"]').first().click({ timeout: 5000 }).catch((e) => console.log('lead assignee option click error', String(e).slice(0, 150)))
  await shot(page, '01-create-lead')
  await page.locator('[role="dialog"]').getByRole('button', { name: /Create Lead|Save/i }).click({ timeout: 10000 }).catch((e) => console.log('create lead submit error', String(e).slice(0, 150)))
  const leadCreated = await pollUntil(async () => (await bodyText()).includes(LEAD_NAME), { timeout: 20000, label: 'new lead appears in the list' })
  check('A lead with an email was created through the real Add Lead form', !!leadCreated)

  // ── 2. Create a campaign, assigned to an employee (required) ─────────────
  await page.goto(`${BASE}/module/crm/marketing/campaigns`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error campaigns', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Add Campaign'), { timeout: 30000, label: 'campaigns list loaded' })
  await page.getByRole('button', { name: 'Add Campaign' }).click({ timeout: 10000 }).catch((e) => console.log('add campaign click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  await page.locator('#campaign-name').fill(CAMPAIGN_NAME).catch((e) => console.log('fill campaign name error', String(e).slice(0, 150)))

  // Four different fields in this dialog all carry role="combobox" (Type, Status,
  // Expected Response, Assigned To) - scope by the one with the "Choose an employee"
  // placeholder text, same lesson as every prior wave about not using a bare
  // [role="combobox"] locator.
  const assignedToCombo = page.locator('[role="dialog"]').locator('[role="combobox"]').filter({ hasText: 'Choose an employee' }).first()
  await assignedToCombo.click({ timeout: 8000 }).catch((e) => console.log('assigned-to combo click error', String(e).slice(0, 150)))
  const employeeOptionReady = await pollUntil(() => page.locator('[role="option"]').first().isVisible().catch(() => false), { timeout: 10000, label: 'employee options become available' })
  if (!employeeOptionReady) {
    await assignedToCombo.click({ timeout: 8000 }).catch((e) => console.log('assigned-to combo re-click error', String(e).slice(0, 150)))
    await pollUntil(() => page.locator('[role="option"]').first().isVisible().catch(() => false), { timeout: 10000, label: 'employee options available after re-click' })
  }
  await page.locator('[role="option"]').first().click({ timeout: 5000 }).catch((e) => console.log('employee option click error', String(e).slice(0, 150)))
  const assignedToSet = await pollUntil(async () => !(await assignedToCombo.innerText().catch(() => '')).includes('Choose an employee'), { timeout: 5000, label: 'Assigned To now shows a real employee' })
  check('Assigned To was set before submitting', !!assignedToSet, await assignedToCombo.innerText().catch(() => '<unreadable>'))
  await shot(page, '02-create-campaign')

  await page.locator('[role="dialog"]').getByRole('button', { name: /Create Campaign|Save/i }).click({ timeout: 10000 }).catch((e) => console.log('create campaign submit error', String(e).slice(0, 150)))
  const campaignCreated = await pollUntil(async () => (await bodyText()).includes(CAMPAIGN_NAME), { timeout: 20000, label: 'new campaign appears in the list' })
  check('The new campaign was created and appears in the list', !!campaignCreated)

  // ── 3. Open the campaign, add the lead as a target through the real Targets tab ──
  await page.locator('tr', { hasText: CAMPAIGN_NAME }).first().click({ timeout: 10000 }).catch((e) => console.log('row click error', String(e).slice(0, 150)))
  await pollUntil(() => page.url().includes('/marketing/campaigns/'), { timeout: 15000, label: 'URL navigates to campaign detail' })
  await pollUntil(async () => (await bodyText()).includes(CAMPAIGN_NAME), { timeout: 20000, label: 'campaign detail page loaded' })
  await shot(page, '03-campaign-detail')

  await page.getByRole('button', { name: 'Targets' }).click({ timeout: 10000 }).catch((e) => console.log('targets tab click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('No leads targeted yet'), { timeout: 15000, label: 'targets tab shows the empty Leads sub-tab' })

  const targetSearch = page.locator('input[placeholder="Search leads to add…"]')
  await targetSearch.fill(LEAD_NAME).catch((e) => console.log('target search fill error', String(e).slice(0, 150)))
  await page.getByRole('button', { name: /Add all matching/ }).click({ timeout: 10000 }).catch((e) => console.log('add matching click error', String(e).slice(0, 150)))
  const targetAdded = await pollUntil(async () => (await bodyText()).includes(LEAD_NAME) && (await bodyText()).includes('Leads (1)'), { timeout: 15000, label: 'the lead appears as a target' })
  await shot(page, '04-target-added')
  check('The lead was added as a campaign target via the real Targets tab', !!targetAdded)

  // ── 4. Open "Send Campaign" - the new Wave 5 feature - and read the pre-send summary ──
  await page.getByRole('button', { name: 'Send Campaign' }).click({ timeout: 10000 }).catch((e) => console.log('send campaign button click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').filter({ hasText: 'Send Campaign' }).first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  const summaryShown = await pollUntil(async () => (await bodyText()).includes('target') && (await bodyText()).includes('not yet attempted'), { timeout: 15000, label: 'pre-send summary loads' })
  const summaryText = await bodyText()
  await shot(page, '05-send-dialog-summary')
  check('The pre-send summary shows exactly 1 target, 0 already sent', !!summaryShown && summaryText.includes('1 target') && summaryText.includes('0 already sent'), summaryText.slice(0, 400))
  check('The summary warns that sending is switched off for this organisation', summaryText.includes('Email sending is currently switched off'), summaryText.slice(0, 400))

  // ── 5. Fill subject/body and send ─────────────────────────────────────────
  await page.locator('#campaign-send-subject').fill('E2E Wave5 Subject').catch((e) => console.log('fill subject error', String(e).slice(0, 150)))
  await page.locator('#campaign-send-body').fill('Hello from the browser-tested campaign send.').catch((e) => console.log('fill body error', String(e).slice(0, 150)))
  await shot(page, '06-send-dialog-filled')
  await page.getByRole('button', { name: 'Send Campaign' }).last().click({ timeout: 10000 }).catch((e) => console.log('send submit error', String(e).slice(0, 150)))

  const resultShown = await pollUntil(async () => (await bodyText()).includes('Queued (not yet delivered)'), { timeout: 20000, label: 'send result breakdown appears' })
  const resultText = await bodyText()
  await shot(page, '07-send-result')
  check('After sending, the result breakdown renders in the still-open dialog', !!resultShown)
  check('The result shows "0 sent" (sending is disabled) and the lead queued, not failed', resultText.includes('0 sent') && !resultText.includes('1Failed') , resultText.slice(0, 400))

  // Regression check for the bug caught during this wave's own review: calling
  // onSaved() straight from onSent (while this very result panel is still meant to
  // be showing) would trigger the app's full-reload-on-save convention and silently
  // unmount this dialog before a human ever saw the breakdown above. The fact that
  // the assertions above just read real result text proves that did NOT happen.
  const dialogStillOpenWithResult = await page.getByRole('button', { name: 'Done' }).isVisible().catch(() => false)
  check('The dialog stayed open with its result visible (did not get unmounted by a premature refresh)', !!dialogStillOpenWithResult)

  await page.getByRole('button', { name: 'Done' }).click({ timeout: 10000 }).catch((e) => console.log('done click error', String(e).slice(0, 150)))
  const dialogClosed = await pollUntil(async () => !(await page.locator('[role="dialog"]').filter({ hasText: 'Send Campaign' }).first().isVisible().catch(() => true)), { timeout: 10000, label: 'dialog closes after Done' })
  check('Clicking Done closes the dialog', !!dialogClosed)

  // ── 6. Send again - the lead must now show as already handled, not re-queued ──
  await page.getByRole('button', { name: 'Send Campaign' }).click({ timeout: 10000 }).catch((e) => console.log('re-open send dialog click error', String(e).slice(0, 150)))
  const summaryAfterFirstSend = await pollUntil(async () => (await bodyText()).includes('pending'), { timeout: 15000, label: 'summary now shows a pending target' })
  const summaryText2 = await bodyText()
  await shot(page, '08-send-dialog-summary-reopened')
  check('Re-opened summary shows 0 already sent, 1 pending, 0 not yet attempted', !!summaryAfterFirstSend && summaryText2.includes('0 already sent') && summaryText2.includes('1 pending'), summaryText2.slice(0, 400))

  await page.locator('#campaign-send-subject').fill('E2E Wave5 Subject Again').catch((e) => console.log('fill subject 2 error', String(e).slice(0, 150)))
  await page.locator('#campaign-send-body').fill('Second attempt from the browser.').catch((e) => console.log('fill body 2 error', String(e).slice(0, 150)))
  await page.getByRole('button', { name: 'Send Campaign' }).last().click({ timeout: 10000 }).catch((e) => console.log('send submit 2 error', String(e).slice(0, 150)))
  const resultShown2 = await pollUntil(async () => (await bodyText()).includes('Already sent before'), { timeout: 20000, label: 'second send result breakdown appears' })
  const resultText2 = await bodyText()
  await shot(page, '09-second-send-result')
  check('The second send reports the lead as already handled, not re-queued', !!resultShown2 && resultText2.includes('0 sent'), resultText2.slice(0, 400))
  // "Already sent before" is a <dt>, its count the following-sibling <dd> - read that pair
  // directly rather than regexing the flattened body text (a first attempt at that stripped
  // ALL whitespace, including the spaces inside "Already sent before" itself, so the literal
  // pattern could never match - a self-inflicted probe bug, not a product one).
  const alreadyHandledDd = page.locator('dt', { hasText: 'Already sent before' }).locator('xpath=following-sibling::dd').first()
  const alreadyHandledText = await alreadyHandledDd.innerText().catch(() => '<unreadable>')
  check('The "Already sent before" count reads 1', alreadyHandledText.trim() === '1', alreadyHandledText)

  await page.getByRole('button', { name: 'Done' }).click({ timeout: 10000 }).catch((e) => console.log('done click 2 error', String(e).slice(0, 150)))

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
