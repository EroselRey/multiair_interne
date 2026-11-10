const {chromium} = require('playwright');
(async () => {
  const times = process.argv.slice(2).map(Number);
  const b = await chromium.launch();
  const p = await b.newPage({viewport: {width: 1920, height: 1080}, deviceScaleFactor: 1});
  p.on('pageerror', (e) => console.log('ERR', e.message));
  p.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') console.log('CONSOLE', m.text()); });
  await p.goto('file://' + __dirname + '/index.html');
  await p.evaluate(() => document.fonts.ready);
  for (const t of times) {
    await p.evaluate((t) => window.seekTo(t), t);
    await p.screenshot({path: `prev_${String(t).replace('.', '_')}.png`});
  }
  console.log('SFX', await p.evaluate(() => window.SFX.length));
  await b.close();
})();
