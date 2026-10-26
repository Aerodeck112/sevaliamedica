// Salvează markupul randat de JavaScript (fragmente dinamice Odoo, ex. s_blog_posts) de pe siteul original,
// pentru a reproduce identic structura lor. Utilizare: node tools/audit/rendered_snippets.js <director-iesire>
const { chromium } = require('playwright');
const fs = require('fs');
const out = process.argv[2];
const pages = ['/', '/servicii', '/blog/blog-sevalia-1'];
(async () => {
    const browser = await chromium.launch({ proxy: { server: process.env.HTTPS_PROXY } });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ro-RO', ignoreHTTPSErrors: true });
    await ctx.addCookies([{ name: 'frontend_lang', value: 'ro_RO', domain: 'www.sevalia.ro', path: '/' }]);
    const page = await ctx.newPage();
    fs.mkdirSync(out, { recursive: true });
    const result = {};
    for (const p of pages) {
        await page.goto('https://www.sevalia.ro' + p, { waitUntil: 'networkidle', timeout: 60000 });
        await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 100)); } });
        await page.waitForTimeout(1500);
        result[p] = await page.evaluate(() => Array.from(document.querySelectorAll('[data-snippet="s_blog_posts"], .s_dynamic_snippet_blog_posts, .s_dynamic')).map((e) => e.outerHTML));
        fs.writeFileSync(`${out}/rendered-${p === '/' ? 'home' : p.replace(/\//g, '_')}.html`, await page.content());
    }
    fs.writeFileSync(`${out}/dynamic-snippets.json`, JSON.stringify(result, null, 1));
    await browser.close();
})();
