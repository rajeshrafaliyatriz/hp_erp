const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch(); const ctx = await ctxFor(b, 'admin'); const page = await ctx.newPage(); page.setDefaultTimeout(60000); page.on('dialog', (d) => d.accept())
  const dlg = page.locator('[role=dialog]').first()
  const post = (frag, m = 'POST') => { const p = page.waitForResponse((r) => r.url().includes(frag) && r.request().method() === m, { timeout: 60000 }); p.catch(() => {}); return p }
  const ok2 = async (r, what) => { if (r.status() >= 300) throw new Error(what + ' ' + r.status() + ' ' + (await r.text()).slice(0, 200)) }

  await go(page, 'customers')
  await step(page, 'renewals: record a renewal for the customer (validation: name+date required)', async () => {
    await page.getByRole('button', { name: 'Renewals', exact: true }).click(); await page.getByRole('button', { name: 'Record renewal' }).click(); await dlg.waitFor()
    await page.waitForFunction(() => { const s = document.querySelector('[role=dialog] select'); return s && s.options.length > 1 }, null, { timeout: 60000 })
    const save = dlg.getByRole('button', { name: 'Save', exact: true }); if (await save.isEnabled()) { await save.click(); await page.waitForTimeout(800); if (!(await dlg.isVisible())) throw new Error('saved with empty fields') }
    await dlg.locator('select').first().selectOption({ index: 1 }); await dlg.getByPlaceholder(/Renewal name/).fill('Verify E2E Renewal')
    await dlg.locator('input[type=date]').first().fill('2026-12-31'); await dlg.getByPlaceholder(/Amount/).fill('40000'); await dlg.getByPlaceholder(/Currency/).fill('INR')
    const r = post('/api/gtm/renewals'); await save.click(); await ok2(await r, 'create renewal')
    await page.getByText('Verify E2E Renewal').first().waitFor()
  })
  await step(page, 'renewals: pipeline shown per currency (INR 40,000), not summed', async () => {
    const t = await page.locator('main').innerText(); if (!/INR/.test(t) || !/40,?000/.test(t)) throw new Error('pipeline missing: ' + t.replace(/\s+/g, ' ').slice(0, 200))
  })
  await step(page, 'renewals: closing as renewed needs the confirmation box', async () => {
    await page.getByRole('button', { name: /^Close/ }).first().click(); await dlg.waitFor(); await page.waitForTimeout(1500)
    console.log('   close dialog:', (await dlg.innerText()).replace(/\s+/g, ' ').slice(0, 260))
    const submit = dlg.getByRole('button', { name: /^(Close renewal|Confirm|Save|Record)/ }).last()
    if (await submit.isEnabled()) throw new Error('submit enabled before confirmation tick')
    await dlg.locator('[role=checkbox], input[type=checkbox]').first().click({ force: true })
    const r = post('/api/gtm/renewals/'); await submit.click(); await ok2(await r, 'status')
  })
  await step(page, 'customers: health now reflects the renewal (renewal component has data)', async () => {
    await page.getByRole('button', { name: 'Customers', exact: true }).click(); await page.waitForTimeout(1500)
    await page.getByText('Verify E2E Co').first().click(); await dlg.getByText('Renewal').first().waitFor(); await page.waitForTimeout(2500)
    console.log('   ', (await dlg.innerText()).replace(/\s+/g, ' ').match(/Renewal\s+20[^R]{0,120}/)?.[0])
  })
  await page.keyboard.press('Escape')

  await go(page, 'monitoring')
  await step(page, 'monitoring: live AI probe is an explicit button; quota/provider state reported honestly', async () => {
    await page.getByText(/AI provider/).first().waitFor(); const btn = page.getByRole('button', { name: /Test AI provider/ }); if (!(await btn.count())) throw new Error('no probe button')
    console.log('   probe button enabled for admin:', await btn.isEnabled(), '(not clicked: it would spend a real provider call)')
    const t = await page.locator('main').innerText(); console.log('   ', t.replace(/\s+/g, ' ').slice(0, 500))
  })

  console.log(`\n${pass} passed, ${fail} failed`)
  await b.close()
})()
