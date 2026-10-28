// Verifică paginile panoului la lățimi de telefon, tabletă și desktop: semnalează elementele care ies din ecran
// (derulare orizontală a întregii pagini). Tabelele dintr-un container cu derulare proprie nu sunt semnalate.
//   node tools/screenshots/depasiri-panou.mjs http://127.0.0.1:8000 <lista-adrese.txt> <director-capturi>
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const [base, listFile, out] = process.argv.slice(2);
const urls = fs.readFileSync(listFile, 'utf8').split('\n').map((s) => s.trim()).filter(Boolean);
fs.mkdirSync(out, { recursive: true });
const PASSWORD = 'Demo-Sevalia-2026';
const SECRET = 'DEMOSEVALIAMFAXXSECRETKEYABCDEFG';
const b32 = (s) => { const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of s) bits += a.indexOf(c).toString(2).padStart(5, '0'); const o = []; for (let i = 0; i + 8 <= bits.length; i += 8) o.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(o); };
const totp = () => { const buf = Buffer.alloc(8); buf.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000))); const h = crypto.createHmac('sha1', b32(SECRET)).update(buf).digest(); const o = h[h.length - 1] & 0xf; return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0'); };

const browser = await chromium.launch();
const roles = ['manager', 'medic', 'receptie', 'administrator-tehnic', 'editor-site'];
const states = {};
for (const role of roles) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ro-RO' });
    const p = await ctx.newPage();
    await p.goto(base + '/panou/autentificare');
    await p.fill('#f_email', `${role}@demo.sevalia.invalid`);
    await p.fill('#f_password', PASSWORD);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    for (let i = 0; i < 3 && p.url().includes('/autentificare'); i++) {
        await p.fill('#f_code', totp());
        await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
        if (p.url().includes('/autentificare')) await p.waitForTimeout(31000 - (Date.now() % 30000));
    }
    states[role] = await ctx.storageState();
    await ctx.close();
}

const viewports = [['mobil', { width: 390, height: 844 }, true], ['tableta', { width: 768, height: 1024 }, true], ['desktop', { width: 1440, height: 900 }, false]];
const report = [];
let checked = 0;
for (const [name, viewport, mobile] of viewports) {
    const ctxs = {};
    for (const role of roles) ctxs[role] = await browser.newContext({ viewport, isMobile: mobile, hasTouch: mobile, locale: 'ro-RO', storageState: states[role] });
    for (const url of urls) {
        let done = false;
        for (const role of roles) {
            const p = await ctxs[role].newPage();
            const resp = await p.goto(base + url, { waitUntil: 'networkidle' }).catch(() => null);
            const status = resp ? resp.status() : 0;
            if (status === 403 || status === 404 || p.url().includes('/autentificare')) { await p.close(); continue; }
            checked++;
            const r = await p.evaluate(() => {
                const vw = document.documentElement.clientWidth;
                const sw = document.documentElement.scrollWidth;
                const fixed = (el) => { for (let a = el; a; a = a.parentElement) if (getComputedStyle(a).position === 'fixed') return true; return false; };
                const clipped = (el) => { for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) { if (/(hidden|auto|scroll|clip)/.test(getComputedStyle(a).overflowX)) { const ar = a.getBoundingClientRect(); if (ar.right <= vw + 2) return true; } } return false; };
                const bad = [];
                for (const el of document.querySelectorAll('body *')) {
                    const rc = el.getBoundingClientRect();
                    if (rc.width && rc.height && rc.right > vw + 2 && !fixed(el) && !clipped(el)) bad.push(el);
                }
                const top = bad.filter((el) => !bad.some((o) => o !== el && o.contains(el)));
                const d = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
                return { vw, sw, items: top.slice(0, 5).map((el) => `${d(el)} (→${Math.round(el.getBoundingClientRect().right)})`) };
            });
            if (r.sw > r.vw + 2 || r.items.length) {
                report.push(`${name} ${url} [${role}] lățime ${r.sw}/${r.vw}\n    ${r.items.join('\n    ')}`);
                await p.screenshot({ path: `${out}/${name}${url.replace(/[^a-z0-9]+/gi, '_')}.png` });
            }
            await p.close();
            done = true;
            break;
        }
        if (!done) report.push(`${name} ${url}: nicio rol nu are acces (sărit)`);
    }
    for (const c of Object.values(ctxs)) await c.close();
}
console.log(`verificate: ${checked}`);
console.log(report.length ? report.join('\n') : 'nicio depășire');
await browser.close();
