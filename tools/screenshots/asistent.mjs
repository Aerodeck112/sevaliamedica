// Capturi ale asistentului Sevalia (desktop și mobil), cu întrebări demonstrative.
//   node tools/screenshots/asistent.mjs http://127.0.0.1:8000 docs/capturi/etapa-asistent
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const [base = 'http://127.0.0.1:8000', out = 'docs/capturi/etapa-asistent'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const consent = { name: 'sevalia_consent', value: encodeURIComponent('{"v":1,"s":0,"m":0,"t":1}'), url: base };
for (const [suffix, viewport, mobile] of [['desktop', { width: 1440, height: 900 }], ['mobil', { width: 390, height: 844 }, true]]) {
    const ctx = await browser.newContext({ viewport, isMobile: !!mobile, deviceScaleFactor: mobile ? 2 : 1, locale: 'ro-RO' });
    await ctx.addCookies([consent]);
    const page = await ctx.newPage();
    await page.goto(base + '/kinetoterapie', { waitUntil: 'networkidle' });
    await page.evaluate(() => document.querySelectorAll('.s_popup').forEach((p) => p.remove()));
    await page.screenshot({ path: `${out}/01-buton-${suffix}.png` });
    await page.click('.sv-assist__fab');
    await page.screenshot({ path: `${out}/02-deschis-${suffix}.png` });
    for (const q of ['Faceți kinetoterapie la copii?', 'Care este programul?', 'Cât costă kinetoterapia?']) {
        await page.fill('#sv-assist-q', q);
        await page.press('#sv-assist-q', 'Enter');
        await page.waitForFunction(() => !document.querySelector('.sv-assist__msg--wait'));
        await page.waitForTimeout(300);
    }
    await page.screenshot({ path: `${out}/03-conversatie-${suffix}.png` });
    await page.fill('#sv-assist-q', 'Am o durere puternică în piept');
    await page.press('#sv-assist-q', 'Enter');
    await page.waitForFunction(() => !document.querySelector('.sv-assist__msg--wait'));
    await page.screenshot({ path: `${out}/04-urgenta-${suffix}.png` });
    await ctx.close();
}
await browser.close();
console.log('Capturi în', out);
