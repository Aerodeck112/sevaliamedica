// Capturi de ecran pentru documentarea etapelor. Rulează pe un server local cu date DEMONSTRATIVE.
//
//   node tools/screenshots/capture.mjs <fisier-plan.json>
//
// Planul: { "baseUrl": "http://127.0.0.1:8000", "outDir": "docs/capturi/etapa-2",
//           "shots": [ { "name": "login", "path": "/panou/autentificare", "role": null, "viewport": "desktop" },
//                      { "name": "dashboard-receptie", "path": "/panou", "role": "receptie" } ] }
// Rol = prefixul contului demo (<rol>@demo.sevalia.invalid). Viewport: desktop (1440×900) | mobil (390×844).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
let playwright;
try { playwright = require('playwright'); } catch { playwright = require(path.join(process.env.NODE_PATH || '', 'playwright')); }

const PASSWORD = 'Demo-Sevalia-2026';
const MFA_SECRET = 'DEMOSEVALIAMFAXXSECRETKEYABCDEFG';

function base32Decode(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s.replace(/=+$/, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

function totp(secret, offset = 0) {
    const counter = Math.floor(Date.now() / 1000 / 30) + offset;
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(counter));
    const h = crypto.createHmac('sha1', base32Decode(secret)).update(buf).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

const plan = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const viewports = {
    desktop: { viewport: { width: 1440, height: 900 } },
    mobil: { viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 },
};
fs.mkdirSync(plan.outDir, { recursive: true });

const browser = await playwright.chromium.launch();
const contexts = {};

async function contextFor(role, vp, keepConsent = true) {
    const key = `${role || 'anon'}:${vp}:${keepConsent}`;
    if (contexts[key]) return contexts[key];
    const ctx = await browser.newContext({ ...viewports[vp], locale: 'ro-RO' });
    if (keepConsent) {
        // Alegerea de cookie e deja făcută (refuz), ca bannerul să nu acopere captura; „keepConsent: false” îl arată.
        await ctx.addCookies([{ name: 'sevalia_consent', value: encodeURIComponent('{"v":1,"s":0,"m":0,"t":1}'), url: plan.baseUrl }]);
    }
    if (role) {
        const page = await ctx.newPage();
        await page.goto(plan.baseUrl + '/panou/autentificare');
        await page.fill('#f_email', `${role.replace(/_/g, '-')}@demo.sevalia.invalid`);
        await page.fill('#f_password', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        // Un cod TOTP e acceptat o singură dată per cont; la eșec reîncercăm în intervalul următor.
        for (let attempt = 0; attempt < 3; attempt++) {
            await page.fill('#f_code', totp(MFA_SECRET));
            await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
            if (!page.url().includes('/autentificare')) break;
            await page.waitForTimeout(31000 - (Date.now() % 30000));
        }
        if (page.url().includes('/autentificare')) throw new Error('Autentificare eșuată pentru ' + role);
        await page.close();
    }
    contexts[key] = ctx;
    return ctx;
}

for (const shot of plan.shots) {
    const vp = shot.viewport || 'desktop';
    const ctx = await contextFor(shot.role, vp, shot.keepConsent !== false);
    const page = await ctx.newPage();
    await page.goto(plan.baseUrl + shot.path, { waitUntil: 'networkidle' });
    if (!shot.role && shot.fullPage !== false) {
        // Derulăm pagina publică pentru a declanșa animațiile la apariție.
        await page.evaluate(async () => {
            for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 100)); }
            window.scrollTo(0, 0);
        });
        await page.waitForTimeout(800);
    }
    if (shot.actions) {
        for (const a of shot.actions) {
            if (a.fill) await page.fill(a.fill, a.value);
            if (a.click) await page.click(a.click);
            if (a.wait) await page.waitForTimeout(a.wait);
        }
    }
    const file = path.join(plan.outDir, `${shot.name}${vp === 'mobil' ? '-mobil' : ''}.png`);
    await page.screenshot({ path: file, fullPage: shot.fullPage !== false });
    console.log('captură', file);
    await page.close();
}
await browser.close();
