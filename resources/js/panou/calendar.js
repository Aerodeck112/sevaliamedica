// Calendar: mutare și redimensionare prin tragere, cu validare pe server.
// Alternativa accesibilă: fiecare programare e un link către pagina ei, cu formularul „Mută programarea”.

const pad = (n) => String(n).padStart(2, '0');
const toHHMM = (min) => `${pad(Math.floor(min / 60))}:${pad(min % 60)}`;

// Pe ecrane mici, filtrele secundare pornesc închise (pagina funcționează și fără JavaScript).
if (window.matchMedia('(max-width: 860px)').matches) {
    document.querySelectorAll('[data-collapse-mobile]').forEach((d) => d.removeAttribute('open'));
}

export default function calendar(root) {
    const startMin = Number(root.dataset.startMin);
    const px = Number(root.dataset.px);
    const slot = Number(root.dataset.slot) || 15;
    const canManage = root.dataset.canManage === '1';
    const live = root.querySelector('.cal__live');
    const dialog = document.getElementById('move-reason');

    const announce = (msg, tone = 'ok') => {
        live.textContent = msg;
        let toast = document.querySelector('.cal-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'cal-toast';
            document.body.appendChild(toast);
        }
        toast.innerHTML = '';
        const box = document.createElement('div');
        box.className = `alert alert--${tone}`;
        box.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
        box.textContent = msg;
        toast.appendChild(box);
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.remove(), 7000);
    };

    const askReason = (summary) =>
        new Promise((resolve) => {
            if (!dialog) return resolve('Mutare din calendar');
            dialog.querySelector('.move-reason__summary').textContent = summary;
            const other = dialog.querySelector('[name="reason_other"]');
            other.value = '';
            dialog.returnValue = '';
            dialog.showModal();
            dialog.addEventListener(
                'close',
                () => {
                    if (dialog.returnValue !== 'ok') return resolve(null);
                    const choice = dialog.querySelector('[name="reason_choice"]').value;
                    const text = choice === 'altul' ? other.value.trim() : choice;
                    resolve(text || null);
                },
                { once: true },
            );
        });

    const snap = (minutes) => Math.round(minutes / slot) * slot;

    // Creare rapidă: dublu-clic pe o zonă liberă deschide formularul precompletat.
    if (canManage) {
        root.addEventListener('dblclick', (e) => {
            const col = e.target.closest('.cal__col');
            if (!col || e.target.closest('.appt')) return;
            const rect = col.getBoundingClientRect();
            const minutes = startMin + snap((e.clientY - rect.top) / px);
            const params = new URLSearchParams({ date: col.dataset.date, time: toHHMM(minutes), location_id: root.dataset.location || '' });
            if (col.dataset.colType === 'staff') params.set('staff_member_id', col.dataset.colId);
            window.location.href = `${root.dataset.createUrl}?${params}`;
        });
    }

    if (!canManage) return;

    let drag = null;

    root.addEventListener('pointerdown', (e) => {
        const card = e.target.closest('.appt.is-movable');
        if (!card || e.button !== 0) return;
        const resizing = e.target.classList.contains('appt__resize');
        const rect = card.getBoundingClientRect();
        drag = {
            card,
            resizing,
            startY: e.clientY,
            startX: e.clientX,
            offsetY: e.clientY - rect.top,
            origTop: card.offsetTop,
            origHeight: card.offsetHeight,
            origCol: card.parentElement,
            moved: false,
        };
        card.setPointerCapture(e.pointerId);
    });

    root.addEventListener('pointermove', (e) => {
        if (!drag) return;
        const dy = e.clientY - drag.startY;
        const dx = e.clientX - drag.startX;
        if (!drag.moved && Math.abs(dy) < 4 && Math.abs(dx) < 4) return;
        drag.moved = true;
        drag.card.classList.add('is-dragging');
        if (drag.resizing) {
            const h = Math.max(slot * px, Math.round((drag.origHeight + dy) / (slot * px)) * slot * px);
            drag.card.style.height = `${h}px`;
            return;
        }
        // Coloana de sub cursor (persoană / sală / zi).
        const under = document.elementsFromPoint(e.clientX, e.clientY).find((el) => el.classList?.contains('cal__col'));
        if (under && under !== drag.card.parentElement) under.appendChild(drag.card);
        const top = Math.max(0, Math.round((drag.origTop + dy) / (slot * px)) * slot * px);
        drag.card.style.top = `${top}px`;
    });

    const revert = (d) => {
        d.origCol.appendChild(d.card);
        d.card.style.top = `${d.origTop}px`;
        d.card.style.height = `${d.origHeight}px`;
    };

    root.addEventListener('pointerup', async (e) => {
        if (!drag) return;
        const d = drag;
        drag = null;
        d.card.classList.remove('is-dragging');
        if (!d.moved) return; // clic simplu: linkul deschide programarea
        e.preventDefault();
        d.card.addEventListener('click', (ev) => ev.preventDefault(), { once: true, capture: true });

        const col = d.card.parentElement;
        const minutes = startMin + Math.round(d.card.offsetTop / px);
        const duration = Math.round(d.card.offsetHeight / px);
        const body = new FormData();
        body.set('date', col.dataset.date);
        body.set('time', toHHMM(minutes));
        body.set('version', d.card.dataset.version);
        if (d.resizing) body.set('duration', String(duration));
        if (col.dataset.colType === 'staff') body.set('staff_member_id', col.dataset.colId);
        if (col.dataset.colType === 'room' && col !== d.origCol) body.set('room_id', col.dataset.colId);

        const summary = `${d.card.querySelector('.appt__who')?.textContent ?? ''}: ${col.dataset.date} ${toHHMM(minutes)}${d.resizing ? `, ${duration} min` : ''}`;
        const reason = await askReason(summary);
        if (!reason) {
            revert(d);
            announce('Mutarea a fost anulată.', 'info');
            return;
        }
        body.set('reason', reason);

        try {
            const res = await fetch(root.dataset.moveUrl.replace('__ID__', d.card.dataset.id), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': root.dataset.csrf, Accept: 'application/json' },
                body,
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json.ok) {
                revert(d);
                d.card.classList.add('is-invalid');
                setTimeout(() => d.card.classList.remove('is-invalid'), 2500);
                const msg = json.conflicts?.join(' ') || json.message || 'Mutarea nu a fost posibilă.';
                announce(`Conflict: ${msg}`, 'danger');
                return;
            }
            d.card.dataset.version = json.version;
            announce(json.message + (json.warnings?.length ? ` ${json.warnings.join(' ')}` : ''), json.warnings?.length ? 'warn' : 'ok');
            setTimeout(() => window.location.reload(), 900);
        } catch {
            revert(d);
            announce('Conexiune întreruptă. Programarea nu a fost mutată.', 'danger');
        }
    });

    root.addEventListener('pointercancel', () => {
        if (drag) revert(drag);
        drag = null;
    });
}
