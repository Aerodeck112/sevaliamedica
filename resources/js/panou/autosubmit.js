// Trimite automat un formular după o scurtă pauză (pașii unei actualizări sau ai unui backup).
// Fără JavaScript, utilizatorul apasă butonul; serverul reia pasul de unde a rămas.
export default function autosubmit(form) {
    const delay = Number(form.dataset.delay || 800);
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const timer = setTimeout(() => form.requestSubmit(), reduce ? delay * 2 : delay);
    // Dacă utilizatorul interacționează cu pagina (ex. citește jurnalul), nu-l întrerupem brusc.
    document.addEventListener('keydown', () => clearTimeout(timer), { once: true });
}
