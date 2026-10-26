// Buton de tipărire (CSP nu permite atribute onclick).
export default function (el) {
    el.addEventListener('click', () => window.print());
}
