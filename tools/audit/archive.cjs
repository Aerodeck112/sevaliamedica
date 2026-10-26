// Arhivare completă a siteului public înainte de dezafectare.
// Deschide fiecare URL într-un browser real, derulează, așteaptă încărcarea și salvează:
//  - HTML-ul randat (după JavaScript) în <out>/rendered/
//  - fiecare resursă de pe domeniul siteului încărcată de pagină (imagini, fonturi, media, documente) în <out>/files/
//  - un index <out>/index.json: URL resursă → fișier, tip, dimensiune, SHA-256, paginile care o folosesc
//
//   NODE_PATH=$(npm root -g) node tools/audit/archive.cjs <lista-urluri.txt> <director-iesire>
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const [, , listFile, outDir] = process.argv;
const urls = [...new Set(fs.readFileSync(listFile, 'utf8').split('\n').map((s) => s.trim()).filter(Boolean))];
fs.mkdirSync(path.join(outDir, 'rendered'), { recursive: true });
fs.mkdirSync(path.join(outDir, 'files'), { recursive: true });
const indexFile = path.join(outDir, 'index.json');
const index = fs.existsSync(indexFile) ? JSON.parse(fs.readFileSync(indexFile, 'utf8')) : { resources: {}, pages: {} };
const save = () => fs.writeFileSync(indexFile, JSON.stringify(index, null, 1));

const extFor = (ct, u) => {
    const map = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/svg+xml': 'svg', 'image/gif': 'gif',
        'image/x-icon': 'ico', 'image/vnd.microsoft.icon': 'ico', 'font/woff2': 'woff2', 'font/woff': 'woff', 'application/pdf': 'pdf',
        'video/mp4': 'mp4', 'text/css': 'css', 'application/javascript': 'js', 'text/javascript': 'js' };
    const base = (ct || '').split(';')[0].trim();
    return map[base] || (path.extname(new URL(u).pathname).slice(1) || 'bin');
};

(async () => {
    const browser = await chromium.launch({ proxy: { server: process.env.HTTPS_PROXY } });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ro-RO', ignoreHTTPSErrors: true,
        extraHTTPHeaders: { 'Accept-Language': 'ro-RO,ro' } });
    await ctx.addCookies([{ name: 'frontend_lang', value: 'ro_RO', domain: 'www.sevalia.ro', path: '/' }]);
    for (const u of urls) {
        if (index.pages[u]?.ok) continue;
        const page = await ctx.newPage();
        const used = new Set();
        page.on('response', async (res) => {
            try {
                const ru = res.url();
                const host = new URL(ru).host;
                if (!host.endsWith('sevalia.ro')) return;
                const type = res.request().resourceType();
                if (!['image', 'font', 'media', 'stylesheet', 'other', 'fetch', 'xhr'].includes(type)) return;
                if (type === 'fetch' || type === 'xhr') return; // apeluri JSON ale Odoo, nu fișiere
                used.add(ru);
                if (index.resources[ru]) { index.resources[ru].pages = [...new Set([...(index.resources[ru].pages || []), u])]; return; }
                if (res.status() !== 200) return;
                const body = await res.body();
                const sha = crypto.createHash('sha256').update(body).digest('hex');
                const file = `${sha.slice(0, 20)}.${extFor(res.headers()['content-type'], ru)}`;
                fs.writeFileSync(path.join(outDir, 'files', file), body);
                index.resources[ru] = { file, type: res.headers()['content-type'], bytes: body.length, sha256: sha, resourceType: type, pages: [u] };
            } catch (e) { /* corpul unor răspunsuri (redirect) nu e disponibil */ }
        });
        try {
            const resp = await page.goto(u, { waitUntil: 'load', timeout: 90000 });
            await page.evaluate(async () => {
                for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 150)); }
            });
            // Odoo ține deschise conexiuni de fundal: așteptăm „rețea liberă” cel mult 8 s.
            await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
            await page.waitForTimeout(1000);
            const slug = (new URL(page.url()).pathname.replace(/^\/|\/$/g, '').replace(/\//g, '__')) || 'home';
            fs.writeFileSync(path.join(outDir, 'rendered', `${slug}.html`), await page.content());
            index.pages[u] = { ok: (resp?.status() ?? 599) < 500, status: resp?.status(), final: page.url(), rendered: `rendered/${slug}.html`, resources: [...used] };
            console.log('ok', resp?.status(), u, used.size);
        } catch (e) {
            index.pages[u] = { ok: false, error: e.message.split('\n')[0] };
            console.log('ERR', u, e.message.split('\n')[0]);
        }
        await page.close();
        save();
    }
    await browser.close();
    save();
})();
