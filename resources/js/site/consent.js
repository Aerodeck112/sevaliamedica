// Consimțământul pentru cookie-uri (decizia clinicii din 2026-10-25).
// - Categorii: necesare (mereu), statistici, marketing.
// - Alegerea se păstrează într-un cookie propriu, fără date personale: {"v":1,"s":0|1,"m":0|1,"t":<timestamp>}.
// - Nicio etichetă opțională nu se încarcă înainte de consimțământ; Google Consent Mode v2 pornește „denied”.
// - Refuzul e la fel de ușor ca acceptarea; „Setări cookie” din subsol redeschide panoul.

const VERSION = 1;
const MAX_AGE = 60 * 60 * 24 * 180; // 6 luni

function readConfig() {
    try {
        return JSON.parse(document.getElementById('site-consent-config')?.textContent || '{}');
    } catch {
        return {};
    }
}

function readChoice(name) {
    const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
    if (!match) return null;
    try {
        const value = JSON.parse(decodeURIComponent(match.slice(name.length + 1)));
        return value && value.v === VERSION ? value : null;
    } catch {
        return null;
    }
}

function writeChoice(name, choice) {
    const value = encodeURIComponent(JSON.stringify({ v: VERSION, s: choice.s ? 1 : 0, m: choice.m ? 1 : 0, t: Math.floor(Date.now() / 1000) }));
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${value}; Max-Age=${MAX_AGE}; Path=/; SameSite=Lax${secure}`;
}

const loaded = new Set();
function loadScript(src) {
    if (loaded.has(src)) return;
    loaded.add(src);
    const nonce = document.querySelector('script[nonce]')?.nonce;
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    if (nonce) s.nonce = nonce;
    document.head.appendChild(s);
}

function applyChoice(config, choice) {
    const granted = { statistici: !!choice.s, marketing: !!choice.m };
    if (typeof window.gtag === 'function') {
        window.gtag('consent', 'update', {
            analytics_storage: granted.statistici ? 'granted' : 'denied',
            ad_storage: granted.marketing ? 'granted' : 'denied',
            ad_user_data: granted.marketing ? 'granted' : 'denied',
            ad_personalization: granted.marketing ? 'granted' : 'denied',
        });
    }
    (config.tags || []).forEach((tag) => {
        if (!granted[tag.category]) return;
        if (tag.type === 'google') {
            loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(tag.id)}`);
            if (!loaded.has(`config:${tag.id}`)) {
                loaded.add(`config:${tag.id}`);
                window.gtag('js', new Date());
                // Fără evenimente de conversie și fără date din formulare.
                window.gtag('config', tag.id, { allow_enhanced_conversions: false });
            }
        } else if (tag.type === 'meta' && !window.fbq) {
            const fbq = function () { fbq.callMethod ? fbq.callMethod.apply(fbq, arguments) : fbq.queue.push(arguments); };
            fbq.queue = [];
            fbq.loaded = true;
            fbq.version = '2.0';
            window.fbq = fbq;
            window._fbq = fbq;
            loadScript('https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', tag.id);
            fbq('track', 'PageView');
        }
    });
}

export function initConsent() {
    const config = readConfig();
    const name = config.cookie || 'sevalia_consent';
    const banner = document.getElementById('sv-cookie');
    if (!banner) return;
    const prefs = banner.querySelector('#sv-cookie-prefs');
    const saveBtn = banner.querySelector('[data-consent="save"]');
    const customizeBtn = banner.querySelector('[data-consent="customize"]');

    const show = (withPrefs) => {
        const current = readChoice(name);
        prefs.querySelector('[name="statistici"]').checked = !!current?.s;
        prefs.querySelector('[name="marketing"]').checked = !!current?.m;
        prefs.hidden = !withPrefs;
        saveBtn.hidden = !withPrefs;
        customizeBtn.setAttribute('aria-expanded', withPrefs ? 'true' : 'false');
        banner.hidden = false;
        banner.querySelector('button')?.focus({ preventScroll: true });
    };
    const decide = (choice) => {
        writeChoice(name, choice);
        banner.hidden = true;
        applyChoice(config, choice);
    };

    banner.addEventListener('click', (event) => {
        const action = event.target.closest('[data-consent]')?.dataset.consent;
        if (action === 'accept') decide({ s: true, m: true });
        if (action === 'reject') decide({ s: false, m: false });
        if (action === 'customize') show(prefs.hidden);
        if (action === 'save') decide({ s: prefs.querySelector('[name="statistici"]').checked, m: prefs.querySelector('[name="marketing"]').checked });
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-cookie-settings], a[href="#setari-cookie"]');
        if (!link) return;
        event.preventDefault();
        show(true);
    });

    const existing = readChoice(name);
    if (existing) applyChoice(config, existing);
    else show(false);
}
