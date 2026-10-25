// Editorul de pagini CMS. Fără JavaScript, formularul funcționează cu câmpuri text simple (HTML-ul e sanitizat pe server).
// Cu JavaScript: editare vizuală a textului, alegerea imaginilor, previzualizare desktop/mobil, semnalarea editării
// simultane și trimiterea doar a câmpurilor modificate (evită limita max_input_vars a PHP pe pagini mari).

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

function toolbar(full) {
    const buttons = [
        ['bold', 'B', 'Îngroșat'],
        ['italic', 'I', 'Cursiv'],
        ['link', '🔗', 'Link'],
        ['unlink', '⨯🔗', 'Elimină linkul'],
    ];
    if (full) {
        buttons.push(['h2', 'H2', 'Titlu'], ['h3', 'H3', 'Subtitlu'], ['p', '¶', 'Paragraf'], ['ul', '•', 'Listă'], ['ol', '1.', 'Listă numerotată']);
    }
    const bar = document.createElement('div');
    bar.className = 'cms-rte-bar';
    bar.setAttribute('role', 'toolbar');
    buttons.forEach(([cmd, label, title]) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.dataset.cmd = cmd;
        bar.appendChild(b);
    });
    return bar;
}

function enhanceRich(textarea) {
    const full = textarea.dataset.rich === 'full';
    const editor = document.createElement('div');
    editor.className = full ? 'cms-rich' : 'cms-inline';
    editor.contentEditable = 'true';
    editor.setAttribute('role', 'textbox');
    editor.setAttribute('aria-multiline', full ? 'true' : 'false');
    const label = document.querySelector(`label[for="${textarea.id}"]`);
    if (label) {
        label.id ||= `${textarea.id}-label`;
        editor.setAttribute('aria-labelledby', label.id);
        label.addEventListener('click', () => editor.focus());
    }
    editor.innerHTML = textarea.value;
    const bar = toolbar(full);
    bar.addEventListener('mousedown', (e) => e.preventDefault());
    bar.addEventListener('click', (e) => {
        const cmd = e.target.closest('button')?.dataset.cmd;
        if (!cmd) return;
        editor.focus();
        if (cmd === 'link') {
            const url = window.prompt('Adresa linkului (ex. /contactus sau https://…):', 'https://');
            if (url) document.execCommand('createLink', false, url);
        } else if (cmd === 'unlink') document.execCommand('unlink');
        else if (cmd === 'h2' || cmd === 'h3' || cmd === 'p') document.execCommand('formatBlock', false, cmd.toUpperCase());
        else if (cmd === 'ul') document.execCommand('insertUnorderedList');
        else if (cmd === 'ol') document.execCommand('insertOrderedList');
        else document.execCommand(cmd);
        sync();
    });
    const sync = () => {
        textarea.value = editor.innerHTML;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    };
    editor.addEventListener('input', sync);
    if (!full) {
        // Textele sloturilor sunt pe un singur rând logic; Enter inserează rând nou (<br>), nu paragraf.
        editor.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.execCommand('insertLineBreak');
            }
        });
    }
    textarea.hidden = true;
    textarea.after(bar, editor);
}

function setupPreview(root) {
    const frameBox = root.querySelector('[data-preview-frame]');
    const iframe = frameBox?.querySelector('iframe');
    if (!iframe) return;
    let width = 1440;
    const fit = () => {
        const scale = Math.min(1, frameBox.clientWidth / width);
        iframe.style.width = `${width}px`;
        iframe.style.height = `${frameBox.clientHeight / scale}px`;
        iframe.style.transform = `scale(${scale})`;
        if (width < frameBox.clientWidth) iframe.style.left = `${(frameBox.clientWidth - width) / 2}px`;
        else iframe.style.left = '0';
    };
    root.querySelectorAll('[data-preview-width]').forEach((btn) => btn.addEventListener('click', () => {
        width = Number(btn.dataset.previewWidth);
        root.querySelectorAll('[data-preview-width]').forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
        fit();
    }));
    window.addEventListener('resize', fit);
    fit();
}

function setupPicker(root) {
    const dialog = document.getElementById('cms-media-dialog');
    if (!dialog) return;
    const grid = dialog.querySelector('[data-picker-grid]');
    const search = dialog.querySelector('[data-picker-search]');
    let target = null;
    const load = async (q = '') => {
        const res = await fetch(`${root.dataset.picker}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
        const items = await res.json();
        grid.replaceChildren(...items.map((m) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = '<img alt="" loading="lazy"><span></span>';
            b.querySelector('img').src = m.thumb;
            b.querySelector('span').textContent = `#${m.id} ${m.name}`;
            b.addEventListener('click', () => {
                if (target) {
                    target.value = m.id;
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    const thumb = target.closest('.cms-media-field')?.querySelector('.cms-media-field__thumb');
                    if (thumb) thumb.src = m.thumb;
                }
                dialog.close();
            });
            return b;
        }));
    };
    let timer;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => load(search.value), 250);
    });
    root.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-media-pick]');
        if (!btn) return;
        target = document.getElementById(btn.dataset.mediaPick);
        dialog.showModal();
        load(search.value);
    });
}

