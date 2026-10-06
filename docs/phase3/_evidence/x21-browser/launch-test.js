// THE GATING QUESTION: does Chromium launch headless on this Windows box?
const { chromium } = require('playwright');
(async () => {
  try {
    const b = await chromium.launch({ headless: true });
    const p = await b.newPage();
    await p.setContent('<h1 id="x">hello</h1>');
    const text = await p.textContent('#x');
    const v = b.version();
    await b.close();
    console.log('LAUNCH: OK   chromium ' + v + '   rendered: "' + text + '"');
  } catch (e) {
    console.log('LAUNCH: FAILED');
    console.log(String(e.message).split('\n').slice(0, 6).join('\n'));
  }
})();
