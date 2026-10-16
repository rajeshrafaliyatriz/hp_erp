// Live browser verification for Gap-Closure Wave 6: automated SLA escalation
// and notifications on breach.
//
// The notification pipeline itself (event recorded -> NotificationDispatcher
// -> RecipientResolver -> g2g_notification, both the assignee and the named
// escalation contact, correct template substitution, correct ledger entry)
// was already proven end to end by a direct backend test - which ALSO
// discovered that tenant 6 (this whole effort's test tenant) sits on the
// G2G_NOTIFY_EMAIL_TENANTS allowlist, so dispatching a real event for real
// sends real email via live Gmail SMTP. This script deliberately NEVER
// calls NotificationDispatcher::dispatch() or runs `events:react` - it only
// exercises TicketSlaEscalator::escalateOverdue() directly (which records
// the event but does not dispatch it) via a `php artisan tinker` subprocess,
// then verifies the resulting STATE (sla_escalated_at, a real reassignment)
// renders correctly in the browser. Any event row it causes is deleted in
// cleanup before this script exits, so nothing is left for a later
// `events:react` tick to pick up and dispatch for real.
//
// Run: node tests/Integration/Gtm/browser/verify-gap-closure-wave6.cjs
const { chromium } = require('playwright-core')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { execSync } = require('child_process')
const S = JSON.parse(fs.readFileSync(__dirname + '/sessions.json', 'utf8'))
const BASE = 'http://localhost:3000'
const HP_ERP_ROOT = path.resolve(__dirname, '..', '..', '..', '..')

// Writes PHP to a real temp FILE and requires it from tinker, rather than
// passing PHP source inline on a shell command line - avoids the fragile
// JS-string -> shell-string -> PHP-string triple escaping (worse on Windows,
// where Node's execSync goes through cmd.exe with its own quoting rules),
// the exact same safe pattern already used earlier this session for the
// Wave 5 backend verification script.
function tinkerFile(phpCode) {
  const tmpFile = path.join(os.tmpdir(), `wave6-tinker-${Date.now()}-${Math.random().toString(36).slice(2)}.php`)
  fs.writeFileSync(tmpFile, phpCode)
  try {
    return execSync(`php artisan tinker --execute="require '${tmpFile.replace(/\\/g, '/')}';"`, { cwd: HP_ERP_ROOT, encoding: 'utf8' }).trim()
  } finally {
    fs.unlinkSync(tmpFile)
  }
}
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
  return page.screenshot({ path: `${__dirname}/shots/wave6-${name}.png`, fullPage: true }).catch((e) => console.log('screenshot failed', name, String(e).slice(0, 120)))
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

