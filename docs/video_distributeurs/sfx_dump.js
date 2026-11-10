const {chromium} = require('playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({viewport: {width: 1920, height: 1080}});
  await p.goto('file://' + __dirname + '/index.html');
  const r = await p.evaluate(() => ({duree: window.DUREE, sfx: window.SFX}));
  require('fs').writeFileSync('sfx.json', JSON.stringify(r));
  console.log(r.duree, r.sfx.length, [...new Set(r.sfx.map((x) => x.k))].join(','));
  await b.close();
})();
