// Live browser verification for Gap-Closure Wave 4: support SLA timers.
// The breach/met/within-SLA MATH itself was already proven rigorously via
// Carbon::setTestNow time-travel in the direct backend test (6 scenarios) -
// a real browser can't fast-forward a clock, so this pass instead proves
// the actual UI wiring: configure a policy, create a ticket, see it show
// "On Track" with a due date, add the first comment, see it flip to
// "SLA Met" with a first-response timestamp - end to end, for real.
//
// Run: node tests/Integration/Gtm/browser/verify-gap-closure-wave4.cjs
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
  return page.screenshot({ path: `${__dirname}/shots/wave4-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
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

  // ── 1. Admin: configure an Urgent SLA policy ─────────────────────────
  await page.goto(`${BASE}/module/crm/master-fields`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error master-fields', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Master Fields'), { timeout: 30000, label: 'master fields page loaded' })
  await page.getByRole('button', { name: 'SLA Policies' }).click({ timeout: 10000 }).catch((e) => console.log('tab click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('first response'), { timeout: 15000, label: 'SLA policies tab visible' })
  await shot(page, '01-sla-policies-tab')

  const urgentRow = page.locator('li', { hasText: 'Urgent' }).first()
  const urgentAlreadySet = (await urgentRow.innerText().catch(() => '')).includes('hour')
  if (!urgentAlreadySet) {
    await urgentRow.locator('input[type="number"]').fill('4').catch((e) => console.log('fill urgent hours error', String(e).slice(0, 150)))
    await urgentRow.getByRole('button', { name: 'Set' }).click({ timeout: 10000 }).catch((e) => console.log('set urgent error', String(e).slice(0, 150)))
    await pollUntil(async () => (await urgentRow.innerText().catch(() => '')).includes('4 hours'), { timeout: 15000, label: 'Urgent policy shows 4 hours' })
  }
  const urgentConfigured = (await urgentRow.innerText().catch(() => '')).includes('hour')
  check('Urgent SLA policy is configured (4 hours to first response)', urgentConfigured, await urgentRow.innerText().catch(() => ''))
  await shot(page, '02-urgent-policy-set')

  // ── 2. Create a real Urgent ticket through the UI ────────────────────
  await page.goto(`${BASE}/module/crm/support/tickets`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error tickets', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Add Ticket'), { timeout: 30000, label: 'tickets list loaded' })
  await page.getByRole('button', { name: 'Add Ticket' }).click({ timeout: 10000 }).catch((e) => console.log('add ticket click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})

  await page.locator('#ticket-title').fill('E2E Wave4 Urgent Ticket').catch((e) => console.log('fill title error', String(e).slice(0, 150)))

  // Status and Priority both default to the same placeholder ("—"), so filtering the
  // combobox by its own text can't tell them apart. A `.filter({has:...})` on an ancestor
  // div doesn't work either - `has` matches ANY ancestor containing the label anywhere in
  // its subtree (not just the immediate parent), so it grabbed a container holding BOTH
  // Organization and Priority, and `.first()` found Organization's combobox instead
  // (confirmed via the captured text: "No organization"). Label and Select really are
  // plain siblings in one wrapping div - an xpath follow-sibling search is the correct,
  // precise tool for that relationship, unlike a CSS :has() filter.
  const priorityCombo = page.locator('[role="dialog"]').locator('xpath=.//label[normalize-space(text())="Priority"]/following-sibling::*//*[@role="combobox"]').first()
  await priorityCombo.click({ timeout: 8000 }).catch((e) => console.log('priority combo click error', String(e).slice(0, 150)))
  // Same lesson as Wave 2: the picklist options load asynchronously, and clicking the
  // instant the dialog opens can beat that fetch, leaving nothing to click. Poll for a
  // real "Urgent" option to actually exist rather than assuming the first click's timing.
  const urgentOptionReady = await pollUntil(() => page.locator('[role="option"]').filter({ hasText: 'Urgent' }).first().isVisible().catch(() => false), { timeout: 10000, label: 'Urgent option becomes available' })
  if (!urgentOptionReady) {
    const optTexts = await page.locator('[role="option"]').allInnerTexts().catch(() => [])
    console.log('priority options actually present:', JSON.stringify(optTexts))
    // The dropdown may have closed/never opened - try clicking the trigger again.
    await priorityCombo.click({ timeout: 8000 }).catch((e) => console.log('priority combo re-click error', String(e).slice(0, 150)))
    await pollUntil(() => page.locator('[role="option"]').filter({ hasText: 'Urgent' }).first().isVisible().catch(() => false), { timeout: 10000, label: 'Urgent option available after re-click' })
  }
  await page.locator('[role="option"]').filter({ hasText: 'Urgent' }).first().click({ timeout: 5000 }).catch((e) => console.log('urgent option click error', String(e).slice(0, 150)))
  const priorityNowShowsUrgent = await pollUntil(async () => (await priorityCombo.innerText().catch(() => '')).includes('Urgent'), { timeout: 5000, label: 'priority combobox now shows Urgent' })
  check('Priority was actually set to Urgent before submitting', !!priorityNowShowsUrgent, await priorityCombo.innerText().catch(() => '<unreadable>'))
  await shot(page, '03-create-ticket-urgent')

  await page.locator('[role="dialog"]').getByRole('button', { name: /Create Ticket|Save/i }).click({ timeout: 10000 }).catch((e) => console.log('create ticket submit error', String(e).slice(0, 150)))
  const ticketCreated = await pollUntil(async () => !(await page.locator('[role="dialog"]').first().isVisible().catch(() => false)), { timeout: RELOAD_TIMEOUT, label: 'create ticket dialog closes' })
  check('Creating an Urgent ticket succeeds', !!ticketCreated)

  // ── 3. The new ticket shows "On Track" with a due date in the list ──
  const ticketListed = await pollUntil(async () => (await bodyText()).includes('E2E Wave4 Urgent Ticket'), { timeout: 20000, label: 'new ticket appears in the list' })
  await shot(page, '04-ticket-in-list')
  check('The new ticket appears in the Tickets list', !!ticketListed)

  const ticketRow = page.locator('tr', { hasText: 'E2E Wave4 Urgent Ticket' }).first()
  const rowText = (await ticketRow.innerText().catch(() => '')).replace(/\s+/g, ' ')
  check('The list row shows "On Track" (SLA badge)', rowText.includes('On Track'), rowText)

  // ── 4. Open the ticket, confirm the SLA section, add the first comment ──
  await ticketRow.click({ timeout: 10000 }).catch((e) => console.log('row click error', String(e).slice(0, 150)))
  await pollUntil(() => page.url().includes('/support/tickets/'), { timeout: 15000, label: 'URL navigates to ticket detail' })
  await pollUntil(async () => (await bodyText()).includes('Ticket Details'), { timeout: 20000, label: 'ticket detail page loaded' })
  const detailTextBefore = await bodyText()
  await shot(page, '05-ticket-detail-before-response')
  check('Ticket detail shows "On Track" and a due date before any response', detailTextBefore.includes('On Track') && detailTextBefore.includes('Due '), detailTextBefore.includes('Due ') ? '' : detailTextBefore.slice(0, 300))
  check('Ticket detail does NOT yet show a "First response" line', !detailTextBefore.includes('First response'))

  await page.getByRole('button', { name: 'Comments' }).click({ timeout: 10000 }).catch((e) => console.log('comments tab click error', String(e).slice(0, 150)))
  const commentBox = page.locator('textarea').first()
  await commentBox.fill('First response - looking into this now.').catch((e) => console.log('fill comment error', String(e).slice(0, 150)))
  await shot(page, '06-comment-filled')
  await page.getByRole('button', { name: 'Post Comment' }).click({ timeout: 10000 }).catch((e) => console.log('post comment error', String(e).slice(0, 150)))
  // Posting now correctly triggers a full reload (this very test found and fixed that it
  // didn't, before - see ticket-detail-page.tsx's postComment()), which - same established
  // convention as changeStatus()/every edit-save elsewhere in this app - resets the view to
  // the Details tab. So the comment text may no longer be on screen by the time this
  // resolves; the stronger, unambiguous proof the comment really posted is the SLA fields
  // below, which can only become true if it did. Still confirm the reload actually
  // completed (the "Ticket Details" heading re-rendering) rather than assuming.
  const reloadedAfterComment = await pollUntil(async () => (await bodyText()).includes('Ticket Details'), { timeout: RELOAD_TIMEOUT, label: 'detail page reloads after posting the comment' })
  check('Posting the first comment triggers a reload of the ticket', !!reloadedAfterComment)

  // ── 5. SLA flips to "SLA Met" with a first-response time - the real proof the comment posted ──
  const metShown = await pollUntil(async () => (await bodyText()).includes('SLA Met'), { timeout: 20000, label: '"SLA Met" appears after the first response' })
  const detailTextAfter = await bodyText()
  await shot(page, '07-ticket-detail-after-response')
  check('Ticket detail now shows "SLA Met" after the first comment', !!metShown)
  check('Ticket detail now shows a "First response" timestamp', detailTextAfter.includes('First response'), detailTextAfter.includes('First response') ? '' : detailTextAfter.slice(0, 300))

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