const TENANT = 6;

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

  // ── 1. Admin: configure an Urgent SLA policy + escalation via the real UI ──
  await page.goto(`${BASE}/module/crm/master-fields`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error master-fields', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Master Fields'), { timeout: 30000, label: 'master fields page loaded' })
  await page.getByRole('button', { name: 'SLA Policies' }).click({ timeout: 10000 }).catch((e) => console.log('tab click error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('first response'), { timeout: 15000, label: 'SLA policies tab visible' })

  const urgentRow = page.locator('li', { hasText: 'Urgent' }).first()
  const urgentAlreadySet = (await urgentRow.innerText().catch(() => '')).includes('hour')
  if (!urgentAlreadySet) {
    await urgentRow.locator('input[type="number"]').fill('1').catch((e) => console.log('fill urgent hours error', String(e).slice(0, 150)))
    await urgentRow.getByRole('button', { name: 'Set' }).click({ timeout: 10000 }).catch((e) => console.log('set urgent error', String(e).slice(0, 150)))
    await pollUntil(async () => (await urgentRow.innerText().catch(() => '')).includes('hour'), { timeout: 15000, label: 'Urgent policy shows hours' })
  }
  await shot(page, '01-urgent-policy-set')
  check('Urgent SLA policy is configured', (await urgentRow.innerText().catch(() => '')).includes('hour'))

  // The escalation contact picker is the ONLY combobox inside this specific
  // <li> (Type/Status/Expected Response-style multi-combobox ambiguity from
  // other waves does not apply here - this row has exactly one), so scoping
  // to the row itself is enough; still filtered by its own placeholder for
  // the same discipline every prior wave's combobox lesson established.
  const escalateCombo = urgentRow.locator('[role="combobox"]').filter({ hasText: 'escalation contact' }).first()
  const autoReassignSwitch = urgentRow.getByRole('switch')

  const disabledBeforeContact = await autoReassignSwitch.isDisabled().catch(() => null)
  check('auto-reassign switch is disabled before an escalation contact is chosen', disabledBeforeContact === true, String(disabledBeforeContact))

  await escalateCombo.click({ timeout: 8000 }).catch((e) => console.log('escalate combo click error', String(e).slice(0, 150)))
  const optionReady = await pollUntil(() => page.locator('[role="option"]').first().isVisible().catch(() => false), { timeout: 10000, label: 'escalation contact options become available' })
  if (!optionReady) {
    await escalateCombo.click({ timeout: 8000 }).catch((e) => console.log('escalate combo re-click error', String(e).slice(0, 150)))
    await pollUntil(() => page.locator('[role="option"]').first().isVisible().catch(() => false), { timeout: 10000, label: 'options available after re-click' })
  }
  const chosenOptionText = await page.locator('[role="option"]').first().innerText().catch(() => '')
  await page.locator('[role="option"]').first().click({ timeout: 5000 }).catch((e) => console.log('escalate option click error', String(e).slice(0, 150)))
  const contactChosen = await pollUntil(async () => !(await escalateCombo.innerText().catch(() => '')).includes('No escalation contact'), { timeout: 5000, label: 'escalation contact now shows a name' })
  check('an escalation contact was chosen', !!contactChosen, chosenOptionText)
  await shot(page, '02-escalation-contact-chosen')

  const enabledAfterContact = await autoReassignSwitch.isDisabled().catch(() => null)
  check('auto-reassign switch becomes enabled once a contact is chosen', enabledAfterContact === false, String(enabledAfterContact))

  await autoReassignSwitch.click({ timeout: 5000 }).catch((e) => console.log('switch click error', String(e).slice(0, 150)))
  const patchWait = page.waitForResponse((r) => r.url().includes('/escalation') && r.request().method() === 'PATCH', { timeout: 10000 }).catch(() => null)
  await urgentRow.getByRole('button', { name: 'Save' }).click({ timeout: 10000 }).catch((e) => console.log('save escalation error', String(e).slice(0, 150)))
  const patchResp = await patchWait
  if (patchResp) {
    const patchBody = await patchResp.text().catch(() => '<unreadable>')
    console.log('DIAGNOSTIC PATCH /escalation response:', patchResp.status(), patchBody.slice(0, 500))
  } else {
    console.log('DIAGNOSTIC: no PATCH /escalation response observed')
  }
  const saved = await pollUntil(async () => !(await urgentRow.getByRole('button', { name: 'Save' }).isVisible().catch(() => true)), { timeout: 15000, label: 'escalation settings save completes (Save button disappears, no longer dirty)' })
  check('saving the escalation settings succeeds', !!saved)
  await shot(page, '03-escalation-saved')

  // Reload the whole page and confirm it actually persisted, not just local state.
  await page.goto(`${BASE}/module/crm/master-fields`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error reload', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Master Fields'), { timeout: 30000, label: 'master fields reloaded' })
  await page.getByRole('button', { name: 'SLA Policies' }).click({ timeout: 10000 }).catch((e) => console.log('tab click 2 error', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('first response'), { timeout: 15000, label: 'SLA policies tab visible after reload' })
  const urgentRowAfterReload = page.locator('li', { hasText: 'Urgent' }).first()
  // "first response" appearing proves the TAB rendered, not that the escalation
  // sub-row's own async getSlaPolicies() call (a second round trip, inside the
  // tab) has resolved yet - poll specifically for that, not just the tab shell.
  await pollUntil(async () => !(await urgentRowAfterReload.innerText().catch(() => '')).includes('No escalation contact'), { timeout: 15000, label: 'escalation contact renders after reload' })
  const reloadedText = await urgentRowAfterReload.innerText().catch(() => '')
  check('after a full reload, the escalation contact is still shown (persisted, not just local state)', !reloadedText.includes('No escalation contact'), reloadedText.replace(/\s+/g, ' ').slice(0, 200))
  const autoReassignCheckedAfterReload = await urgentRowAfterReload.getByRole('switch').isChecked().catch(() => null)
  check('auto-reassign is still ON after reload', autoReassignCheckedAfterReload === true, String(autoReassignCheckedAfterReload))

  // ── 2. Backend only: trigger a REAL breach+escalation (never dispatches) ──
  const setup = tinkerFile(`<?php
use App\\Domain\\Crm\\Support\\TicketSlaEscalator;
use Illuminate\\Support\\Facades\\DB;

$policy = DB::table('crm_sla_policies')->where('sub_institute_id', 6)->where('priority', 'Urgent')->first();
$assignee = DB::table('tbluser')->where('sub_institute_id', 6)->where('id', '!=', $policy->escalate_to_user_id)->orderBy('id')->value('id');
$ticketId = DB::table('crm_tickets')->insertGetId([
    'ticket_no' => 'TKT-W6BROWSER', 'title' => 'E2E Wave6 Browser Ticket', 'priority' => 'Urgent', 'status' => 'Open',
    'assigned_to' => $assignee, 'sub_institute_id' => 6, 'created_by' => $assignee,
    'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
]);
$result = TicketSlaEscalator::escalateOverdue(6, false);
echo json_encode(['ticketId' => $ticketId, 'escalateTo' => (int) $policy->escalate_to_user_id, 'result' => $result]);
`)
  const setupJsonMatch = setup.match(/\{.*\}/s)
  const setupData = setupJsonMatch ? JSON.parse(setupJsonMatch[0]) : null
  check('backend setup created a backdated ticket and ran the real escalator', !!setupData && !!setupData.ticketId, setup.slice(-300))
  const ticketId = setupData?.ticketId
  const escalateToId = setupData?.escalateTo

  // ── 3. Open the real ticket, confirm the escalated/reassigned state renders ──
  await page.goto(`${BASE}/module/crm/support/tickets/${ticketId}`, { waitUntil: 'load', timeout: 60000 }).catch((e) => console.log('nav error ticket detail', String(e).slice(0, 150)))
  await pollUntil(async () => (await bodyText()).includes('Ticket Details') || (await bodyText()).includes('E2E Wave6 Browser Ticket'), { timeout: 30000, label: 'ticket detail page loaded' })
  await shot(page, '04-escalated-ticket-detail')
  const detailText = await bodyText()
  check('the SLA badge shows "SLA Breached · Escalated"', detailText.includes('SLA Breached') && detailText.includes('Escalated'), detailText.slice(0, 400))
  check('the detail page shows an explicit "Escalated" timestamp line', /Escalated \d{4}-\d{2}-\d{2}/.test(detailText))

  const escalateToName = tinkerFile(`<?php
use Illuminate\\Support\\Facades\\DB;
$u = DB::table('tbluser')->where('id', ${escalateToId})->first();
echo trim($u->first_name . ' ' . $u->last_name);
`)
  check('Assigned To was reassigned to the real escalation contact', escalateToName.trim() !== '' && detailText.includes(escalateToName.trim()), `expected "${escalateToName.trim()}"`)

  // ── Cleanup ──────────────────────────────────────────────────────────────
  tinkerFile(`<?php
use Illuminate\\Support\\Facades\\DB;
$ids = DB::table('crm_tickets')->where('ticket_no', 'TKT-W6BROWSER')->pluck('id');
DB::table('g2g_event')->where('type', 'ticket.sla_breached')->whereIn('entity_id', $ids)->delete();
DB::table('crm_tickets')->whereIn('id', $ids)->delete();
DB::table('crm_sla_policies')->where('sub_institute_id', 6)->delete();
echo 'cleanup done';
`)

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
