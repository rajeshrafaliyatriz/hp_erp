// Live browser verification for Gap-Closure Wave 1 (order-to-cash completion):
// Opportunity win/loss reason, Invoice PDF download, Invoice "Email Customer",
// Invoice payment tracking (Payments tab, Record Payment, Paid/Balance Due/
// payment-status badges). Self-contained, same shape as verify-sales-marketing.cjs.
//
// Run: node tests/Integration/Gtm/browser/verify-gap-closure-wave1.cjs <oppId> <invoiceId>
// Needs: both dev servers up (Laravel :8000, Next :3000), sessions.json present.
const { chromium } = require('playwright-core')
const fs = require('fs')
const S = JSON.parse(fs.readFileSync(__dirname + '/sessions.json', 'utf8'))
const BASE = 'http://localhost:3000'
const OPP_ID = process.argv[2]
const INVOICE_ID = process.argv[3]
const launch = () => chromium.launch({ executablePath: process.env.USERPROFILE + '/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe', headless: true })
const ctxFor = async (browser, role) => {
  const s = S[role]
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true })
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
  return page.screenshot({ path: `${__dirname}/shots/wave1-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
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
  if (!OPP_ID || !INVOICE_ID) { console.log('usage: verify-gap-closure-wave1.cjs <oppId> <invoiceId>'); process.exit(2) }
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
  // Full reloads (onSaved() -> isLoading=true -> the whole detail component unmounts while
  // it refetches, then remounts) can take well over 10s on a cold/first-visit dev-compile -
  // give those a generous timeout, same spirit as the sidebar/first-nav waits in
  // verify-sales-marketing.cjs.
  const RELOAD_TIMEOUT = 40000

  // ── 1. Opportunity win/loss reason ───────────────────────────────────
  await page.goto(`${BASE}/module/crm/sales/opportunities/${OPP_ID}`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error opp', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('E2E Wave1 Verify Opp'), { timeout: 30000, label: 'opportunity detail page loaded' })
  await shot(page, '01-opportunity-detail')

  await page.getByRole('button', { name: 'Edit' }).first().click({ timeout: 10000 }).catch((e) => console.log('edit click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})

  // Sales Stage is the custom combobox component (@/components/ui/select), not a native
  // <select> - its trigger carries role="combobox" and currently displays "Prospecting";
  // its option list renders into a portal (document.body), so the option itself is
  // searched page-wide, only the trigger is scoped to the open dialog.
  const stageTrigger = page.locator('[role="dialog"] [role="combobox"]').filter({ hasText: 'Prospecting' }).first()
  await stageTrigger.click({ timeout: 8000 }).catch((e) => console.log('stage trigger click error', String(e).slice(0, 150)))
  const lostOption = page.locator('[role="option"]').filter({ hasText: 'Closed Lost' }).first()
  await lostOption.waitFor({ state: 'visible', timeout: 5000 }).catch((e) => console.log('closed lost option wait error', String(e).slice(0, 150)))
  await lostOption.click({ timeout: 5000 }).catch((e) => console.log('closed lost option click error', String(e).slice(0, 150)))
  const reasonFieldAppeared = await pollUntil(() => page.locator('#opp-close-reason').isVisible().catch(() => false), { timeout: 8000, label: 'Reason Lost field appears' })
  check('"Reason Lost" field appears when Sales Stage = Closed Lost', !!reasonFieldAppeared)
  await shot(page, '02-opp-edit-closed-lost-no-reason')

  // Try to save with no reason - expect the client-side guard to block it with an error, dialog stays open.
  const dialogSaveBtn = page.locator('[role="dialog"]').getByRole('button', { name: /Save|Update/i })
  await dialogSaveBtn.click({ timeout: 5000 }).catch((e) => console.log('save-no-reason click error', String(e).slice(0, 150)))
  const blockedNoReason = await pollUntil(async () => (await dialogText()).toLowerCase().includes('say why'), { timeout: 5000, label: 'blocked with no reason' })
  check('Saving Closed Lost with no reason is blocked client-side', !!blockedNoReason, (await dialogText()).slice(0, 200))

  const reasonFillOk = await page.locator('#opp-close-reason').fill('E2E verify: lost to a competitor on price.').then(() => true).catch((e) => { console.log('fill reason error', String(e).slice(0, 150)); return false })
  const reasonValueNow = await page.locator('#opp-close-reason').inputValue().catch(() => '<unreadable>')
  console.log('reason field fill ok:', reasonFillOk, '| current value:', reasonValueNow)
  await dialogSaveBtn.click({ timeout: 5000 }).catch((e) => console.log('save-with-reason click error', String(e).slice(0, 150)))
  const savedOk = await pollUntil(async () => !(await page.locator('[role="dialog"]').first().isVisible().catch(() => false)), { timeout: RELOAD_TIMEOUT, label: 'edit dialog closes after save' })
  if (!savedOk) console.log('dialog text at timeout:', (await dialogText()).slice(0, 300))
  check('Opportunity saves successfully once a reason is given', !!savedOk)

  const reasonShown = await pollUntil(async () => (await bodyText()).includes('lost to a competitor on price'), { timeout: RELOAD_TIMEOUT, label: 'detail page shows Reason Lost' })
  await shot(page, '03-opp-detail-after-lost')
  check('Opportunity detail page shows the "Reason Lost" section', !!reasonShown)

  // ── 2. Invoice: PDF download ─────────────────────────────────────────
  await page.goto(`${BASE}/module/crm/sales/invoices/${INVOICE_ID}`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error invoice', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('E2E Wave1 Verify Invoice'), { timeout: 30000, label: 'invoice detail page loaded' })
  await shot(page, '04-invoice-detail-unpaid')
  const bodyAfterLoad = await bodyText()
  check('Invoice header shows "Unpaid" payment status', bodyAfterLoad.includes('Unpaid'))

  // Decoupled from whether headless Chromium's download event fires for a Blob-URL +
  // synthetic <a> click (a known inconsistency, unrelated to whether the backend actually
  // produced a real PDF) - fetch the endpoint directly, in-page, with the same Bearer token
  // the app itself uses, and inspect the real response bytes.
  const pdfCheck = await page.evaluate(async (invoiceId) => {
    try {
      const token = JSON.parse(localStorage.getItem('userData') || '{}').token
      const res = await fetch(`/api/crm/invoices/${invoiceId}/pdf`, { headers: { Authorization: `Bearer ${token}` } })
      const buf = await res.arrayBuffer()
      const bytes = new Uint8Array(buf.slice(0, 5))
      const magic = Array.from(bytes).map((c) => String.fromCharCode(c)).join('')
      return { ok: res.ok, status: res.status, contentType: res.headers.get('content-type'), byteLength: buf.byteLength, magic }
    } catch (e) {
      return { ok: false, error: String(e) }
    }
  }, INVOICE_ID).catch((e) => ({ ok: false, error: String(e) }))
  console.log('direct PDF fetch result:', JSON.stringify(pdfCheck))
  check(
    'Invoice PDF endpoint returns a real PDF (direct fetch, bypassing the browser download mechanism)',
    pdfCheck.ok && pdfCheck.contentType === 'application/pdf' && pdfCheck.magic === '%PDF-' && pdfCheck.byteLength > 500,
    JSON.stringify(pdfCheck),
  )

  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }).catch(() => null),
    page.getByRole('button', { name: /Download PDF/i }).click({ timeout: 10000 }).catch((e) => console.log('download click error', String(e).slice(0, 150))),
  ])
  check('Clicking "Download PDF" also triggers a browser download event (best-effort, headless)', !!download, download ? download.suggestedFilename() : '(not observed - see the direct-fetch check above for the authoritative result)')

  // ── 3. Invoice: Email Customer ───────────────────────────────────────
  await page.getByRole('button', { name: /Email Customer/i }).click({ timeout: 10000 }).catch((e) => console.log('email customer click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  await page.locator('#email-to').fill('customer@example.invalid').catch((e) => console.log('fill email-to error', String(e).slice(0, 150)))
  await page.locator('#email-subject').fill('E2E verify invoice email').catch((e) => console.log('fill email-subject error', String(e).slice(0, 150)))
  await page.locator('#email-body').fill('This is an automated verification email.').catch((e) => console.log('fill email-body error', String(e).slice(0, 150)))
  console.log('email form values:', JSON.stringify({
    to: await page.locator('#email-to').inputValue().catch(() => '<unreadable>'),
    subject: await page.locator('#email-subject').inputValue().catch(() => '<unreadable>'),
  }))
  await shot(page, '05-invoice-email-dialog')
  await page.locator('[role="dialog"]').getByRole('button', { name: 'Send' }).click({ timeout: 10000 }).catch((e) => console.log('send click error', String(e).slice(0, 150)))
  const emailSent = await pollUntil(async () => !(await page.locator('[role="dialog"]').first().isVisible().catch(() => false)), { timeout: RELOAD_TIMEOUT, label: 'compose dialog closes after send' })
  if (!emailSent) console.log('dialog text at timeout:', (await dialogText()).slice(0, 300))
  check('Composing and sending an invoice email succeeds', !!emailSent)

  await page.getByRole('button', { name: 'Emails' }).click({ timeout: 10000 }).catch((e) => console.log('emails tab click error', String(e).slice(0, 150)))
  const emailListed = await pollUntil(async () => (await bodyText()).includes('E2E verify invoice email'), { timeout: 15000, label: 'emails tab lists the sent email' })
  await shot(page, '06-invoice-emails-tab')
  check('Emails tab lists the composed email', !!emailListed)

  // ── 4. Invoice: Payments ─────────────────────────────────────────────
  await page.getByRole('button', { name: 'Payments' }).click({ timeout: 10000 }).catch((e) => console.log('payments tab click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('No payments recorded yet'), { timeout: 15000, label: 'payments tab shows empty state' })
  await shot(page, '07-invoice-payments-empty')

  const paymentDialogSubmit = page.locator('[role="dialog"]').getByRole('button', { name: 'Record Payment' })
  await page.getByRole('button', { name: 'Record Payment' }).first().click({ timeout: 10000 }).catch((e) => console.log('record payment click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  await page.locator('#payment-amount').fill('400').catch((e) => console.log('fill amount error', String(e).slice(0, 150)))
  await page.locator('#payment-method').fill('Bank Transfer').catch((e) => console.log('fill method error', String(e).slice(0, 150)))
  await paymentDialogSubmit.click({ timeout: 10000 }).catch((e) => console.log('submit payment error', String(e).slice(0, 150)))
  const partialRecorded = await pollUntil(async () => (await bodyText()).includes('Partially Paid'), { timeout: RELOAD_TIMEOUT, label: 'header shows Partially Paid after 400 payment' })
  await shot(page, '08-invoice-after-partial-payment')
  check('Recording a 400 payment on a 1000 invoice shows "Partially Paid"', !!partialRecorded)
  const balance600 = await pollUntil(async () => (await bodyText()).includes('600.00'), { timeout: 10000, label: 'balance due shows 600.00' })
  check('Balance Due correctly shows 600.00 after the partial payment', !!balance600)

  // A full reload (onSaved()) remounts InvoiceDetailPage from scratch, so its `tab` state
  // resets to 'details' - the same behaviour saveLineItems()/changeStage() already have.
  // Re-select the Payments tab before trying to record the second payment.
  await page.getByRole('button', { name: 'Payments' }).click({ timeout: 10000 }).catch((e) => console.log('re-click payments tab error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Bank Transfer'), { timeout: 10000, label: 'payments tab shows the first payment again' })

  await page.getByRole('button', { name: 'Record Payment' }).first().click({ timeout: 10000 }).catch((e) => console.log('record 2nd payment click error', String(e).slice(0, 150)))
  await page.locator('[role="dialog"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
  const prefilled = await page.locator('#payment-amount').inputValue().catch(() => '')
  check('"Record Payment" pre-fills the remaining balance (600.00)', prefilled === '600.00', prefilled)
  await page.locator('#payment-method').fill('Cash').catch((e) => console.log('fill 2nd method error', String(e).slice(0, 150)))
  await paymentDialogSubmit.click({ timeout: 10000 }).catch((e) => console.log('submit 2nd payment error', String(e).slice(0, 150)))
  const fullyPaid = await pollUntil(async () => { const t = await bodyText(); return t.includes('Paid') && !t.includes('Partially Paid') && !t.includes('Unpaid') }, { timeout: RELOAD_TIMEOUT, label: 'header shows Paid after full payment' })
  await shot(page, '09-invoice-fully-paid')
  check('Invoice shows "Paid" once the balance is fully covered', !!fullyPaid)

  await page.getByRole('button', { name: 'Payments' }).click({ timeout: 10000 }).catch((e) => console.log('re-click payments tab 2 error', String(e).slice(0, 150)))
  const paymentsListed = await pollUntil(async () => { const t = await bodyText(); return t.includes('400.00') && t.includes('600.00') && t.includes('Bank Transfer') && t.includes('Cash') }, { timeout: 10000, label: 'both payments listed in the ledger' })
  check('Both payments appear in the Payments tab ledger', !!paymentsListed)

  console.log('\n=== all /api/ calls ===', JSON.stringify(log.api))
  console.log('=== console errors ===', JSON.stringify(log.console.slice(0, 10)))
  console.log('=== page errors ===', JSON.stringify(log.pageerrors.slice(0, 10)))
  console.log('=== non-2xx API calls ===', JSON.stringify(log.api.filter((x) => !x.startsWith('2')).slice(0, 20)))

  const failed = results.filter((r) => !r.ok)
  console.log(`\n=== SUMMARY: ${results.length - failed.length}/${results.length} passed ===`)
  if (failed.length) console.log('FAILED:', JSON.stringify(failed, null, 2))

  await page.close()
  await b.close()
  process.exit(failed.length ? 1 : 0)
})()
