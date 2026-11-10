const {chromium} = require('playwright');
const {spawn} = require('child_process');
(async () => {
  const FPS = 30;
  const b = await chromium.launch();
  const p = await b.newPage({viewport: {width: 1920, height: 1080}, deviceScaleFactor: 1});
  p.on('pageerror', (e) => console.log('ERR', e.message));
  await p.goto('file://' + __dirname + '/index.html');
  await p.evaluate(() => document.fonts.ready);
  const duree = await p.evaluate(() => window.DUREE);
  const n = Math.round(duree * FPS);
  const ff = spawn('ffmpeg', ['-loglevel', 'error', '-y', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-', '-i', 'musique.wav',
    '-c:v', 'libx264', '-preset', 'medium', '-crf', '17', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-c:a', 'aac', '-b:a', '192k',
    '-movflags', '+faststart', '-shortest', 'Multiair_demandes_distributeurs.mp4'], {stdio: ['pipe', 'inherit', 'inherit']});
  const t0 = Date.now();
  for (let i = 0; i < n; i++) {
    await p.evaluate((t) => window.seekTo(t), i / FPS);
    const buf = await p.screenshot({type: 'jpeg', quality: 94});
    if (!ff.stdin.write(buf)) await new Promise((r) => ff.stdin.once('drain', r));
    if (i % 300 === 0) console.log(`${i}/${n} images — ${Math.round((Date.now() - t0) / 1000)} s`);
  }
  ff.stdin.end();
  await new Promise((r) => ff.on('close', r));
  console.log('fini', n, 'images en', Math.round((Date.now() - t0) / 1000), 's');
  await b.close();
})();
