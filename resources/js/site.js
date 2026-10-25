// Comportamentele siteului public, scrise local în locul scripturilor Odoo/Bootstrap:
// meniuri derulante, meniul mobil, antetul care dispare la derulare, carusel, acordeon, animații la apariție,
// câmpurile condiționate ale formularelor și consimțământul pentru cookie-uri.
import { initConsent } from './site/consent.js';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const desktop = window.matchMedia('(min-width: 992px)');

// ---------- Meniuri derulante ----------
function closeDropdowns(except) {
    document.querySelectorAll('.dropdown-menu.show').forEach((menu) => {
        const owner = menu.closest('.dropdown, .dropup');
        if (owner && owner === except) return;
        menu.classList.remove('show');
        owner?.querySelector('[data-bs-toggle="dropdown"]')?.setAttribute('aria-expanded', 'false');
        owner?.querySelector('[data-bs-toggle="dropdown"]')?.classList.remove('show');
    });
}

function toggleDropdown(toggle, force) {
    const owner = toggle.closest('.dropdown, .dropup');
    const menu = owner?.querySelector(':scope > .dropdown-menu, :scope > .o_mega_menu');
    if (!menu) return;
    const open = force ?? !menu.classList.contains('show');
    closeDropdowns(open ? owner : null);
    menu.classList.toggle('show', open);
    toggle.classList.toggle('show', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-bs-toggle="dropdown"]');
    if (toggle) {
        event.preventDefault();
        toggleDropdown(toggle);
        return;
    }
    if (!event.target.closest('.dropdown-menu')) closeDropdowns(null);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeDropdowns(null);
        closeOffcanvas();
    }
});

// Antetul cu „o_hoverable_dropdown”: pe desktop, meniurile se deschid la trecerea mouse-ului.
document.querySelectorAll('header.o_hoverable_dropdown .navbar-expand-lg .dropdown').forEach((item) => {
    const toggle = item.querySelector('[data-bs-toggle="dropdown"]');
    if (!toggle) return;
    item.addEventListener('mouseenter', () => desktop.matches && toggleDropdown(toggle, true));
    item.addEventListener('mouseleave', () => desktop.matches && toggleDropdown(toggle, false));
});

// ---------- Meniul mobil (offcanvas) ----------
let backdrop = null;
function closeOffcanvas() {
    document.querySelectorAll('.offcanvas.show').forEach((panel) => {
        panel.classList.remove('show');
        document.querySelector(`[data-bs-target="#${panel.id}"]`)?.setAttribute('aria-expanded', 'false');
    });
    backdrop?.remove();
    backdrop = null;
    document.body.style.overflow = '';
}

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-bs-toggle="offcanvas"]');
    if (opener) {
        const panel = document.querySelector(opener.dataset.bsTarget);
        if (!panel) return;
        panel.classList.add('show');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('role', 'dialog');
        opener.setAttribute('aria-expanded', 'true');
        backdrop = document.createElement('div');
        backdrop.className = 'offcanvas-backdrop fade show';
        backdrop.addEventListener('click', closeOffcanvas);
        document.body.appendChild(backdrop);
        document.body.style.overflow = 'hidden';
        panel.querySelector('button, a')?.focus();
        return;
    }
    if (event.target.closest('[data-bs-dismiss="offcanvas"]')) closeOffcanvas();
});

// ---------- Antetul care dispare la derulare în jos și reapare la derulare în sus ----------
const header = document.querySelector('header#top.o_header_disappears');
if (header) {
    const overlay = document.getElementById('wrapwrap')?.classList.contains('o_header_overlay');
    let placeholder = null;
    let lastY = window.scrollY;
    let affixed = false;
    const height = () => header.offsetHeight;
    const onScroll = () => {
        const y = window.scrollY;
        if (y > height() && !affixed) {
            affixed = true;
            if (!overlay) {
                placeholder = document.createElement('div');
                placeholder.style.height = `${height()}px`;
                header.after(placeholder);
            }
            header.classList.add('o_header_affixed', 'o_header_is_scrolled', 'o_header_no_transition');
            header.style.transform = 'translate(0, -100%)';
            requestAnimationFrame(() => header.classList.remove('o_header_no_transition'));
        } else if (y <= 0 && affixed) {
            affixed = false;
            header.classList.remove('o_header_affixed', 'o_header_is_scrolled');
            header.style.transform = '';
            placeholder?.remove();
            placeholder = null;
        } else if (affixed) {
            header.style.transform = y < lastY ? '' : 'translate(0, -100%)';
        }
        lastY = y;
    };
    window.addEventListener('scroll', onScroll, { passive: true });
}

