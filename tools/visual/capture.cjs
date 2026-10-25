// Capturează reconstrucția locală a siteului, la aceleași rezoluții ca referința (docs/audit/referinta).
//   node tools/visual/capture.cjs http://127.0.0.1:8001 storage/app/visual [slug1,slug2,...]
// Slug-urile sunt numele fișierelor din referință (ex. home, kinetoterapie, blog__blog-sevalia-1).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [, , base, out, only] = process.argv;
const refDir = path.join(__dirname, '../../docs/audit/referinta/desktop');
let slugs = fs.readdirSync(refDir).filter((f) => f.endsWith('.jpg')).map((f) => f.slice(0, -4));
if (only) slugs = slugs.filter((s) => only.split(',').includes(s));

const viewports = {
    desktop: { viewport: { width: 1440, height: 900 } },
    mobil: { viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 },
};

(async () => {
    const browser = await chromium.launch();
    for (const [name, opts] of Object.entries(viewports)) {
        const ctx = await browser.newContext({ ...opts, locale: 'ro-RO' });
        const host = new URL(base).hostname;
        // Alegerea de cookie e deja făcută, ca bannerul să nu acopere comparația.
        await ctx.addCookies([{ name: 'sevalia_consent', value: encodeURIComponent('{"v":1,"s":0,"m":0,"t":1}'), domain: host, path: '/' }]);
        const page = await ctx.newPage();
        fs.mkdirSync(path.join(out, name), { recursive: true });
        for (const slug of slugs) {
            const url = base + '/' + (slug === 'home' ? '' : slug.replace(/__/g, '/'));
            try {
                await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
                await page.evaluate(async () => {
                    for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); }
                    window.scrollTo(0, 0);
                });
                await page.waitForTimeout(1500);
                await page.screenshot({ path: path.join(out, name, slug + '.png'), fullPage: true });
                console.log('ok', name, slug);
            } catch (e) {
                console.log('ERR', name, slug, e.message.split('\n')[0]);
            }
        }
        await ctx.close();
    }
    await browser.close();
})();
