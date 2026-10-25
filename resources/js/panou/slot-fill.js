// Butoanele cu intervale sugerate completează câmpurile de dată / oră / persoană din formularul curent.
export default function slotFill(form) {
    form.addEventListener('click', (e) => {
        const b = e.target.closest('[data-fill-slot]');
        if (!b) return;
        const set = (name, v) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el && v !== undefined && v !== '') el.value = v;
        };
        set('date', b.dataset.date);
        set('time', b.dataset.time);
        set('staff_member_id', b.dataset.staff);
        form.querySelector('[name="reason"]')?.focus();
    });
}
