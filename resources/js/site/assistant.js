// Asistentul Sevalia: întrebările se trimit doar către siteul clinicii (POST, nu în URL) și nu se păstrează.
const escape = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// Formatare minimă, după escapare: **îngroșat** și _cursiv_.
const format = (s) => escape(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/(^|\s)_(.+?)_(?=\s|$)/g, '$1<em>$2</em>');

export function initAssistant() {
    const root = document.querySelector('[data-assistant]');
    if (!root) return;
    const fab = root.querySelector('.sv-assist__fab');
    const panel = root.querySelector('.sv-assist__panel');
    const log = root.querySelector('.sv-assist__log');
    const form = root.querySelector('[data-assistant-form]');
    const input = form.querySelector('input');
    let busy = false;

    const open = (state) => {
        panel.hidden = !state;
        fab.setAttribute('aria-expanded', state ? 'true' : 'false');
        if (state) input.focus(); else fab.focus();
    };
    fab.addEventListener('click', () => open(true));
    root.querySelector('[data-assistant-close]').addEventListener('click', () => open(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) open(false); });

    const add = (html, kind) => {
        const div = document.createElement('div');
        div.className = `sv-assist__msg sv-assist__msg--${kind}`;
        div.innerHTML = html;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    };

    const ask = async (question) => {
        if (busy || !question.trim()) return;
        busy = true;
        add(escape(question), 'user');
        const wait = add('Caut în informațiile clinicii…', 'bot sv-assist__msg--wait');
        try {
            const r = await fetch(root.dataset.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ q: question.slice(0, 300) }),
                credentials: 'omit',
            });
            const data = await r.json();
            wait.remove();
            if (!r.ok) {
                add(escape(data.error || 'Nu am putut răspunde acum. Reîncercați.'), 'bot');
            } else {
                const links = (data.links || []).filter((l) => l.url.startsWith('/'))
                    .map((l) => `<a href="${escape(l.url)}">${escape(l.label)}</a>`).join('');
                add(format(data.answer) + (links ? `<div class="sv-assist__links">${links}</div>` : ''), 'bot');
            }
        } catch {
            wait.remove();
            add('Conexiunea a eșuat. Reîncercați sau sunați la recepție.', 'bot');
        }
        busy = false;
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = input.value;
        input.value = '';
        ask(q);
    });
    root.querySelectorAll('[data-ask]').forEach((chip) => chip.addEventListener('click', () => ask(chip.dataset.ask)));
}
