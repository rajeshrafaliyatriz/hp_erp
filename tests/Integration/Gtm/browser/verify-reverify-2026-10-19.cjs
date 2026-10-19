// Deep re-verification audit (2026-10-19): screenshot capture for the 6 screens the
// UX/terminology sweep flagged as needing direct human visual review (a static code
// read can't fully settle whether these "read as one coherent system"). This script
// takes no action beyond navigating and screenshotting - no fixtures, nothing to clean up.
//
// Run: node tests/Integration/Gtm/browser/verify-reverify-2026-10-19.cjs
const { chromium } = require('playwright-core')
const fs = require('fs')
const S = JSON.parse(fs.readFileSync(__dirname + '/sessions.json', 'utf8'))
const BASE = process.env.BASE_URL || 'http://localhost:3000'
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
function shot(page, name) {
  return page.screenshot({ path: `${__dirname}/shots/reverify-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
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

;(async () => {
  const b = await launch()
  const ctx = await ctxFor(b, 'admin')
  const page = await ctx.newPage()
  const bodyText = async () => (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ')

  // 1. Sidebar expanded: Sales & Marketing -> GTM Agents and -> Signals
  // /module/crm is a route PREFIX, not a real page (confirmed via `next build`'s own route
  // list - every real page nests at least one level deeper) - land on a genuine page instead.
  await page.goto(`${BASE}/module/crm/sales/opportunities`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error crm root', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Sales & Marketing') || (await bodyText()).includes('Opportunities'), { timeout: 30000, label: 'crm root loaded' })
  const salesMarketing = page.getByText('Sales & Marketing', { exact: false }).first()
  await salesMarketing.click({ timeout: 10000 }).catch((e) => console.log('sales&marketing click error', String(e).slice(0, 150)))
  const gtmAgentsItem = page.getByText('GTM Agents', { exact: false }).first()
  await gtmAgentsItem.click({ timeout: 8000 }).catch((e) => console.log('gtm agents expand click error', String(e).slice(0, 150)))
  await page.waitForTimeout(500)
  await shot(page, '01-sidebar-gtm-expanded')
  const signalsItem = page.getByText('Signals', { exact: true }).first()
  await signalsItem.click({ timeout: 8000 }).catch((e) => console.log('signals expand click error', String(e).slice(0, 150)))
  await page.waitForTimeout(500)
  await shot(page, '01b-sidebar-signals-expanded')
  console.log('shot 1 done')

  // 2. CRM -> GTM navigation transition (breadcrumb disappearance check)
  // Navigate directly to a known-real opportunity id rather than relying on "click the
  // first row" (fragile if the list is empty/paginated/sorted unpredictably).
  await page.goto(`${BASE}/module/crm/sales/opportunities/18`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error opportunity detail', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).length > 200, { timeout: 30000, label: 'opportunity detail loaded' })
  await shot(page, '02a-crm-opportunity-detail-breadcrumb')
  await page.goto(`${BASE}/gtm/command-center`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error gtm command-center', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).length > 100, { timeout: 30000, label: 'gtm command center loaded' })
  await shot(page, '02b-gtm-command-center-no-breadcrumb')
  console.log('shot 2 done')

  // 3. Department Detail "Org Signals" tab, empty state + generate button
  await page.goto(`${BASE}/organization/departments/87`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error department detail', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).length > 200, { timeout: 30000, label: 'department detail loaded' })
  const orgSignalsTab = page.getByText('Org Signals', { exact: false }).first()
  await orgSignalsTab.click({ timeout: 8000 }).catch((e) => console.log('org signals tab click error', String(e).slice(0, 150)))
  await page.waitForTimeout(800)
  await shot(page, '03-department-org-signals-tab')
  console.log('shot 3 done')

  // 4. /portfolio page - distinct visual skin check
  await page.goto(`${BASE}/portfolio`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error portfolio', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).length > 100, { timeout: 30000, label: 'portfolio loaded' })
  await shot(page, '04-portfolio-premium-skin')
  console.log('shot 4 done')

  // 5. /gtm/command-center at density (re-shot standalone, already have from step 2 but captured fresh focus here)
  await shot(page, '05-gtm-command-center-density')

  // 6. /signals page - product switcher
  await page.goto(`${BASE}/signals`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error signals', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).length > 100, { timeout: 30000, label: 'signals page loaded' })
  await shot(page, '06-signals-product-switcher')
  console.log('shot 6 done')

  console.log('\n=== all screenshots captured ===')
  await page.close()
  await b.close()
  process.exit(0)
})()