// ---------- Înălțimea „ecran complet”: ca în Odoo, prima secțiune scade înălțimea antetului ----------
function fullScreenHeights() {
    const head = document.querySelector('header#top');
    const overlay = document.getElementById('wrapwrap')?.classList.contains('o_header_overlay');
    document.querySelectorAll('.o_full_screen_height').forEach((section) => {
        if (section.closest('.modal')) return;
        const rect = section.getBoundingClientRect();
        if (rect.height === 0) return;
        const top = rect.top + window.scrollY;
        const offset = !overlay && head && top <= head.offsetHeight + 1 ? head.offsetHeight : 0;
        section.style.setProperty('min-height', `${window.innerHeight - offset}px`, 'important');
    });
}
fullScreenHeights();
window.addEventListener('resize', fullScreenHeights, { passive: true });

// ---------- Text evidențiat (o_text_highlight): liniile desenate de Odoo deasupra/dedesubtul textului ----------
const SVG = 'http://www.w3.org/2000/svg';
function drawHighlights() {
    document.querySelectorAll('.o_text_highlight').forEach((el) => {
        el.querySelector(':scope > svg.o_text_highlight_svg')?.remove();
        const kind = Array.from(el.classList).find((c) => c.startsWith('o_text_highlight_'));
        if (!kind) return;
        const box = el.getBoundingClientRect();
        if (box.width === 0) return;
        const svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('class', 'o_text_highlight_svg o_content_no_merge position-absolute overflow-visible pe-none');
        svg.setAttribute('aria-hidden', 'true');
        svg.style.inset = '0';
        const line = (x, y, w) => {
            const p = document.createElementNS(SVG, 'path');
            p.setAttribute('stroke-width', 'var(--text-highlight-width)');
            p.setAttribute('stroke', 'var(--text-highlight-color)');
            p.setAttribute('stroke-linecap', 'round');
            p.setAttribute('d', `M ${x},${y} h ${w}`);
            svg.appendChild(p);
        };
        const range = document.createRange();
        range.selectNodeContents(el);
        Array.from(range.getClientRects()).forEach((r) => {
            const x = r.left - box.left;
            const y = r.top - box.top;
            if (kind === 'o_text_highlight_over_underline') line(x, y + 1, r.width);
            line(x, y + r.height, r.width);
        });
        el.prepend(svg);
    });
}
if (document.fonts?.ready) document.fonts.ready.then(drawHighlights);
else window.addEventListener('load', drawHighlights);
window.addEventListener('resize', () => requestAnimationFrame(drawHighlights), { passive: true });

// ---------- Animații la apariție (o_animate) ----------
const animated = document.querySelectorAll('.o_animate');
if (reducedMotion || !('IntersectionObserver' in window)) {
    animated.forEach((el) => {
        el.style.visibility = 'visible';
        el.style.animationName = 'none';
    });
} else {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const el = entry.target;
            observer.unobserve(el);
            el.style.visibility = 'visible';
            el.classList.add('o_animating');
            el.addEventListener('animationend', () => {
                el.classList.remove('o_animating');
                el.classList.add('o_animated');
            }, { once: true });
        });
    }, { rootMargin: '0px 0px -5% 0px' });
    animated.forEach((el) => observer.observe(el));
}

// ---------- Carusel (testimoniale) ----------
document.querySelectorAll('.carousel').forEach((carousel) => {
    const items = () => Array.from(carousel.querySelectorAll('.carousel-item'));
    const indicators = () => Array.from(carousel.querySelectorAll('[data-bs-slide-to]'));
    let busy = false;
    const go = (targetIndex, direction) => {
        const list = items();
        const current = list.findIndex((i) => i.classList.contains('active'));
        const next = (targetIndex + list.length) % list.length;
        if (busy || next === current || current < 0) return;
        const from = list[current];
        const to = list[next];
        const dir = direction ?? (next > current ? 'next' : 'prev');
        const setIndicators = () => indicators().forEach((b, i) => {
            b.classList.toggle('active', i === next);
            b.setAttribute('aria-current', i === next ? 'true' : 'false');
        });
        if (reducedMotion) {
            from.classList.remove('active');
            to.classList.add('active');
            setIndicators();
            return;
        }
        busy = true;
        const order = dir === 'next' ? 'carousel-item-next' : 'carousel-item-prev';
        const side = dir === 'next' ? 'carousel-item-start' : 'carousel-item-end';
        to.classList.add(order);
        void to.offsetWidth;
        from.classList.add(side);
        to.classList.add(side);
        const done = () => {
            to.classList.remove(order, side);
            to.classList.add('active');
            from.classList.remove('active', side);
            busy = false;
        };
        to.addEventListener('transitionend', done, { once: true });
        setTimeout(() => busy && done(), 900);
        setIndicators();
    };
    carousel.addEventListener('click', (event) => {
        const control = event.target.closest('[data-bs-slide], [data-bs-slide-to]');
        if (!control) return;
        event.preventDefault();
        const current = items().findIndex((i) => i.classList.contains('active'));
        if (control.dataset.bsSlideTo !== undefined) go(Number(control.dataset.bsSlideTo));
        else go(current + (control.dataset.bsSlide === 'prev' ? -1 : 1), control.dataset.bsSlide === 'prev' ? 'prev' : 'next');
    });
    const interval = Number(carousel.dataset.bsInterval || 5000);
    if (!reducedMotion && carousel.dataset.bsRide && interval > 0) {
        let timer = setInterval(() => go(items().findIndex((i) => i.classList.contains('active')) + 1, 'next'), interval);
        carousel.addEventListener('mouseenter', () => clearInterval(timer));
        carousel.addEventListener('mouseleave', () => {
            clearInterval(timer);
            timer = setInterval(() => go(items().findIndex((i) => i.classList.contains('active')) + 1, 'next'), interval);
        });
    }
});

