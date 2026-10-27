// Comportamentele tabletei: tastatura pentru cod, semnătura pe ecran, stelele, revenirea la ecranul de început.

// Tastatura pentru codul de 6 cifre.
document.querySelectorAll('[data-kiosk-pin]').forEach((form) => {
    const input = form.querySelector('input[name="code"]');
    const dots = [...form.querySelectorAll('.k-pin__dots span')];
    const render = () => dots.forEach((d, i) => d.classList.toggle('on', i < input.value.length));
    form.addEventListener('click', (e) => {
        const key = e.target.closest('[data-key]')?.dataset.key;
        if (!key) return;
        if (key === 'del') input.value = input.value.slice(0, -1);
        else if (input.value.length < 6) input.value += key;
        render();
        if (input.value.length === 6) form.requestSubmit();
    });
    document.addEventListener('keydown', (e) => {
        if (/^\d$/.test(e.key) && input.value.length < 6) { input.value += e.key; render(); if (input.value.length === 6) form.requestSubmit(); }
        if (e.key === 'Backspace') { input.value = input.value.slice(0, -1); render(); }
    });
});

// Recenzii trimise direct pe tabletă de recepție: ecranul de început întreabă la câteva secunde.
document.querySelectorAll('[data-kiosk-poll]').forEach((el) => {
    const url = el.dataset.kioskPoll;
    setInterval(async () => {
        try {
            const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await r.json();
            if (data.open) location.href = data.open;
        } catch { /* rețea indisponibilă: se reîncearcă */ }
    }, 4000);
});

// Semnătura desenată cu degetul sau cu stylusul (pointer events), salvată ca PNG.
document.querySelectorAll('[data-kiosk-sign]').forEach((form) => {
    const canvas = form.querySelector('canvas');
    const field = form.querySelector('input[name="signature"]');
    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0e3b3d';
    let drawing = false; let strokes = 0; let last = null;
    const pos = (e) => { const r = canvas.getBoundingClientRect(); return { x: (e.clientX - r.left) * canvas.width / r.width, y: (e.clientY - r.top) * canvas.height / r.height }; };
    canvas.addEventListener('pointerdown', (e) => { drawing = true; last = pos(e); canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointermove', (e) => {
        if (!drawing) return;
        const p = pos(e);
        ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        last = p; strokes++;
    });
    const end = () => { drawing = false; };
    canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', end);
    form.querySelector('[data-sign-clear]').addEventListener('click', () => { ctx.clearRect(0, 0, canvas.width, canvas.height); strokes = 0; field.value = ''; });
    form.addEventListener('submit', (e) => {
        if (e.submitter?.value === 'refuz') {
            if (!confirm('Confirmați că NU sunteți de acord cu acest document?')) e.preventDefault();
            return;
        }
        if (strokes < 10) { e.preventDefault(); alert('Vă rugăm să semnați în chenar.'); return; }
        field.value = canvas.toDataURL('image/png');
    });
});

// Textul de sub stele.
document.querySelectorAll('[data-kiosk-stars]').forEach((set) => {
    const text = document.querySelector('[data-stars-text]');
    const labels = { 1: 'Nemulțumit', 2: 'Sub așteptări', 3: 'Bine', 4: 'Foarte bine', 5: 'Excelent' };
    set.addEventListener('change', (e) => { if (text) text.textContent = labels[e.target.value] || ''; });
});

// După o perioadă de inactivitate, tableta revine la ecranul de început (datele nu rămân afișate).
const idleAfter = Number(document.body.dataset.idleAfter || 0);
if (idleAfter > 0) {
    let timer;
    const reset = () => { clearTimeout(timer); timer = setTimeout(() => { location.href = document.body.dataset.idleUrl; }, idleAfter * 1000); };
    ['pointerdown', 'keydown', 'scroll'].forEach((ev) => document.addEventListener(ev, reset, { passive: true }));
    reset();
}
