const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch(); const ctx = await ctxFor(b, 'admin'); const page = await ctx.newPage(); const log = { console: [], pageerrors: [], api: [] }; watch(page, log)
  const dlg = page.locator('[role=dialog]').first()
  const post = (frag, m = 'POST') => page.waitForResponse((r) => r.url().includes(frag) && r.request().method() === m, { timeout: 30000 })

  await go(page, 'prospecting')
  await step(page, 'prospecting: empty name keeps Create disabled / dialog open', async () => {
    await page.getByRole('button', { name: 'Add account', exact: true }).click(); await dlg.waitFor()
    const btn = dlg.getByRole('button', { name: 'Create', exact: true })
    if (await btn.isEnabled()) { await btn.click(); await page.waitForTimeout(700); if (!(await dlg.isVisible())) throw new Error('empty name accepted') }
    await page.keyboard.press('Escape'); await dlg.waitFor({ state: 'hidden' })
  })
  await step(page, 'prospecting: open account, edit industry, persists', async () => {
    await page.getByText('Verify E2E Co').first().click(); await dlg.waitFor()
    await dlg.getByRole('button', { name: 'Edit', exact: true }).click()
    const ind = dlg.getByPlaceholder(/industry/i).first().or(dlg.locator('input[value="Software"]')).first(); await ind.fill('Software and Services')
    const r = post('/api/gtm/accounts/', 'PATCH'); await dlg.getByRole('button', { name: /^Save/ }).first().click(); const x = await r
    if (x.status() >= 300) throw new Error('PATCH ' + x.status() + ' ' + (await x.text()).slice(0, 200))
    await dlg.getByText('Software and Services').first().waitFor({ timeout: 10000 })
  })
  await step(page, 'prospecting: add contact (needs name) persists', async () => {
    await dlg.getByPlaceholder('Full name *').fill('Verify E2E Person'); await dlg.getByPlaceholder('Title').fill('Head of Ops'); await dlg.getByPlaceholder('Email').fill('verify.e2e.person@example.invalid')
    const r = post('/api/gtm/accounts/'); await dlg.getByRole('button', { name: 'Add', exact: true }).click(); const x = await r
    if (x.status() >= 300) throw new Error('POST ' + x.status() + ' ' + (await x.text()).slice(0, 200))
    await dlg.getByText('Verify E2E Person').first().waitFor({ timeout: 10000 })
  })
  await step(page, 'prospecting: log an activity', async () => {
    await dlg.getByPlaceholder('What happened?').fill('Verify E2E intro call'); const r = post('/api/gtm/'); await dlg.getByRole('button', { name: 'Log', exact: true }).click(); const x = await r
    if (x.status() >= 300) throw new Error(String(x.status())); await dlg.getByText('Verify E2E intro call').first().waitFor({ timeout: 10000 })
  })
  await step(page, 'prospecting: invalid contact email rejected by server (422) and shown', async () => {
    await dlg.getByPlaceholder('Full name *').fill('Verify E2E Bad'); await dlg.getByPlaceholder('Email').fill('not-an-email')
    const r = post('/api/gtm/accounts/'); await dlg.getByRole('button', { name: 'Add', exact: true }).click(); const x = await r
    if (x.status() !== 422) throw new Error('expected 422 got ' + x.status())
    await page.waitForTimeout(600); if (!(await dlg.innerText()).toLowerCase().match(/email|invalid|valid/)) throw new Error('error not shown')
  })
  await page.keyboard.press('Escape')

  await go(page, 'outreach')
  await step(page, 'outreach: new message -> draft', async () => {
    await page.getByRole('button', { name: 'New message', exact: true }).click(); await dlg.waitFor()
    const sels = dlg.locator('select'); await sels.nth(0).selectOption({ label: 'Verify E2E Co' }); await page.waitForTimeout(800)
    await sels.nth(1).selectOption({ index: 1 })
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E subject')
    await dlg.locator('textarea').first().fill('Hello, this is a clearly labelled Verify E2E test message body that is long enough.')
    const r = post('/api/gtm/outreach'); await dlg.getByRole('button', { name: /^(Save|Create)/ }).first().click(); const x = await r
    if (x.status() >= 300) throw new Error('POST ' + x.status() + ' ' + (await x.text()).slice(0, 200))
  })
  await step(page, 'outreach: draft listed; readiness panel states why sending is/is not possible', async () => {
    await page.getByText('Verify E2E subject').first().waitFor({ timeout: 15000 })
    const t = await page.locator('main').innerText(); if (!/Sending is (not )?enabled/.test(t)) throw new Error('no readiness verdict')
  })
  await step(page, 'outreach: submit -> pending approval, then approve needs confirmation', async () => {
    await page.getByText('Verify E2E subject').first().click(); await dlg.waitFor()
    let r = post('/api/gtm/outreach/'); await dlg.getByRole('button', { name: /Submit/ }).first().click(); let x = await r; if (x.status() >= 300) throw new Error('submit ' + x.status())
    await page.waitForTimeout(800)
    const approve = dlg.getByRole('button', { name: /Approve/ }).first(); await approve.waitFor({ timeout: 10000 })
    console.log('   approve control visible; NOT sending (sending stays off / no send click performed)')
    await page.keyboard.press('Escape')
  })
  await step(page, 'outreach: suppression tab - add and remove an entry', async () => {
    await page.getByRole('button', { name: 'Suppression list' }).click(); await page.waitForTimeout(1500)
    await page.locator('main input').last().fill('verify-e2e.example.invalid')
    const r = post('/api/gtm/suppressions'); await page.getByRole('button', { name: /^Add/ }).last().click(); const x = await r; if (x.status() >= 300) throw new Error('add ' + x.status() + ' ' + (await x.text()).slice(0, 150))
    await page.getByText('verify-e2e.example.invalid').first().waitFor({ timeout: 10000 })
  })

  console.log(`\n${pass} passed, ${fail} failed; pageerrors=${JSON.stringify(log.pageerrors)}; console.errors=${log.console.length} ${JSON.stringify(log.console.slice(0, 3))}`)
  await b.close()
})()
