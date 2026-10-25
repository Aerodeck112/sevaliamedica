// Formularul de programare: căutarea pacientului, crearea unei fișe noi cu verificarea duplicatelor,
// intervale libere sugerate. Formularul rămâne utilizabil și fără JavaScript.

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export default function appointmentForm(form) {
    const picker = form.querySelector('[data-picker]');
    const value = picker.querySelector('[data-picker-value]');
    const picked = picker.querySelector('[data-picker-picked]');
    const searchBox = picker.querySelector('[data-picker-search]');
    const input = searchBox.querySelector('input[type="search"]');
    const results = searchBox.querySelector('[role="listbox"]');
    let timer;
    let active = -1;

    const choose = (p) => {
        value.value = p.id;
        picked.querySelector('[data-picker-label]').textContent = p.name;
        picked.querySelector('[data-picker-meta]').textContent = [p.birth_date, p.phone].filter(Boolean).join(' · ');
        picked.hidden = false;
        searchBox.hidden = true;
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    };

    picker.querySelector('[data-picker-clear]').addEventListener('click', () => {
        value.value = '';
        picked.hidden = true;
        searchBox.hidden = false;
        input.value = '';
        input.focus();
    });

    const render = (items) => {
        results.innerHTML = '';
        active = -1;
        if (!items.length) {
            results.innerHTML = '<li class="small muted" style="padding:.5rem .6rem">Niciun pacient găsit.</li>';
        }
        items.forEach((p, i) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.id = `pr_${i}`;
            li.innerHTML = `<button type="button"><strong>${escapeHtml(p.name)}</strong> <span class="small muted">${escapeHtml([p.birth_date, p.phone].filter(Boolean).join(' · '))}</span></button>`;
            li.querySelector('button').addEventListener('click', () => choose(p));
            li._p = p;
            results.appendChild(li);
        });
        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) {
            results.hidden = true;
            return;
        }
        timer = setTimeout(async () => {
            const res = await fetch(`${form.dataset.searchUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
            if (res.ok) render(await res.json());
        }, 220);
    });

    input.addEventListener('keydown', (e) => {
        const items = [...results.querySelectorAll('[role="option"]')];
        if (!items.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items.forEach((li, i) => li.setAttribute('aria-selected', i === active ? 'true' : 'false'));
            input.setAttribute('aria-activedescendant', items[active].id);
        } else if (e.key === 'Enter' && active >= 0) {
            e.preventDefault();
            choose(items[active]._p);
        } else if (e.key === 'Escape') {
            results.hidden = true;
        }
    });

    // Intervale libere.
    const suggestList = form.querySelector('[data-suggest-list]');
    const fillSlot = (date, time, staff) => {
        form.querySelector('[name="date"]').value = date;
        form.querySelector('[name="time"]').value = time;
        if (staff) form.querySelector('[name="staff_member_id"]').value = staff;
    };
    form.querySelector('[data-suggest]')?.addEventListener('click', async () => {
        const params = new URLSearchParams({
            service_id: form.querySelector('[name="service_id"]').value,
            location_id: form.querySelector('[name="location_id"]').value,
            staff_member_id: form.querySelector('[name="staff_member_id"]').value,
            date: form.querySelector('[name="date"]').value,
        });
        if (!params.get('service_id')) {
            suggestList.innerHTML = '<li class="small field__error">Alegeți întâi serviciul.</li>';
            return;
        }
        suggestList.innerHTML = '<li class="small muted">Se caută…</li>';
        const res = await fetch(`${form.dataset.suggestUrl}?${params}`, { headers: { Accept: 'application/json' } });
        const items = res.ok ? await res.json() : [];
        suggestList.innerHTML = items.length ? '' : '<li class="small muted">Niciun interval liber în următoarele 3 săptămâni pentru aceste criterii.</li>';
        items.forEach((s) => {
            const li = document.createElement('li');
            li.innerHTML = `<button type="button" class="btn btn--small">${escapeHtml(s.label)}${s.staff ? ' · ' + escapeHtml(s.staff) : ''}</button>`;
            li.querySelector('button').addEventListener('click', () => fillSlot(s.date, s.time, s.staff_member_id));
            suggestList.appendChild(li);
        });
    });
    document.querySelectorAll('[data-fill-slot]').forEach((b) => b.addEventListener('click', () => fillSlot(b.dataset.date, b.dataset.time, b.dataset.staff)));

    // Fișă nouă din dialog, cu verificarea duplicatelor pe server.
    const dialog = document.querySelector('[data-new-patient]');
    if (!dialog) return;
    const out = dialog.querySelector('[data-np-result]');
    const confirmWrap = dialog.querySelector('[data-np-confirm]');
    dialog.querySelector('[data-np-save]').addEventListener('click', async () => {
        const get = (id) => dialog.querySelector(`#${id}`).value.trim();
        const body = new FormData();
        body.set('last_name', get('np_last_name'));
        body.set('first_name', get('np_first_name'));
        body.set('phone', get('np_phone'));
        body.set('birth_date', get('np_birth_date'));
        body.set('cnp', get('np_cnp'));
        body.set('allow_phone', '1');
        if (dialog.querySelector('#np_confirm').checked) body.set('confirm_not_duplicate', '1');
        const res = await fetch(dialog.dataset.storeUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }, body });
        const json = await res.json().catch(() => ({}));
        if (res.status === 201) {
            choose(json);
            dialog.close();
            return;
        }
        const rows = [...(json.blocking ?? []), ...(json.warnings ?? [])];
        const list = rows
            .map((r) => `<li>${escapeHtml(r.name)} ${r.birth_date ? '(' + escapeHtml(r.birth_date) + ')' : ''} — ${escapeHtml(r.reason)} <button type="button" class="btn btn--small" data-use="${r.id}" data-name="${escapeHtml(r.name)}">Folosește această fișă</button></li>`)
            .join('');
        const errors = json.errors ? Object.values(json.errors).flat().map(escapeHtml).join('<br>') : '';
        out.innerHTML = `<div class="alert ${res.status === 409 ? 'alert--warn' : 'alert--danger'}" role="alert"><div><strong>${escapeHtml(json.message ?? 'Verificați datele.')}</strong>${errors ? '<br>' + errors : ''}${list ? '<ul>' + list + '</ul>' : ''}</div></div>`;
        confirmWrap.hidden = res.status !== 409;
        out.querySelectorAll('[data-use]').forEach((b) =>
            b.addEventListener('click', () => {
                choose({ id: b.dataset.use, name: b.dataset.name });
                dialog.close();
            }),
        );
    });
}
