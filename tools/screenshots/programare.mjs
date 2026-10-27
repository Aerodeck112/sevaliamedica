// Capturi ale programării online, pas cu pas, pe date DEMONSTRATIVE (inclusiv trimiterea).
//   node tools/screenshots/programare.mjs http://127.0.0.1:8000 docs/capturi/etapa-programare
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const [base = 'http://127.0.0.1:8000', out = 'docs/capturi/etapa-programare'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const consent = { name: 'sevalia_consent', value: encodeURIComponent('{"v":1,"s":0,"m":0,"t":1}'), url: base };

for (const [suffix, viewport, mobile] of [['desktop', { width: 1440, height: 900 }], ['mobil', { width: 390, height: 844 }, true]]) {
    const ctx = await browser.newContext({ viewport, isMobile: !!mobile, deviceScaleFactor: mobile ? 2 : 1, locale: 'ro-RO' });
    await ctx.addCookies([consent]);
    const page = await ctx.newPage();
    const shot = (n) => page.screenshot({ path: `${out}/${n}-${suffix}.png`, fullPage: true });
    await page.goto(base + '/programare-online');
    await shot('01-serviciu');
    await page.click('.sv-booking__option');
    if (await page.locator('.sv-booking__chip[href*="locatie"]').count() && !(await page.locator('.sv-booking__day').count())) {
        await page.click('.sv-booking__chip[href*="locatie"]');
    }
    await shot('02-zi-si-ora');
    await page.click('.sv-booking__time');
    await shot('03-date');
    if (!mobile) {
        await page.waitForTimeout(3200); // tokenul refuză trimiterile mai rapide de 3 s (roboți)
        await page.click('.sv-booking__submit');
        await shot('04-erori-validare');
        await page.fill('#b_name', 'Pacient Demo Fictiv');
        await page.fill('#b_phone', '0700 000 000');
        await page.fill('#b_email', 'pacient@demo.sevalia.invalid');
        await page.check('input[name=consent]');
        await page.waitForTimeout(3200); // tokenul refuză trimiterile mai rapide de 3 s (roboți)
        await page.click('.sv-booking__submit');
        await page.waitForURL('**/contactus-thank-you**');
        await page.evaluate(() => document.querySelectorAll('.s_popup').forEach((p) => p.remove()));
        await shot('05-confirmare');
    }
    await ctx.close();
}
await browser.close();
console.log('Capturi în', out);
