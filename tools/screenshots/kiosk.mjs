// Capturi ale tabletelor de recepție (consimțământ + recenzie), pe date DEMONSTRATIVE.
//   node tools/screenshots/kiosk.mjs http://127.0.0.1:8000 docs/capturi/etapa-tablete
// Folosește tools/screenshots/kiosk-pregatire.php pentru codurile de asociere și cererile demo.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const [base = 'http://127.0.0.1:8000', out = 'docs/capturi/etapa-tablete'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const prep = (...a) => JSON.parse(execFileSync('php', ['tools/screenshots/kiosk-pregatire.php', ...a], { encoding: 'utf8' }).trim().split('\n').pop());

const devices = prep('tablete');
const byMode = (m) => devices.find((d) => d.mode === m);
const browser = await chromium.launch();
const tablet = { viewport: { width: 1180, height: 820 }, deviceScaleFactor: 1, hasTouch: true, locale: 'ro-RO' };
const shot = (page, name) => page.screenshot({ path: `${out}/${name}.png`, fullPage: true });

async function paired(mode) {
    const ctx = await browser.newContext(tablet);
    const page = await ctx.newPage();
    await page.goto(base + '/tableta');
    if (mode === 'consimtamant') await shot(page, '01-asociere');
    await page.fill('#pairing_code', prep('asociere', String(byMode(mode).id)).cod);
    await page.click('button[type=submit]');
    await page.waitForURL('**/tableta');
    return page;
}

// Tableta 1: consimțământ cu cod de 6 cifre și semnătură pe ecran.
let page = await paired('consimtamant');
await shot(page, '02-consimtamant-asteptare-cod');
const { cod } = prep('consimtamant');
for (const d of cod.slice(0, 5)) await page.click(`[data-key="${d}"]`);
await shot(page, '03-cod-introdus');
await page.click(`[data-key="${cod[5]}"]`); // a 6-a cifră trimite automat codul
await page.waitForSelector('[data-kiosk-sign]');
await page.check('input[name=agree]');
await page.locator('canvas').scrollIntoViewIfNeeded();
const box = await page.locator('canvas').boundingBox();
await page.mouse.move(box.x + 60, box.y + box.height * 0.6);
await page.mouse.down();
for (let i = 0; i <= 40; i++) {
    await page.mouse.move(box.x + 60 + i * 9, box.y + box.height * (0.6 - 0.25 * Math.sin(i / 3)), { steps: 2 });
}
await page.mouse.up();
await shot(page, '04-consimtamant-semnare');
await page.click('button[value=semnez]');
await page.waitForURL('**/multumim**');
await page.waitForTimeout(1500);
await shot(page, '05-consimtamant-multumim');

// Tableta 2: recenzie trimisă de recepție direct pe tabletă (se deschide singură).
page = await paired('recenzie');
await shot(page, '06-recenzii-asteptare');
prep('recenzie', String(byMode('recenzie').id));
await page.waitForSelector('[data-kiosk-stars]', { timeout: 20000 });
await shot(page, '07-recenzie-formular');
await page.click('label[for=star5]');
await page.fill('textarea[name=comment]', 'Text demonstrativ: echipă atentă și programare la timp.');
await shot(page, '08-recenzie-5-stele');
await page.click('.k-actions button[type=submit]');
await page.waitForURL('**/multumim**');
await page.waitForTimeout(1500);
await shot(page, '09-recenzie-multumim');

await browser.close();
console.log('Capturi în', out);
