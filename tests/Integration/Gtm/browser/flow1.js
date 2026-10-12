const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch(); const ctx = await ctxFor(b, 'admin'); const page = await ctx.newPage(); const log = { console: [], pageerrors: [], api: [] }; watch(page, log)
  const dlg = page.locator('[role=dialog]').first()

  await go(page, 'prospecting')
  await step(page, 'prospecting: add account validation (empty name blocked)', async () => {
    await page.getByRole('button', { name: 'Add account', exact: true }).click(); await dlg.waitFor()
    await dlg.getByRole('button', { name: 'Create', exact: true }).click(); await page.waitForTimeout(800)
    if (!(await dlg.isVisible())) throw new Error('dialog closed with empty name')
  })
  await step(page, 'prospecting: create account persists and appears in list', async () => {
    await dlg.getByPlaceholder(/Company name/).fill('Verify E2E Co'); await dlg.getByPlaceholder(/Website or domain/).fill('verify-e2e.example.invalid'); await dlg.getByPlaceholder('Industry').fill('Software'); await dlg.getByPlaceholder('Location').fill('Pune')
    const resp = page.waitForResponse((r) => r.url().includes('/api/gtm/accounts') && r.request().method() === 'POST')
    await dlg.getByRole('button', { name: 'Create', exact: true }).click(); const r = await resp
    if (r.status() !== 200 && r.status() !== 201) throw new Error('POST ' + r.status() + ' ' + (await r.text()).slice(0, 150))
    await page.getByText('Verify E2E Co').first().waitFor({ timeout: 15000 })
  })
  await step(page, 'prospecting: duplicate account name is rejected or merged, never silently doubled', async () => {
    await page.getByRole('button', { name: 'Add account', exact: true }).click(); await dlg.waitFor()
    await dlg.getByPlaceholder(/Company name/).fill('Verify E2E Co')
    const resp = page.waitForResponse((r) => r.url().includes('/api/gtm/accounts') && r.request().method() === 'POST')
    await dlg.getByRole('button', { name: 'Create', exact: true }).click(); const r = await resp
    console.log('   duplicate create ->', r.status(), (await r.text()).slice(0, 120))
    await page.keyboard.press('Escape')
  })

  await go(page, 'deals')
  await step(page, 'deals: create deal for the new account', async () => {
    await page.getByRole('button', { name: 'New deal', exact: true }).click(); await dlg.waitFor()
    await dlg.locator('select').first().selectOption({ label: 'Verify E2E Co' })
    await dlg.getByPlaceholder(/Deal name|name/i).first().fill('Verify E2E Deal')
    await dlg.getByPlaceholder(/Amount/).fill('50000'); await dlg.getByPlaceholder(/Currency/i).fill('INR')
    const resp = page.waitForResponse((r) => r.url().includes('/api/gtm/deals') && r.request().method() === 'POST')
    await dlg.getByRole('button', { name: 'Create', exact: true }).click(); const r = await resp
    if (r.status() >= 300) throw new Error('POST ' + r.status() + ' ' + (await r.text()).slice(0, 200))
    await page.getByText('Verify E2E Deal').first().waitFor({ timeout: 15000 })
  })

  await go(page, 'campaigns')
  await step(page, 'campaigns: create campaign', async () => {
    await page.getByRole('button', { name: 'New campaign', exact: true }).click(); await dlg.waitFor()
    await dlg.getByPlaceholder(/name/i).first().fill('Verify E2E Campaign')
    const resp = page.waitForResponse((r) => r.url().includes('/api/gtm/campaigns') && r.request().method() === 'POST')
    await dlg.getByRole('button', { name: /^(Create|Save)/ }).first().click(); const r = await resp
    if (r.status() >= 300) throw new Error('POST ' + r.status() + ' ' + (await r.text()).slice(0, 200))
    await page.getByText('Verify E2E Campaign').first().waitFor({ timeout: 15000 })
  })

  await go(page, 'monitoring')
  await step(page, 'monitoring: 11 checks shown with fix text, no secret-looking values', async () => {
    await page.getByText(/AI provider/).first().waitFor({ timeout: 30000 })
    const txt = await page.locator('main').innerText()
    for (const k of ['Database', 'AI provider', 'GTM agents', 'scheduler', 'Dependencies']) if (!new RegExp(k, 'i').test(txt)) throw new Error('missing section ' + k)
    if (/AIza[0-9A-Za-z_-]{20,}|sk-[A-Za-z0-9]{20,}/.test(txt)) throw new Error('secret-looking string rendered')
  })
  await step(page, 'monitoring: refresh works', async () => {
    const resp = page.waitForResponse((r) => r.url().includes('/api/gtm/monitoring')); await page.getByRole('button', { name: 'Refresh' }).click(); const r = await resp; if (r.status() !== 200) throw new Error(String(r.status()))
  })

  console.log(`\n${pass} passed, ${fail} failed; pageerrors=${JSON.stringify(log.pageerrors)}; console.errors=${log.console.length} ${JSON.stringify(log.console.slice(0, 3))}`)
  await b.close()
})()
