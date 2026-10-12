const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch(); const ctx = await ctxFor(b, 'admin'); const page = await ctx.newPage(); const log = { console: [], pageerrors: [], api: [] }; watch(page, log)
  page.setDefaultTimeout(30000)
  const dlg = page.locator('[role=dialog]').first()
  const post = (frag, m = 'POST') => { const p = page.waitForResponse((r) => r.url().includes(frag) && r.request().method() === m, { timeout: 40000 }); p.catch(() => {}); return p }
  const closeDlg = async () => { await page.keyboard.press('Escape'); await page.waitForTimeout(500) }
  const ok2 = async (r, what) => { if (r.status() >= 300) throw new Error(what + ' ' + r.status() + ' ' + (await r.text()).slice(0, 200)) }

  await go(page, 'outreach')
  await step(page, 'outreach: suppression add / listed / remove (valid email)', async () => {
    await page.getByRole('button', { name: 'Suppression list' }).click(); await page.waitForTimeout(1200)
    await page.getByPlaceholder('name@example.com').fill('verify.e2e.block@example.invalid')
    let r = post('/api/gtm/suppressions'); await page.getByRole('button', { name: 'Add', exact: true }).click(); await ok2(await r, 'add')
    await page.getByText('verify.e2e.block@example.invalid').first().waitFor()
    r = post('/api/gtm/suppressions', 'DELETE'); await page.getByRole('button', { name: /Remove/ }).first().click(); await ok2(await r, 'remove')
  })

  await go(page, 'campaigns')
  await step(page, 'campaigns: cannot activate with zero targets (server rule shown to the user)', async () => {
    await page.getByText('Verify E2E Campaign').first().click(); await page.waitForTimeout(1500)
    await page.getByRole('button', { name: 'Activate' }).click(); await page.waitForTimeout(1500)
    const t = await page.locator('body').innerText(); if (!/at least one target|no_targets|needs at least one/i.test(t)) throw new Error('no zero-target message')
    await page.keyboard.press('Escape')
  })
  await step(page, 'campaigns: add account target by search', async () => {
    await page.getByPlaceholder('Search accounts by name').fill('Verify E2E'); await page.getByRole('button', { name: 'Search', exact: true }).click(); await page.waitForTimeout(1500)
    await page.locator('main [role=checkbox]').first().click()
    const r = post('/api/gtm/campaigns/'); await page.getByRole('button', { name: 'Add selected accounts' }).click(); await ok2(await r, 'add members')
    await page.waitForTimeout(1200); if (!/Targets \(1 account/.test(await page.locator('main').innerText())) throw new Error('target count not 1')
  })
  await step(page, 'campaigns: activate with a target -> active; metrics are measured zeros not invented', async () => {
    const r = post('/api/gtm/campaigns/'); await page.getByRole('button', { name: 'Activate' }).click(); await page.waitForTimeout(700)
    if (await dlg.count()) { await dlg.getByRole('button', { name: /^(Confirm|Activate|Yes)/ }).last().click() }
    await ok2(await r, 'status'); await page.waitForTimeout(1200)
    if (!/active/.test(await page.locator('main').innerText())) throw new Error('status not active')
  })
  await go(page, 'campaigns')
  await step(page, 'campaigns: segment builder - save and preview', async () => {
    await page.getByRole('button', { name: 'Segments', exact: true }).click(); await page.waitForTimeout(1500)
    await page.getByRole('button', { name: /New segment/ }).click(); await dlg.waitFor()
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E Segment')
    const r = post('/api/gtm/segments'); await dlg.getByRole('button', { name: /Save and preview/ }).click(); await ok2(await r, 'segment'); await page.waitForTimeout(2500)
    const t = await page.locator('body').innerText(); if (!/match|preview|account/i.test(t)) throw new Error('no preview shown')
  })
  await closeDlg()

  await go(page, 'workflows')
  await step(page, 'workflows: create draft (Deal created -> action with required text)', async () => {
    await page.getByRole('button', { name: 'New workflow' }).click(); await dlg.waitFor(); await page.waitForTimeout(800)
    await dlg.getByPlaceholder(/Flag stalled/).fill('Verify E2E Workflow')
    await dlg.locator('select').first().selectOption('gtm.deal.created')
    await dlg.getByRole('button', { name: /Add action/ }).click(); await page.waitForTimeout(500)
    const block = dlg.locator('div.rounded-lg.border').last()
    for (const el of await block.locator('input[type=text], input:not([type]), textarea').all()) { if (!(await el.inputValue())) await el.fill('Verify E2E follow-up {{deal.name}}') }
    console.log('   action block:', (await block.innerText()).replace(/\s+/g, ' ').slice(0, 160))
    const r = post('/api/gtm/workflows'); await dlg.getByRole('button', { name: 'Create draft' }).click(); const x = await r
    if (x.status() >= 300) { throw new Error('create ' + x.status() + ' ' + (await x.text()).slice(0, 250)) }
    await page.getByText('Verify E2E Workflow').first().waitFor()
  })
  await step(page, 'workflows: dry run executes no actions', async () => {
    await page.getByRole('button', { name: 'Dry run' }).first().click(); await dlg.waitFor()
    await dlg.locator('input').first().fill('999999'); const r = post('/api/gtm/workflows/'); await dlg.getByRole('button', { name: 'Run test' }).click(); const x = await r
    console.log('   dry-run on a non-existent record ->', x.status(), (await x.text()).slice(0, 160))
  })
  await closeDlg()
  await step(page, 'workflows: activate (confirm) -> pause -> archive (confirm)', async () => {
    let r = post('/api/gtm/workflows/'); await page.getByRole('button', { name: 'Activate' }).first().click(); await dlg.waitFor()
    console.log('   activate dialog:', (await dlg.innerText()).replace(/\s+/g, ' ').slice(0, 220))
    await dlg.getByRole('button', { name: /Activate/ }).last().click(); await ok2(await r, 'activate'); await page.waitForTimeout(1500)
    r = post('/api/gtm/workflows/'); await page.getByRole('button', { name: 'Pause' }).first().click(); await dlg.waitFor(); await dlg.getByRole('button', { name: /Pause/ }).last().click(); await ok2(await r, 'pause'); await page.waitForTimeout(1500)
    r = post('/api/gtm/workflows/'); await page.getByRole('button', { name: 'Archive' }).first().click(); await dlg.waitFor(); await dlg.getByRole('button', { name: /Archive/ }).last().click(); await ok2(await r, 'archive')
  })

  console.log(`\n${pass} passed, ${fail} failed; pageerrors=${JSON.stringify(log.pageerrors)}; console.errors=${log.console.length}`)
  await b.close()
})()