function setupLease(root, form) {
    const warning = document.getElementById('cms-lease-warning');
    const beat = async () => {
        try {
            const res = await fetch(root.dataset.heartbeat, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } });
            const data = await res.json();
            const lock = form.querySelector('[name="lock_version"]');
            if (!data.ok && warning) {
                warning.hidden = false;
                warning.querySelector('div').textContent = `${data.other} editează această pagină acum. A doua salvare va fi refuzată, ca să nu se suprascrie modificările.`;
            }
            if (Number(lock.value) !== data.lock_version && warning) {
                warning.hidden = false;
                warning.querySelector('div').textContent = 'Pagina a fost salvată între timp de altcineva. Reîncărcați pagina înainte să salvați, altfel salvarea va fi refuzată.';
            }
        } catch { /* rețea indisponibilă: reîncercăm la următorul interval */ }
    };
    setInterval(beat, 60000);
    window.addEventListener('pagehide', () => {
        const data = new FormData();
        data.append('_token', csrf());
        navigator.sendBeacon?.(root.dataset.release, data);
    });
}

export default function init(root) {
    const form = root.querySelector('#cms-form');
    if (!form) return;

    root.querySelectorAll('textarea[data-rich]').forEach(enhanceRich);
    setupPreview(root);
    setupPicker(root);
    setupLease(root, form);

    // Valorile inițiale, pentru a trimite doar ce s-a modificat.
    const fields = () => Array.from(form.querySelectorAll('[name^="slots["]'));
    const initial = new Map();
    fields().forEach((f) => initial.set(f, f.type === 'checkbox' ? f.checked : f.value));
    let dirty = false;
    const indicator = form.querySelector('[data-dirty-indicator]');
    form.addEventListener('input', () => {
        dirty = true;
        if (indicator) indicator.hidden = false;
    });
    window.addEventListener('beforeunload', (e) => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Adăugare secțiune din șablon.
    form.querySelectorAll('[data-add-button]').forEach((btn) => {
        const select = form.querySelector(`[data-add-select="${btn.dataset.addButton}"]`);
        const update = () => {
            btn.value = select.value ? `add:${btn.dataset.addButton}:${select.value}` : '';
            btn.disabled = !select.value;
        };
        select.addEventListener('change', update);
        update();
    });

    let submitter = null;
    form.addEventListener('click', (e) => {
        const b = e.target.closest('button[type="submit"]');
        if (b) submitter = b;
    });

    form.addEventListener('submit', (e) => {
        if (submitter?.dataset.confirmOp && !window.confirm(submitter.dataset.confirmOp)) {
            e.preventDefault();
            return;
        }
        if (submitter?.hasAttribute('data-confirm-publish') && !window.confirm('Publicați această versiune? Va deveni vizibilă imediat pe site.')) {
            e.preventDefault();
            return;
        }
        dirty = false;
        // Construim obiectul cu sloturile modificate: slots[uid][id] sau slots[uid][id][camp].
        const changed = {};
        fields().forEach((f) => {
            const value = f.type === 'checkbox' ? f.checked : f.value;
            const parts = f.name.replace(/\]/g, '').split('[').slice(1);
            const isHiddenTwin = f.type === 'hidden' && f.nextElementSibling?.type === 'checkbox';
            if (!isHiddenTwin && value !== initial.get(f)) {
                const [uid, id, key] = parts;
                changed[uid] ??= {};
                if (key) {
                    // Trimitem toate subcâmpurile slotului (ex. href + text), ca valoarea să fie completă.
                    changed[uid][id] ??= {};
                    form.querySelectorAll(`[name^="slots[${uid}][${id}]["]`).forEach((sub) => {
                        if (sub.type === 'hidden' && sub.nextElementSibling?.type === 'checkbox') return;
                        const subKey = sub.name.replace(/\]/g, '').split('[')[3];
                        changed[uid][id][subKey] = sub.type === 'checkbox' ? (sub.checked ? '1' : '0') : sub.value;
                    });
                } else {
                    changed[uid][id] = value;
                }
            }
            f.disabled = true;
        });
        form.querySelector('[name="slots_json"]').value = JSON.stringify(changed);
    });

    // Contoare de caractere pentru câmpurile SEO.
    form.querySelectorAll('[data-count]').forEach((input) => {
        const hint = document.createElement('span');
        hint.className = 'field__hint';
        input.after(hint);
        const update = () => {
            hint.textContent = `${input.value.length} / ${input.dataset.count} caractere recomandate`;
        };
        input.addEventListener('input', update);
        update();
    });
}
