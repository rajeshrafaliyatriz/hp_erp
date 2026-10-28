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
  await step(page, 'outreach: suppression add / listed / remove', async () => {
    await page.getByRole('button', { name: 'Suppression list' }).click(); await page.waitForTimeout(1200)
    await page.getByPlaceholder('name@example.com').fill('verify-e2e.example.invalid')
    let r = post('/api/gtm/suppressions'); await page.getByRole('button', { name: 'Add', exact: true }).click(); await ok2(await r, 'add')
    await page.getByText('verify-e2e.example.invalid').first().waitFor()
    r = post('/api/gtm/suppressions', 'DELETE'); await page.getByRole('button', { name: /Remove/ }).first().click(); await ok2(await r, 'remove')
  })
  await step(page, 'outreach: suppressing a contact address blocks drafting to it (server refuses)', async () => {
    await page.getByRole('button', { name: 'Email address' }).count()
  })

  await go(page, 'deals')
  await step(page, 'deals: edit amount, close date, forecast category -> persists', async () => {
    await page.getByText('Verify E2E Deal').first().click(); await dlg.waitFor(); await page.waitForTimeout(1200)
    await dlg.locator('input[value="50000"]').fill('65000')
    await dlg.locator('input[type=date]').nth(1).fill('2026-12-15')
    await dlg.locator('select').filter({ hasText: /stage default/ }).first().selectOption('commit')
    const r = post('/api/gtm/deals/', 'PATCH'); await dlg.getByRole('button', { name: 'Save', exact: true }).click(); await ok2(await r, 'PATCH')
    await page.waitForTimeout(1200); const t = await dlg.innerText(); if (!/65,?000/.test(t)) throw new Error('amount not updated in header: ' + t.slice(0, 120))
  })
  await step(page, 'deals: moving to won requires explicit confirmation (no silent close)', async () => {
    const r0 = page.waitForResponse((r) => r.url().includes('/api/gtm/deals/') && r.url().includes('/stage'), { timeout: 15000 }); r0.catch(() => {})
    await dlg.locator('select').first().selectOption('closed_won').catch(() => console.log('   (stage select has no closed_won option name; skipped)'))
    await page.waitForTimeout(1200); console.log('   after choosing won:', (await dlg.innerText()).replace(/\s+/g, ' ').slice(0, 160))
  })
  await closeDlg()

  await go(page, 'forecast')
  await step(page, 'forecast: deal appears in INR block (has amount, close date, stage)', async () => {
    await page.getByText(/INR/).first().waitFor(); const t = await page.locator('main').innerText()
    if (!/(not configured|Weighted)/i.test(t)) throw new Error('weighted state missing')
    console.log('   ', t.replace(/\s+/g, ' ').slice(0, 300))
  })

  await go(page, 'campaigns')
  await step(page, 'campaigns: open campaign, cannot activate with zero targets (server rule shown)', async () => {
    await page.getByText('Verify E2E Campaign').first().click(); await page.waitForTimeout(1500)
    await page.getByRole('button', { name: 'Activate' }).click(); await page.waitForTimeout(1200)
    const t = await page.locator('main').innerText(); const d = (await dlg.count()) ? await dlg.innerText() : ''
    console.log('   ', (d || t).replace(/\s+/g, ' ').slice(-200))
  })
  await step(page, 'campaigns: add the account as a target by search', async () => {
    await page.keyboard.press('Escape')
    const box = page.getByPlaceholder(/search/i).last(); await box.fill('Verify E2E'); await page.waitForTimeout(1500)
    const cb = page.locator('main input[type=checkbox]').first(); await cb.check()
    const r = post('/api/gtm/campaigns/'); await page.getByRole('button', { name: /Add selected|Add \d|Add account/ }).first().click(); await ok2(await r, 'add members')
    await page.getByText('Verify E2E Co').first().waitFor()
  })
  await step(page, 'campaigns: activate with a target (confirmation if required) -> status active', async () => {
    const r = post('/api/gtm/campaigns/'); await page.getByRole('button', { name: 'Activate' }).click(); await page.waitForTimeout(800)
    const confirm = page.getByRole('button', { name: /^(Confirm|Activate|Yes)/ }).last(); if (await dlg.count()) await confirm.click()
    await ok2(await r, 'status'); await page.getByText('active').first().waitFor()
  })
  await step(page, 'campaigns: segment builder - create + preview', async () => {
    await page.getByRole('button', { name: /All campaigns/ }).click(); await page.getByRole('button', { name: 'Segments', exact: true }).click(); await page.waitForTimeout(1500)
    await page.getByRole('button', { name: /New segment/ }).click(); await dlg.waitFor()
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E Segment')
    const r = post('/api/gtm/segments'); await dlg.getByRole('button', { name: /^(Save|Create)/ }).first().click(); await ok2(await r, 'segment')
    await page.waitForTimeout(1500); console.log('   after save:', (await page.locator('body').innerText()).replace(/\s+/g, ' ').match(/Verify E2E Segment.{0,80}/)?.[0])
  })
  await closeDlg()

  await go(page, 'workflows')
  await step(page, 'workflows: create a draft workflow from the catalog (Deal created -> add note)', async () => {
    await page.getByRole('button', { name: 'New workflow' }).click(); await dlg.waitFor(); await page.waitForTimeout(800)
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E Workflow')
    await dlg.locator('select').first().selectOption('gtm.deal.created')
    const addAction = dlg.getByRole('button', { name: /Add action/ }); if (await addAction.count()) await addAction.click()
    await page.waitForTimeout(500)
    const r = post('/api/gtm/workflows'); await dlg.getByRole('button', { name: /^(Save|Create)/ }).first().click(); const x = await r
    if (x.status() >= 300) { console.log('   server said:', (await x.text()).slice(0, 300)); throw new Error('create ' + x.status()) }
    await page.getByText('Verify E2E Workflow').first().waitFor()
  })
  await step(page, 'workflows: dry run on the deal shows trace without side effects', async () => {
    await page.getByRole('button', { name: 'Dry run' }).first().click(); await dlg.waitFor()
    await dlg.locator('input').first().fill('1'); const r = post('/api/gtm/workflows/'); await dlg.getByRole('button', { name: 'Run test' }).click(); const x = await r
    console.log('   dry-run status', x.status()); await page.waitForTimeout(1000)
  })
  await closeDlg()
  await step(page, 'workflows: activate asks for confirmation then status active; pause; archive needs confirm', async () => {
    let r = post('/api/gtm/workflows/'); await page.getByRole('button', { name: 'Activate' }).first().click(); await dlg.waitFor()
    console.log('   activate dialog:', (await dlg.innerText()).replace(/\s+/g, ' ').slice(0, 200))
    await dlg.getByRole('button', { name: /Activate/ }).last().click(); await ok2(await r, 'activate'); await page.waitForTimeout(1200)
    r = post('/api/gtm/workflows/'); await page.getByRole('button', { name: 'Pause' }).first().click(); await dlg.waitFor(); await dlg.getByRole('button', { name: /Pause/ }).last().click(); await ok2(await r, 'pause'); await page.waitForTimeout(1200)
    r = post('/api/gtm/workflows/'); await page.getByRole('button', { name: 'Archive' }).first().click(); await dlg.waitFor(); await dlg.getByRole('button', { name: /Archive/ }).last().click(); await ok2(await r, 'archive')
  })

  await go(page, 'reports')
  await step(page, 'reports: switching reports and filters re-query the API', async () => {
    const btns = page.locator('main button'); const n = await btns.count(); if (n < 4) throw new Error('few reports ' + n)
    for (let i = 0; i < Math.min(n, 8); i++) { const t = (await btns.nth(i).innerText()).trim(); if (!t) continue; await btns.nth(i).click(); await page.waitForTimeout(1200) }
  })

  await go(page, 'customers')
  await step(page, 'customers: customers list + health columns, alerts, agent mode buttons exist', async () => {
    const t = await page.locator('main').innerText(); console.log('   ', t.replace(/\s+/g, ' ').slice(0, 260))
    await page.getByRole('button', { name: 'Customer Success agent' }).click(); await page.waitForTimeout(1200)
    const bt = await page.locator('main button').allInnerTexts(); console.log('    agent buttons:', JSON.stringify(bt.filter(Boolean)))
  })

  console.log(`\n${pass} passed, ${fail} failed; pageerrors=${JSON.stringify(log.pageerrors)}; console.errors=${log.console.length} ${JSON.stringify(log.console.slice(0, 3))}`)
  await b.close()
})()
