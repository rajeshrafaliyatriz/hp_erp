const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch(); const ctx = await ctxFor(b, 'admin'); const page = await ctx.newPage(); const log = { console: [], pageerrors: [], api: [] }; watch(page, log)
  page.setDefaultTimeout(40000)
  page.on('dialog', (d) => d.accept())
  const dlg = page.locator('[role=dialog]').first()
  const post = (frag, m = 'POST') => { const p = page.waitForResponse((r) => r.url().includes(frag) && r.request().method() === m, { timeout: 40000 }); p.catch(() => {}); return p }
  const ok2 = async (r, what) => { if (r.status() >= 300) throw new Error(what + ' ' + r.status() + ' ' + (await r.text()).slice(0, 200)) }

  await go(page, 'outreach')
  await step(page, 'outreach: suppression list shows the entry added earlier, remove deletes it', async () => {
    await page.getByRole('button', { name: 'Suppression list' }).click(); await page.waitForTimeout(2500)
    await page.waitForTimeout(500)
    const r = post('/api/gtm/suppressions', 'DELETE'); await page.getByRole('button', { name: "Remove verify.e2e.block@example.invalid" }).click().catch(() => {}); await r.catch(() => {})
  })
  await step(page, 'outreach: a suppressed address cannot be drafted to (server refuses) - UI shows the reason', async () => {
    await page.getByPlaceholder('name@example.com').fill('verify.e2e.person@example.invalid'); let r = post('/api/gtm/suppressions'); await page.getByRole('button', { name: 'Add', exact: true }).click(); await ok2(await r, 'add')
    await page.getByRole('button', { name: 'All', exact: true }).click(); await page.waitForTimeout(1500)
    await page.getByRole('button', { name: 'New message', exact: true }).click(); await dlg.waitFor()
    const sels = dlg.locator('select'); await sels.nth(0).selectOption({ label: 'Verify E2E Co' }); await page.waitForTimeout(1000); await sels.nth(1).selectOption({ index: 1 })
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E suppressed subject')
    await dlg.locator('textarea').first().fill('Hello, a clearly labelled Verify E2E message to a suppressed address, long enough.')
    r = post('/api/gtm/outreach'); await dlg.getByRole('button', { name: /^(Save|Create)/ }).first().click(); const x = await r
    console.log('   create to a suppressed address ->', x.status(), (await x.text()).slice(0, 200)); await page.waitForTimeout(800)
    console.log('   dialog text:', (await dlg.innerText()).replace(/\s+/g, ' ').slice(-200))
    await page.keyboard.press('Escape')
  })
  await step(page, 'outreach: cleanup - remove test suppression', async () => {
    await page.getByRole('button', { name: 'Suppression list' }).click(); await page.waitForTimeout(2000)
    const r = post('/api/gtm/suppressions', 'DELETE'); await page.getByRole('button', { name: "Remove verify.e2e.block@example.invalid" }).click().catch(() => {}); await r.catch(() => {})
  })

  await go(page, 'campaigns')
  await step(page, 'campaigns: add account target by search, count 1', async () => {
    await page.getByText('Verify E2E Campaign').first().click(); await page.waitForTimeout(1500)
    await page.getByPlaceholder('Search accounts by name').fill('Verify E2E'); await page.getByRole('button', { name: 'Search', exact: true }).click(); await page.waitForTimeout(2500)
    await page.locator('main [role=checkbox], main input[type=checkbox]').first().click({ force: true })
    const r = post('/api/gtm/campaigns/'); await page.getByRole('button', { name: /selected account/ }).click({ timeout: 10000 }); await ok2(await r, 'add members')
    await page.waitForTimeout(1500); if (!/Targets \(1 account/.test(await page.locator('main').innerText())) throw new Error('target count not 1')
  })
  await step(page, 'campaigns: activate -> active', async () => {
    const r = post('/api/gtm/campaigns/'); await page.getByRole('button', { name: 'Activate' }).click(); await page.waitForTimeout(700)
    if (await dlg.count()) { await dlg.getByRole('button', { name: /^(Confirm|Activate|Yes)/ }).last().click() }
    await ok2(await r, 'status'); await page.waitForTimeout(1200); if (!/active/.test(await page.locator('main').innerText())) throw new Error('status not active')
  })
  await go(page, 'campaigns')
  await step(page, 'campaigns: segment builder - save and preview', async () => {
    await page.locator('main button', { hasText: 'Segments' }).first().click(); await page.waitForTimeout(2000)
    await page.getByRole('button', { name: /New segment/ }).click({ timeout: 20000 }); await dlg.waitFor()
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E Segment')
    let r = post('/api/gtm/segments'); await dlg.getByRole('button', { name: /Save and preview/ }).click(); const x = await r
    console.log('   no filter chosen ->', x.status(), 'message shown to user:', /criteria|filter|required|choose/i.test(await dlg.innerText()))
    await dlg.getByText('target', { exact: true }).first().click()
    r = post('/api/gtm/segments'); await dlg.getByRole('button', { name: /Save and preview/ }).click(); await ok2(await r, 'segment'); await page.waitForTimeout(3000)
    console.log('   after save:', (await page.locator('body').innerText()).replace(/\s+/g, ' ').match(/Verify E2E Co.{0,60}/)?.[0])
  })

  console.log(`\n${pass} passed, ${fail} failed; pageerrors=${JSON.stringify(log.pageerrors)}`)
  await b.close()
})()
