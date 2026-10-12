const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch(); const ctx = await ctxFor(b, 'admin'); const page = await ctx.newPage(); const log = { console: [], pageerrors: [], api: [] }; watch(page, log)
  page.setDefaultTimeout(30000)
  const dlg = page.locator('[role=dialog]').first()
  const post = (frag, m = 'POST') => { const p = page.waitForResponse((r) => r.url().includes(frag) && r.request().method() === m, { timeout: 60000 }); p.catch(() => {}); return p }
  const closeDlg = async () => { await page.keyboard.press('Escape'); await page.waitForTimeout(500) }

  await go(page, 'outreach')
  await step(page, 'outreach: opened draft shows its saved subject and body (regression: empty fields)', async () => {
    await page.getByText('Verify E2E subject').first().click(); await dlg.waitFor()
    const v = await dlg.locator('input').first().inputValue(); if (!v.includes('Verify E2E subject')) throw new Error('subject field empty: ' + v)
    const t = await dlg.locator('textarea').first().inputValue(); if (!t.includes('Verify E2E test message')) throw new Error('body empty')
  })
  await step(page, 'outreach: edit then save persists', async () => {
    await dlg.locator('input').first().fill('Verify E2E subject v2'); const r = post('/api/gtm/outreach/', 'PATCH'); await dlg.getByRole('button', { name: 'Save changes' }).click(); const x = await r
    if (x.status() >= 300) throw new Error(String(x.status()))
  })
  await step(page, 'outreach: submit for approval -> approve control appears', async () => {
    const r = post('/api/gtm/outreach/'); await dlg.getByRole('button', { name: 'Submit for approval' }).click(); const x = await r; if (x.status() >= 300) throw new Error('submit ' + x.status() + ' ' + (await x.text()).slice(0, 150))
    await page.waitForTimeout(1500); await dlg.getByRole('button', { name: /Approve/ }).first().waitFor()
  })
  await step(page, 'outreach: editing a pending message warns it withdraws approval', async () => {
    const txt = await dlg.innerText(); if (!/withdraws/i.test(txt)) throw new Error('no withdraw warning')
  })
  await step(page, 'outreach: cancel message', async () => {
    const r = post('/api/gtm/outreach/'); await dlg.getByRole('button', { name: 'Cancel message' }).click(); const x = await r; if (x.status() >= 300) throw new Error(String(x.status()))
  })
  await closeDlg()
  await step(page, 'outreach: suppression tab add + list + remove', async () => {
    await page.getByRole('button', { name: 'Suppression list' }).click(); await page.waitForTimeout(1500)
    await page.locator('main input').last().fill('verify-e2e.example.invalid')
    const r = post('/api/gtm/suppressions'); await page.getByRole('button', { name: /^Add/ }).last().click(); const x = await r; if (x.status() >= 300) throw new Error('add ' + x.status() + ' ' + (await x.text()).slice(0, 150))
    await page.getByText('verify-e2e.example.invalid').first().waitFor()
    const d = post('/api/gtm/suppressions', 'DELETE'); await page.getByRole('button', { name: /Remove/ }).first().click(); const y = await d; if (y.status() >= 300) throw new Error('remove ' + y.status())
  })

  await go(page, 'deals')
  await step(page, 'deals: open deal, edit amount + forecast category, persists', async () => {
    await page.getByText('Verify E2E Deal').first().click(); await dlg.waitFor()
    await dlg.getByRole('button', { name: /^Edit/ }).first().click()
    await dlg.getByPlaceholder(/amount/i).first().fill('65000'); await dlg.locator('select').filter({ hasText: /commit/ }).first().selectOption('commit')
    const r = post('/api/gtm/deals/', 'PATCH'); await dlg.getByRole('button', { name: /^Save/ }).first().click(); const x = await r; if (x.status() >= 300) throw new Error('PATCH ' + x.status() + ' ' + (await x.text()).slice(0, 200))
    await page.waitForTimeout(1200); if (!/65,?000/.test(await dlg.innerText())) throw new Error('amount not shown after save')
  })
  await closeDlg()

  await go(page, 'forecast')
  await step(page, 'forecast: shows deal in a per-currency block, weighted state honest', async () => {
    await page.getByText(/INR/).first().waitFor(); const t = await page.locator('main').innerText()
    if (!/(not configured|Weighted)/i.test(t)) throw new Error('weighted state missing')
  })
  await step(page, 'forecast: settings dialog rejects probability > 100 (no save)', async () => {
    await page.getByRole('button', { name: 'Settings' }).click(); await dlg.waitFor(); await page.waitForTimeout(1500)
    const first = dlg.locator('input[type=number]').first(); await first.fill('150')
    await dlg.getByRole('button', { name: /^Save/ }).click(); await page.waitForTimeout(800)
    if (!/100|between|invalid|must/i.test(await dlg.innerText())) throw new Error('no validation message')
  })
  await closeDlg()

  await go(page, 'campaigns')
  await step(page, 'campaigns: create + appears', async () => {
    await page.getByRole('button', { name: 'New campaign', exact: true }).click(); await dlg.waitFor()
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E Campaign')
    const r = post('/api/gtm/campaigns'); await dlg.getByRole('button', { name: 'Create campaign' }).click(); const x = await r; if (x.status() >= 300) throw new Error('POST ' + x.status() + ' ' + (await x.text()).slice(0, 200))
    await page.getByText('Verify E2E Campaign').first().waitFor()
  })
  await step(page, 'campaigns: segment builder - create segment', async () => {
    await page.getByRole('button', { name: 'Segments', exact: true }).click(); await page.waitForTimeout(1500)
    await page.getByRole('button', { name: /New segment/ }).click(); await dlg.waitFor()
    await dlg.locator('input[type=text], input:not([type])').first().fill('Verify E2E Segment')
    const r = post('/api/gtm/segments'); await dlg.getByRole('button', { name: /^(Save|Create)/ }).first().click(); const x = await r; if (x.status() >= 300) throw new Error('POST ' + x.status() + ' ' + (await x.text()).slice(0, 200))
  })
  await closeDlg()

  await go(page, 'workflows')
  await step(page, 'workflows: new workflow dialog is built from the catalog', async () => {
    await page.getByRole('button', { name: 'New workflow' }).click(); await dlg.waitFor(); await page.waitForTimeout(800)
    const t = await dlg.innerText(); if (!/Account created|Deal created|trigger/i.test(t)) throw new Error('trigger list missing')
    await page.screenshot({ path: __dirname + '/shots/wf-builder.png' })
  })
  await closeDlg()

  await go(page, 'reports')
  await step(page, 'reports: list renders and a report loads', async () => {
    await page.locator('main button').first().waitFor(); const n = await page.locator('main button').count(); if (n < 3) throw new Error('only ' + n + ' report buttons')
    await page.locator('main button').first().click(); await page.waitForTimeout(2500)
    const t = await page.locator('main').innerText(); if (!/Apply filters|rows|No rows|Export/i.test(t)) throw new Error('report body missing')
  })
  await step(page, 'reports: CSV export downloads a real file', async () => {
    const dl = page.waitForEvent('download', { timeout: 60000 }); await page.getByRole('button', { name: /Export|Download|CSV/ }).first().click(); const d = await dl
    const fs = require('fs'); const p = __dirname + '/shots/' + d.suggestedFilename(); await d.saveAs(p); const body = fs.readFileSync(p, 'utf8'); if (body.length < 5) throw new Error('empty file')
    console.log('   downloaded', d.suggestedFilename(), body.split('\n').length, 'lines')
  })

  await go(page, 'customers')
  await step(page, 'customers: tabs render', async () => {
    for (const t of ['Renewals', 'Alerts', 'Customer Success agent', 'Customers']) { await page.getByRole('button', { name: t, exact: true }).click(); await page.waitForTimeout(1500) }
  })

  console.log(`\n${pass} passed, ${fail} failed; pageerrors=${JSON.stringify(log.pageerrors)}; console.errors=${log.console.length} ${JSON.stringify(log.console.slice(0, 3))}`)
  await b.close()
})()
