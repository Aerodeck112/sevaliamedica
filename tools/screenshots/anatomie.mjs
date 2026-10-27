// Capturi ale schițelor anatomice (copertă + harta corpului), după terminarea animației.
//   node tools/screenshots/anatomie.mjs http://127.0.0.1:8000 docs/capturi/etapa-anatomie
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const [base = 'http://127.0.0.1:8000', out = 'docs/capturi/etapa-anatomie'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const consent = { name: 'sevalia_consent', value: encodeURIComponent('{"v":1,"s":0,"m":0,"t":1}'), url: base };
const shots = [
    ['01-kinetoterapie-desktop', '/kinetoterapie', { width: 1440, height: 900 }],
    ['02-atm-desktop', '/kinetoterapie-atm-articulatia-temporo-mandibulara', { width: 1440, height: 900 }],
    ['03-emsella-desktop', '/emsella-tratament-pentru-incontinenta-urinara', { width: 1440, height: 900 }],
    ['04-kinetoterapie-mobil', '/kinetoterapie', { width: 390, height: 844 }, true],
];
for (const [name, path, viewport, mobile] of shots) {
    const ctx = await browser.newContext({ viewport, isMobile: !!mobile, deviceScaleFactor: mobile ? 2 : 1, locale: 'ro-RO' });
    await ctx.addCookies([consent]);
    const page = await ctx.newPage();
    await page.goto(base + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(4500);
    await page.screenshot({ path: `${out}/${name}.png` });
    await ctx.close();
}
// Harta corpului de pe prima pagină: zona implicită și o zonă aleasă.
for (const [name, viewport, mobile] of [['05-harta-corpului-desktop', { width: 1440, height: 900 }], ['07-harta-corpului-mobil', { width: 390, height: 844 }, true]]) {
    const ctx = await browser.newContext({ viewport, isMobile: !!mobile, deviceScaleFactor: mobile ? 2 : 1, locale: 'ro-RO' });
    await ctx.addCookies([consent]);
    const page = await ctx.newPage();
    await page.goto(base + '/', { waitUntil: 'networkidle' });
    // Fereastra de anunț a siteului original ar acoperi captura.
    await page.evaluate(() => document.querySelectorAll('.s_popup').forEach((p) => p.remove()));
    const map = page.locator('[data-bodymap]');
    await map.scrollIntoViewIfNeeded();
    await page.waitForTimeout(4000);
    await map.screenshot({ path: `${out}/${name}.png` });
    if (!mobile) {
        await page.click('.sv-bodymap__zone[data-zone="pelvin"]');
        await page.waitForTimeout(800);
        await map.screenshot({ path: `${out}/06-harta-corpului-planseu-pelvin.png` });
    }
    await ctx.close();
}
await browser.close();
console.log('Capturi în', out);
