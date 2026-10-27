# Performanță și accesibilitate — măsurători Lighthouse

Măsurat la 2026-10-27 cu Lighthouse 12.8.2, profil **mobil** (telefon simulat, rețea și procesor încetinite),
local, prin `php artisan serve` (un singur proces) + un proxy care comprimă răspunsurile ca `mod_deflate` de pe
hosting. Valorile variază de la o rulare la alta cu câteva puncte (până la ±10 pe pagini cu imagini mari), din cauza
serverului local cu un singur proces. **Nu sunt măsurători de pe hostingul clinicii**; acolo se refac după instalare
(PageSpeed Insights, link direct din Marketing → Google și AI).

| Pagina | Performanță | Accesibilitate | Bune practici | LCP | CLS |
|---|---|---|---|---|---|
| `/` | 96 | 92 | 100 | 2,7 s | 0,001 |
| `/kinetoterapie` | 84–98 | 91 | 100 | 2,4–3,9 s | 0 |
| `/masaj` | 95 | 88 | 100 | 2,8 s | 0,013 |
| `/kinetoterapie-atm-articulatia-temporo-mandibulara` | 98 | 88 | 100 | 2,4 s | 0,017 |
| `/emsella-tratament-pentru-incontinenta-urinara` | 90 | 88 | 100 | 3,3 s | 0,018 |
| `/recuperare-medicala-mures` | 93 | 91 | 100 | 2,9 s | 0,002 |
| `/reabilitare-medicala-mures` | 96 | 91 | 100 | 2,7 s | 0,022 |
| `/programare-online` | 93 | 91 | 100 | 2,8 s | 0 |

SEO: 100 pe toate paginile în modul producție (local, în modul de test, robots.txt blochează intenționat indexarea,
deci Lighthouse raportează „pagina nu poate fi indexată”).

## Corecturi făcute în această etapă

- Imaginea principală se preîncarcă pentru tipul de ecran pe care e vizibilă: înainte, telefoanele preîncărcau
  imaginea copertei de desktop și nu pe a lor.
- Fonturile textului de bază (Montserrat 400/700, inclusiv setul cu ă, ș, ț) se cer devreme: textul nu se mai
  reașază la încărcarea fonturilor (CLS de la 0,159 la 0 pe pagina de programare).
- Bannerul de cookie apare imediat (fără să aștepte scriptul principal) și folosește fontul sistemului.
- `favicon.ico` redus de la 43 KB (7 dimensiuni) la 6,5 KB (16/32/48 px, aceleași imagini); pictograma mare
  e separată (`/images/icon-192.png`, `/images/apple-touch-icon.png`).
- Accesibilitate fără schimbări vizibile: nume pentru pictogramele rețelelor sociale, rolurile corecte ale
  acordeoanelor cu întrebări frecvente, nume pentru butonul asistentului, linkul din bannerul de cookie subliniat.

## Rămase, din designul original (păstrat fidel)

- **Contrast**: unele texte mici în culoarea `rgb(0, 159, 174)` și linkurile din cuprinsul paginilor au contrast
  sub pragul WCAG AA. Culorile sunt cele ale siteului original; o nuanță puțin mai închisă ar rezolva — decizia
  clinicii.
- **Ordinea titlurilor** (ex. H4 direct după H1): structura paginilor originale.
- **Linkuri doar colorate** în text (ex. „Echipa noastră”): stilul original.
- Fontul de pictograme FontAwesome (77 KB) se încarcă pentru câteva pictograme; poate fi redus ulterior.
