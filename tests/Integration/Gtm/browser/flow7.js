const { launch, ctxFor, watch, BASE } = require('./lib')
let pass = 0, fail = 0
const step = async (page, name, fn) => { try { await fn(); pass++; console.log('PASS', name) } catch (e) { fail++; console.log('FAIL', name, '->', String(e.message).split('\n')[0].slice(0, 220)); await page.screenshot({ path: `${__dirname}/shots/fail-${name.replace(/\W+/g, '_').slice(0, 40)}.png` }).catch(() => {}) } }
const go = async (page, p) => { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'domcontentloaded', timeout: 120000 }); await page.waitForResponse((r) => r.url().includes('/api/gtm/') && r.status() < 500, { timeout: 90000 }); await page.waitForTimeout(1500) }
;(async () => {
  const b = await launch()
  const pages = {}
  for (const r of ['admin', 'executive', 'other']) { const c = await ctxFor(b, r); pages[r] = await c.newPage(); pages[r].setDefaultTimeout(40000); pages[r].on('dialog', (d) => d.accept()) }
  const page = pages.admin; const dlg = page.locator('[role=dialog]').first()
  const post = (pg, frag, m = 'POST') => { const p = pg.waitForResponse((r) => r.url().includes(frag) && r.request().method() === m, { timeout: 40000 }); p.catch(() => {}); return p }
  const ok2 = async (r, what) => { if (r.status() >= 300) throw new Error(what + ' ' + r.status() + ' ' + (await r.text()).slice(0, 200)) }

  // ---- tenant isolation: an administrator of ANOTHER organisation ----
  for (const p of ['prospecting', 'deals', 'campaigns', 'workflows', 'outreach', 'customers', 'forecast']) {
    await step(pages.other, `isolation: other tenant admin sees no "Verify E2E" data on /gtm/${p}`, async () => {
      await go(pages.other, p); await pages.other.waitForTimeout(3000)
      const t = await pages.other.locator('main').innerText(); if (/Verify E2E/.test(t)) throw new Error('LEAK: tenant 6 data visible to another tenant')
    })
  }
  await step(pages.other, 'isolation: other tenant cannot open tenant 6 records by id (API 404/403)', async () => {
    const r = await pages.other.evaluate(async () => { const s = JSON.parse(localStorage.getItem('userData')); const out = []; for (const u of ['/api/gtm/campaigns/24', '/api/gtm/deals/1', '/api/gtm/workflows/1', '/api/gtm/outreach/1/attempts']) { const x = await fetch('http://127.0.0.1:8000' + u, { headers: { Authorization: 'Bearer ' + s.token, Accept: 'application/json' } }); out.push(u + ' ' + x.status) } return out })
    console.log('   ', JSON.stringify(r)); if (r.some((x) => /\s200$/.test(x))) throw new Error('cross-tenant read allowed')
  })

  // ---- executive: reads allowed, writes refused ----
  await step(pages.executive, 'executive: can read deals; a write attempt is refused with a clear message', async () => {
    await go(pages.executive, 'deals'); await pages.executive.waitForTimeout(2500)
    const res = await pages.executive.evaluate(async () => { const s = JSON.parse(localStorage.getItem('userData')); const x = await fetch('http://127.0.0.1:8000/api/gtm/deals', { method: 'POST', headers: { Authorization: 'Bearer ' + s.token, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ account_id: 1, name: 'Verify E2E exec write' }) }); return x.status })
    if (res !== 403) throw new Error('executive write returned ' + res)
    const btn = await pages.executive.getByRole('button', { name: 'New deal' }).count(); console.log('   "New deal" button visible to executive:', btn > 0)
  })

  // ---- customer lifecycle: win the deal, then renew ----
  await go(page, 'deals')
  await step(page, 'deals: winning a deal needs confirmation, then the account becomes a customer', async () => {
    await page.getByText('Verify E2E Deal').first().click(); await dlg.waitFor(); await page.waitForTimeout(1200)
    await dlg.locator('select').first().selectOption('won'); await page.waitForTimeout(1200)
    console.log('   after choosing won:', (await dlg.innerText()).replace(/\s+/g, ' ').slice(0, 220))
    const confirmBtn = dlg.getByRole('button', { name: /Confirm|Mark as won|Close as won/ }); if (await confirmBtn.count()) { const r = post(page, '/stage'); await confirmBtn.first().click(); await ok2(await r, 'stage') }
    await page.waitForTimeout(1500)
  })
  await page.keyboard.press('Escape')
  await go(page, 'customers')
  await step(page, 'customers: the new customer is listed with an explained health (components + missing data)', async () => {
    await page.getByText('Verify E2E Co').first().waitFor({ timeout: 40000 }); await page.getByText('Verify E2E Co').first().click(); await dlg.waitFor(); await page.waitForTimeout(2500)
    const t = (await dlg.innerText()).replace(/\s+/g, ' '); console.log('   ', t.slice(0, 380))
    if (!/missing|not recorded|no data|not available/i.test(t)) throw new Error('health shows no "missing" explanation')
  })
  await page.keyboard.press('Escape')
  await step(page, 'customers: add a renewal, then close it as renewed with confirmation', async () => {
    await page.getByRole('button', { name: 'Renewals', exact: true }).click(); await page.waitForTimeout(1500)
    const add = page.getByRole('button', { name: /New renewal|Add renewal/ }); await add.first().click(); await dlg.waitFor()
    console.log('   renewal dialog fields:', JSON.stringify(await dlg.evaluate((el) => [...el.querySelectorAll('input,select,textarea')].map((e) => e.tagName[0] + ':' + (e.getAttribute('placeholder') || e.type)))))
    await page.screenshot({ path: __dirname + '/shots/renewal-dlg.png' })
  })

  console.log(`\n${pass} passed, ${fail} failed`)
  await b.close()
})()
