// Corectorul SEO: numărul de caractere al propunerilor, actualizat la scriere, cu semnalarea depășirii limitelor.
export default function init(form) {
    form.querySelectorAll('[data-max]').forEach((field) => {
        const counter = form.querySelector(`[data-count-for="${field.id}"]`);
        if (!counter) return;
        const min = Number(field.dataset.min);
        const max = Number(field.dataset.max);
        const update = () => {
            const n = [...field.value.trim()].length;
            counter.textContent = `${n} caractere (recomandat ${min}–${max})`;
            counter.classList.toggle('field__error', n < min || n > max);
        };
        field.addEventListener('input', update);
        update();
    });
}
