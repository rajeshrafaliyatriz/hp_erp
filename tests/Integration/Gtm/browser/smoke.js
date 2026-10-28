const { launch, ctxFor, watch, BASE } = require('./lib')
const pages = ['command-center', 'prospecting', 'playbooks', 'agents', 'deals', 'outreach', 'forecast', 'campaigns', 'customers', 'workflows', 'reports', 'monitoring']
;(async () => {
  const role = process.argv[2] || 'admin'
  const b = await launch(); const ctx = await ctxFor(b, role)
  for (const p of pages) {
    const page = await ctx.newPage(); const log = { console: [], pageerrors: [], api: [] }; watch(page, log)
    let err = ''
    try { await page.goto(`${BASE}/gtm/${p}`, { waitUntil: 'networkidle', timeout: 45000 }) } catch (e) { err = String(e).slice(0, 100) }
    await page.waitForTimeout(800)
    const text = (await page.locator('main, body').first().innerText().catch(() => '')).replace(/\s+/g, ' ')
    const bad = log.api.filter((x) => !x.startsWith('2'))
    console.log(`\n## ${role} /gtm/${p} url=${page.url().replace(BASE, '')} ${err}`)
    console.log('  api:', log.api.filter((x) => x.includes('/gtm/')).length, 'gtm calls; non-2xx:', JSON.stringify(bad))
    console.log('  pageerrors:', JSON.stringify(log.pageerrors), 'console.errors:', log.console.length, JSON.stringify(log.console.slice(0, 2)))
    console.log('  text:', text.slice(0, 260))
    await page.screenshot({ path: `${__dirname}/shots/${role}-${p}.png` }).catch(() => {})
    await page.close()
  }
  await b.close()
})()