// ---------- Acordeon / secțiuni pliabile ----------
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-bs-toggle="collapse"]');
    if (!toggle) return;
    event.preventDefault();
    const target = document.querySelector(toggle.dataset.bsTarget || toggle.getAttribute('href'));
    if (!target) return;
    const open = !target.classList.contains('show');
    const parent = target.dataset.bsParent ? document.querySelector(target.dataset.bsParent) : null;
    if (open && parent) {
        parent.querySelectorAll('.collapse.show').forEach((other) => {
            other.classList.remove('show');
            document.querySelectorAll(`[data-bs-target="#${other.id}"]`).forEach((t) => {
                t.classList.add('collapsed');
                t.setAttribute('aria-expanded', 'false');
            });
        });
    }
    target.classList.toggle('show', open);
    toggle.classList.toggle('collapsed', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
});

// ---------- Formulare: câmpuri afișate în funcție de modalitatea de contact aleasă ----------
document.querySelectorAll('form[data-site-form]').forEach((form) => {
    const update = () => {
        const chosen = Array.from(form.querySelectorAll('input[name="contact[]"]:checked')).map((c) => c.value);
        form.querySelectorAll('[data-visibility-dependency]').forEach((field) => {
            const condition = (field.dataset.visibilityCondition || '').toLowerCase();
            const visible = chosen.includes(condition === 'e-mail' ? 'email' : condition);
            field.classList.toggle('d-none', !visible);
        });
    };
    form.addEventListener('change', update);
    update();
});

// ---------- Fereastra de anunț (s_popup), afișată după întârzierea configurată în Odoo ----------
document.querySelectorAll('.s_popup .modal[data-display="afterDelay"]').forEach((modal) => {
    const key = `popup:${modal.closest('.s_popup')?.id || 'anunt'}`;
    let dismissed = false;
    try { dismissed = sessionStorage.getItem(key) === '1'; } catch { /* stocare indisponibilă */ }
    if (dismissed) return;
    const show = () => {
        modal.style.display = 'block';
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('role', 'dialog');
    };
    const hide = () => {
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        try { sessionStorage.setItem(key, '1'); } catch { /* stocare indisponibilă */ }
    };
    modal.querySelectorAll('.js_close_popup').forEach((btn) => {
        btn.setAttribute('role', 'button');
        btn.setAttribute('tabindex', '0');
        btn.addEventListener('click', hide);
        btn.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && hide());
    });
    modal.addEventListener('click', (e) => e.target === modal && hide());
    document.addEventListener('keydown', (e) => e.key === 'Escape' && modal.classList.contains('show') && hide());
    setTimeout(show, Number(modal.dataset.showAfter || 3000));
});

// ---------- Fundal video (o_background_video): se încarcă după pagină, ca să nu întârzie afișarea ----------
const saveData = navigator.connection?.saveData;
if (!reducedMotion && !saveData) {
    window.addEventListener('load', () => setTimeout(() => {
        document.querySelectorAll('.o_background_video[data-bg-video-src]').forEach((section) => {
            let src = section.dataset.bgVideoSrc;
            try {
                const url = new URL(src, location.href);
                if (!/(^|\.)youtube\.com$/.test(url.hostname)) return;
                url.hostname = 'www.youtube-nocookie.com';
                src = url.toString();
            } catch { return; }
            const wrap = document.createElement('div');
            wrap.className = 'o_bg_video_container';
            wrap.setAttribute('aria-hidden', 'true');
            const iframe = document.createElement('iframe');
            iframe.className = 'o_bg_video_iframe fade';
            iframe.src = src;
            iframe.title = 'Video de fundal';
            iframe.tabIndex = -1;
            iframe.allow = 'autoplay';
            iframe.setAttribute('frameborder', '0');
            iframe.addEventListener('load', () => iframe.classList.add('show'));
            wrap.appendChild(iframe);
            section.prepend(wrap);
            const resize = () => {
                // Acoperă secțiunea păstrând raportul 16:9, ca în Odoo.
                const w = section.offsetWidth;
                const h = section.offsetHeight;
                const ratio = 16 / 9;
                const width = Math.max(w, h * ratio);
                iframe.style.width = `${width}px`;
                iframe.style.height = `${width / ratio}px`;
                iframe.style.left = `${(w - width) / 2}px`;
                iframe.style.top = `${(h - width / ratio) / 2}px`;
            };
            resize();
            window.addEventListener('resize', resize, { passive: true });
        });
    }, 1500));
}

initConsent();
