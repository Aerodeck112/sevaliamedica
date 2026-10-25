// Reduce foaia de stil publicată a siteului original (Odoo) la regulile folosite de paginile importate.
//
//   php artisan sevalia:site-html-dump && node tools/site-css/build.mjs
//
// Intrare: database/content/odoo-snapshot/assets/web.assets_frontend.min.css + HTML-ul din storage/app/site-css/
// Ieșire:  resources/css/site/odoo.css (inclusă de resources/css/site.css; versionată în repository)
import fs from 'node:fs';
import path from 'node:path';
import { PurgeCSS } from 'purgecss';

const root = path.resolve(import.meta.dirname, '../..');
const source = path.join(root, 'database/content/odoo-snapshot/assets/web.assets_frontend.min.css');
const outFile = path.join(root, 'resources/css/site/odoo.css');
let css = fs.readFileSync(source, 'utf8');

// Fonturile Google se înlocuiesc cu fonturi găzduite local (@fontsource, în site.css).
css = css.replace(/@import url\("https:\/\/fonts\.googleapis\.com[^)]*\);?/g, '');
// Fonturile de pictograme (FontAwesome 4.7, odoo_ui_icons) se servesc local.
css = css.replace(/url\('\/web\/static\/src\/libs\/fontawesome\/css\/\.\.\/fonts\/fontawesome-webfont\.woff2\?v=4\.7\.0'\) format\('woff2'\),\s*url\('[^']*'\) format\('woff'\)/g,
    "url('../../fonts/fontawesome-webfont.woff2') format('woff2')");
css = css.replace(/url\('\/web\/static\/lib\/odoo_ui_icons\/fonts\/odoo_ui_icons\.woff2'\) format\('woff2'\),\s*url\('[^']*'\) format\('woff'\)/g,
    "url('../../fonts/odoo_ui_icons.woff2') format('woff2')");

const content = [
    path.join(root, 'storage/app/site-css/*.html'),
    path.join(root, 'app/Modules/Site/Views/**/*.blade.php'),
    path.join(root, 'resources/js/site.js'),
    path.join(root, 'resources/js/site/*.js'),
];

const result = await new PurgeCSS().purge({
    content,
    css: [{ raw: css }],
    fontFace: false,
    keyframes: true,
    variables: true,
    // Clase adăugate de JavaScript (meniuri, carusel, animații la apariție, antet la derulare).
    safelist: {
        standard: ['show', 'showing', 'hiding', 'collapsing', 'collapse', 'active', 'disabled', 'fade', 'o_visible', 'o_animating', 'o_animated',
            'o_header_is_scrolled', 'o_header_affixed', 'o_header_no_transition', 'o_top_fixed_element', 'modal-backdrop', 'offcanvas-backdrop',
            'carousel-item-next', 'carousel-item-prev', 'carousel-item-start', 'carousel-item-end', 'was-validated', 'is-invalid', 'invalid-feedback',
            'alert', 'alert-success', 'alert-danger', 'd-none', 'd-block', 'visually-hidden', 'o_bg_video_container', 'o_bg_video_iframe', 'modal', 'modal_shown', 'o_text_highlight_svg', 'position-absolute', 'overflow-visible', 'pe-none'],
        deep: [/^o_anim/, /^o_header/, /^dropdown/, /^offcanvas/, /^carousel/, /^accordion/],
        greedy: [/data-bs-popper/],
    },
});

const header = `/*
 * Stilurile siteului www.sevalia.ro, preluate din foaia de stil publicată de platforma Odoo
 * (web.assets_frontend.min.css, instantaneu 2026-10-25) și reduse automat la regulile folosite.
 * Conține: Bootstrap 5 (licență MIT, © The Bootstrap Authors), Odoo website (LGPL-3.0, © Odoo S.A.),
 * Font Awesome 4.7 CSS (MIT; fontul: SIL OFL 1.1) și personalizările temei clinicii.
 * Fișier generat de tools/site-css/build.mjs — nu editați manual; ajustările se fac în resources/css/site.css.
 */
`;
fs.mkdirSync(path.dirname(outFile), { recursive: true });
fs.writeFileSync(outFile, header + result[0].css);
console.log(`CSS original: ${(css.length / 1024).toFixed(0)} KB → redus: ${(result[0].css.length / 1024).toFixed(0)} KB (${outFile})`);
