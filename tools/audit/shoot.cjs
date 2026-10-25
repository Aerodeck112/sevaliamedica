const { chromium } = require('playwright');
const fs=require('fs');
const [,, listFile, outDir] = process.argv;
const urls = fs.readFileSync(listFile,'utf8').split('\n').filter(Boolean);
(async () => {
  const browser = await chromium.launch({ proxy: { server: process.env.HTTPS_PROXY }, args:['--ignore-certificate-errors-spki-list'] });
  const vps = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844, isMobile:true, deviceScaleFactor:2 } };
  for (const [vn, vp] of Object.entries(vps)) {
    const ctx = await browser.newContext({ viewport: {width:vp.width,height:vp.height}, isMobile: !!vp.isMobile, deviceScaleFactor: vp.deviceScaleFactor||1, locale:'ro-RO', ignoreHTTPSErrors:true, extraHTTPHeaders:{'Accept-Language':'ro-RO,ro'} });
    await ctx.addCookies([{name:'frontend_lang',value:'ro_RO',domain:'www.sevalia.ro',path:'/'}]);
    const page = await ctx.newPage();
    for (const u of urls) {
      const slug = (new URL(u).pathname.replace(/^\/|\/$/g,'').replace(/\//g,'__')) || 'home';
      const f = `${outDir}/${vn}/${slug}.png`;
      if (fs.existsSync(f)) continue;
      fs.mkdirSync(`${outDir}/${vn}`,{recursive:true});
      try {
        await page.goto(u, { waitUntil: 'networkidle', timeout: 60000 });
        await page.evaluate(async () => { for (let y=0;y<document.body.scrollHeight;y+=600){window.scrollTo(0,y);await new Promise(r=>setTimeout(r,120));} window.scrollTo(0,0); });
        await page.waitForTimeout(1200);
        await page.screenshot({ path: f, fullPage: true });
        console.log('ok', vn, slug);
      } catch (e) { console.log('ERR', vn, slug, e.message.split('\n')[0]); }
    }
    await ctx.close();
  }
  await browser.close();
})();
