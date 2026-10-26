// Afișează markupul randat (după JavaScript) al elementelor care se potrivesc unui selector, pe siteul original.
//   node tools/audit/rendered_elements.cjs /kinetoterapie ".o_text_highlight" [desktop|mobil]
const { chromium } = require('playwright');
const [, , pagePath, selector, vp = 'desktop'] = process.argv;
(async () => {
    const browser = await chromium.launch({ proxy: { server: process.env.HTTPS_PROXY } });
    const opts = vp === 'mobil' ? { viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 } : { viewport: { width: 1440, height: 900 } };
    const ctx = await browser.newContext({ ...opts, locale: 'ro-RO', ignoreHTTPSErrors: true });
    await ctx.addCookies([{ name: 'frontend_lang', value: 'ro_RO', domain: 'www.sevalia.ro', path: '/' }]);
    const page = await ctx.newPage();
    await page.goto('https://www.sevalia.ro' + pagePath, { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(2000);
    const out = await page.evaluate((sel) => Array.from(document.querySelectorAll(sel)).slice(0, 5).map((e) => {
        const cs = getComputedStyle(e);
        return { html: e.outerHTML.slice(0, 3000), minHeight: cs.minHeight, height: e.getBoundingClientRect().height, style: e.getAttribute('style') };
    }), selector);
    console.log(JSON.stringify(out, null, 1));
    await browser.close();
})();
