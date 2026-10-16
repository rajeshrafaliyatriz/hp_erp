// Live browser verification for Gap-Closure Wave 3: public web-to-lead
// capture. Deliberately uses a SECOND, genuinely anonymous browser context
// (no localStorage injection at all) for the actual public-form part, to
// prove the page truly works with zero authentication - not just that the
// backend accepts an unauthenticated request.
//
// Run: node tests/Integration/Gtm/browser/verify-gap-closure-wave3.cjs
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
  return page.screenshot({ path: `${__dirname}/shots/wave3-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
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
  const results = []
  const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? ' - ' + detail : ''}`) }

  // ── 1. Admin (authenticated context): configure and enable web-to-lead ──
  const adminCtx = await ctxFor(b, 'admin')
  const adminPage = await adminCtx.newPage()
  const adminLog = { console: [], pageerrors: [], api: [] }
  watch(adminPage, adminLog)
  const bodyText = async () => (await adminPage.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ')

  await adminPage.goto(`${BASE}/module/crm/master-fields`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error master-fields', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Master Fields'), { timeout: 30000, label: 'master fields page loaded' })
  await adminPage.getByRole('button', { name: 'Web-to-Lead' }).click({ timeout: 10000 }).catch((e) => console.log('tab click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Public lead capture form'), { timeout: 15000, label: 'web-to-lead tab visible' })
  await shot(adminPage, '01-web-to-lead-tab')

  // Try enabling with no default assignee yet - must be refused. The checkbox is a CHILD of
  // the <label>"Enabled"</label> element, not a sibling of its text node - a plain CSS
  // descendant selector matches the real markup, unlike an xpath preceding-sibling guess.
  const enableCheckbox = adminPage.locator('label:has-text("Enabled") input[type="checkbox"]').first()
  await adminPage.getByText('Enabled', { exact: true }).click({ timeout: 8000 }).catch((e) => console.log('enable-no-assignee click error', String(e).slice(0, 150)))
  const refusedNoAssignee = await pollUntil(async () => (await bodyText()).includes('Choose a default assignee'), { timeout: 10000, label: 'refused with no default assignee' })
  check('Enabling with no default assignee is refused', !!refusedNoAssignee)
  const stillUnchecked = !(await enableCheckbox.isChecked().catch(() => true))
  check('The Enabled checkbox stays unchecked after the refusal', stillUnchecked)

  // Set a default assignee - its own placeholder text uniquely identifies the combobox,
  // same safe approach as Wave 2's rotation-member picker (a bare [role="combobox"]
  // locator also matches the page's own global "Find a screen…" search box, so always
  // filter by distinguishing text rather than taking an unscoped .first()).
  const assigneeCombo = adminPage.locator('[role="combobox"]').filter({ hasText: 'Choose who owns web leads' }).first()
  await assigneeCombo.click({ timeout: 8000 }).catch((e) => console.log('assignee combo click error', String(e).slice(0, 150)))
  const firstOption = adminPage.locator('[role="option"]').first()
  const assigneeName = await firstOption.innerText().catch(() => '')
  await firstOption.click({ timeout: 5000 }).catch((e) => console.log('assignee option click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Settings updated') || (await bodyText()).includes(assigneeName.trim()), { timeout: 15000, label: 'default assignee saved' })
  await shot(adminPage, '02-default-assignee-set')

  // Now enable - should succeed.
  await adminPage.getByText('Enabled', { exact: true }).click({ timeout: 8000 }).catch((e) => console.log('enable click error', String(e).slice(0, 150)))
  const enabledOk = await pollUntil(async () => (await bodyText()).includes('Web-to-lead turned on'), { timeout: 15000, label: 'web-to-lead turned on confirmation' })
  check('Enabling succeeds once a default assignee is set', !!enabledOk)
  await shot(adminPage, '03-enabled-with-link')

  const linkInput = adminPage.locator('input[readonly]').first()
  const formUrl = await linkInput.inputValue().catch(() => '')
  check('A public form link is displayed', /\/public\/lead-capture\//.test(formUrl), formUrl)
  const publicKey = (formUrl.split('/lead-capture/')[1] || '').trim()
  check('A real-looking public key was extracted from the link', publicKey.length >= 30, publicKey)

  // ── 2. A GENUINELY ANONYMOUS browser context - no localStorage injection at all ──
  const anonCtx = await b.newContext({ viewport: { width: 1200, height: 900 } })
  const anonPage = await anonCtx.newPage()
  const anonLog = { console: [], pageerrors: [], api: [] }
  watch(anonPage, anonLog)
  const anonBodyText = async () => (await anonPage.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ')

  await anonPage.goto(`${BASE}/public/lead-capture/${publicKey}`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error public page', String(e).slice(0, 150)))
  const publicFormLoaded = await pollUntil(async () => (await anonBodyText()).includes('Get in touch'), { timeout: 20000, label: 'public form loads with zero auth' })
  await shot(anonPage, '04-anonymous-public-form')
  check('The public form loads in a context with NO stored session/token at all', !!publicFormLoaded)

  const storageCheck = await anonPage.evaluate(() => ({ userData: localStorage.getItem('userData'), cookies: document.cookie })).catch(() => null)
  check('Confirmed: this context genuinely has no userData in localStorage', storageCheck && storageCheck.userData === null, JSON.stringify(storageCheck))

  await anonPage.locator('#lc-first-name').fill('Wave3').catch((e) => console.log('fill first name error', String(e).slice(0, 150)))
  await anonPage.locator('#lc-last-name').fill('Public Verify').catch((e) => console.log('fill last name error', String(e).slice(0, 150)))
  await anonPage.locator('#lc-email').fill('e2e-wave3-public@example.invalid').catch((e) => console.log('fill email error', String(e).slice(0, 150)))
  await anonPage.locator('#lc-company').fill('E2E Public Co').catch((e) => console.log('fill company error', String(e).slice(0, 150)))
  await anonPage.locator('#lc-message').fill('Found you through the public form!').catch((e) => console.log('fill message error', String(e).slice(0, 150)))
  const honeypotValue = await anonPage.locator('#lc-website').inputValue().catch(() => '<unreadable>')
  check('The honeypot field is genuinely empty (a real visitor never fills it)', honeypotValue === '', honeypotValue)
  await shot(anonPage, '05-public-form-filled')

  await anonPage.getByRole('button', { name: 'Submit' }).click({ timeout: 10000 }).catch((e) => console.log('submit click error', String(e).slice(0, 150)))
  const submitted = await pollUntil(async () => (await anonBodyText()).includes('Thanks'), { timeout: RELOAD_TIMEOUT, label: 'thank-you message appears' })
  await shot(anonPage, '06-public-form-submitted')
  check('Submitting the public form shows a thank-you confirmation', !!submitted)

  // ── 3. Back in the authenticated context: confirm the lead actually landed ──
  await adminPage.goto(`${BASE}/module/crm/marketing/leads`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error leads', String(e).slice(0, 150)))
  const leadListed = await pollUntil(async () => (await bodyText()).includes('Public Verify'), { timeout: 20000, label: 'the public-submitted lead appears in the Leads list' })
  await shot(adminPage, '07-lead-in-list')
  check('The lead created via the public form appears in the (authenticated) Leads list', !!leadListed)

  if (leadListed) {
    const leadRow = adminPage.locator('tr', { hasText: 'Public Verify' }).first()
    await leadRow.click({ timeout: 10000 }).catch((e) => console.log('row click error', String(e).slice(0, 150)))
    await pollUntil(() => adminPage.url().includes('/marketing/leads/'), { timeout: 15000, label: 'URL navigates to lead detail' })
    await pollUntil(async () => (await bodyText()).includes('Lead Information'), { timeout: 20000, label: 'lead detail page loaded' })
    const detailText = await bodyText()
    await shot(adminPage, '08-lead-detail')
    check('Lead detail shows the company submitted anonymously ("E2E Public Co")', detailText.includes('E2E Public Co'))
    check(`Lead was assigned to the configured default assignee (${assigneeName.trim()})`, detailText.includes(assigneeName.trim()), assigneeName.trim())
  }

  console.log('\n=== admin context: console errors ===', JSON.stringify(adminLog.console.slice(0, 10)))
  console.log('=== admin context: non-2xx API calls ===', JSON.stringify(adminLog.api.filter((x) => !x.startsWith('2')).slice(0, 20)))
  console.log('=== anonymous context: console errors ===', JSON.stringify(anonLog.console.slice(0, 10)))
  console.log('=== anonymous context: page errors ===', JSON.stringify(anonLog.pageerrors.slice(0, 10)))
  console.log('=== anonymous context: non-2xx API calls ===', JSON.stringify(anonLog.api.filter((x) => !x.startsWith('2')).slice(0, 20)))

  const failed = results.filter((r) => !r.ok)
  console.log(`\n=== SUMMARY: ${results.length - failed.length}/${results.length} passed ===`)
  if (failed.length) console.log('FAILED:', JSON.stringify(failed, null, 2))

  await adminPage.close()
  await anonPage.close()
  await b.close()
  process.exit(failed.length ? 1 : 0)
})()
