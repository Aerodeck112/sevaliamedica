# Auditul siteului public www.sevalia.ro

Data: 2026-10-25. Metodă: preluare HTTP a sitemapului și a tuturor linkurilor interne (`tools/audit/crawl.py`),
capturi Chromium/Playwright pe pagina completă (`tools/audit/shoot.js`), analiza CSS-ului publicat.

**Ce am verificat vizual:** capturile din `referinta/desktop` (1440×900, pagină completă) și `referinta/mobil`
(390×844, randat la 2×, salvat la 390 px) au fost generate de un browser real. Am inspectat vizual direct pagina
principală și pagina de tarife; restul capturilor sunt disponibile ca referință pentru comparația automată,
dar nu le-am verificat individual cu ochiul.

## 1. Platforma actuală

- Odoo (meta `generator`, rute `/web/assets`, `/website/form`, `/web/image`), servit prin nginx, HTTPS cu HSTS.
- Limba implicită română; există și versiune `/en/...` generată automat de Odoo, cu majoritatea textelor tot
  în română și cu sluguri diferite pentru unele articole de blog.
- Terți încărcați pe toate paginile: Cookiebot (`consent.cookiebot.com`), Google Ads (`AW-16584116716`,
  `AW-10996703908`), Plausible prin Odoo SaaS, Google Fonts, hărți Google în iframe, YouTube embed.

## 2. Structura URL

- Sitemap: 65 de intrări (`database/content/odoo-snapshot/sitemap.xml`). Crawl în română: 68 de URL-uri, toate
  200 cu excepția celor de mai jos. Inventar complet: [`INVENTAR-URL.md`](INVENTAR-URL.md).
- Paginile sunt la rădăcină (`/kinetoterapie`), blogul la `/blog/blog-sevalia-1/<slug>-<id>`; `/blog` →
  `/blog/blog-sevalia-1` (redirect Odoo).
- `robots.txt` permite tot; declară sitemapul.

### Linkuri rupte găsite

| Link | Unde apare | Rezultat |
|------|------------|----------|
| `/neurologie` | meniul „Specialitate medicală” (toate paginile) | 404 |
| `/dry-needling` | link în conținut | 404 |
| `tel://0365 403 001` | link de telefon | format invalid de link telefonic (`//` în loc de număr) |
| „Politica de cookie” | subsol | text fără link |
| Hartă subsol | subsol, ambele coloane | afișează textul Odoo „O adresă trebuie specificată pentru a putea fi încorporată o hartă” |

## 3. Meniul principal (ordinea originală)

1. **Specialitate medicală** → Reumatologie (`/reumatologie`), Reabilitare medicală (`/reabilitare-medicala-mures`),
   Medicină internă (`/medicina-interna-consultatii-tirgu-mures`), Pediatrie (`/pediatrie`), Psihiatrie
   (`/psihiatrie`), Neurologie (`/neurologie` — 404)
2. **Servicii** → Electroterapie, Salinoterapie, Kinetoterapie, Baie Stanger, Băi galvanice, Duș subacval,
   Magnetoterapie, Termoterapie, Drenaj limfatic, Ultrasonoterapie, Laserterapie, Masaj, Kinesio taping,
   Kinetoterapie ATM
3. **Terapii premium** → Decompresie vertebrală robotizată, EMSELLA – terapia planșeului pelvin, ShockWave Focusat,
   TR-Therapy (TECAR), Super Inductive System, AlterG VIA – bandă antigravitațională, Recuperare postoperatorie –
   CPMotion, Hydro Physio – bandă subacvală; plus legătura „Descoperă echipa noastră”
4. **Ghid pacient** → Sunt însărcinată, Am dureri, Am leziuni, Aleg sănătatea (`/vreau-sa-ma-simt-bine`),
   Ghidul tău de recuperare – intră pe blog, Cum ajungi la noi
5. **Descoperă echipa noastră** (`/echipa-noastra`)
6. **Contactați-ne** (`/contactus`)
7. **Blog** (`/blog`)
8. Selector de limbă (română / English (US))

Pagini publice care nu apar în meniu: `/servicii`, `/fizioterapie-targu-mures`, `/recuperare-medicala-mures`,
`/medicina-de-familie-band-mures`, `/sevalia-cabinet-maurer-mures`, `/ghid-intrebari-medicina-interna`,
`/emsella-landing-oferta-vara`, `/tarife-asistenta-medicala-ambulatorie-clinica`, `/politica-de-confidentialitate`,
`/termeni-si-conditii`, `/terms` (pagină Odoo implicită), `/website/info` (pagină tehnică Odoo).

**Subsol:** text de prezentare, link-uri utile (Acasă, Despre noi, Politica de confidențialitate, Politica de cookie,
Termeni și condiții, Tarife asistență medicală ambulatorie clinică), rețele sociale (Facebook, YouTube, Instagram,
TikTok prin `/website/social/*`), butonul „Programează un consult”, două coloane de specialități
(„REUMATOLOGIE | REABILITARE MEDICALĂ | MEDICINĂ INTERNĂ” și „PEDIATRIE | NEUROLOGIE | PSIHIATRIE”),
„© Copyright Sevalia Medica 2023. Toate drepturile rezervate!”.

## 4. Identitate vizuală (din CSS-ul publicat)

| Element | Valoare |
|---------|---------|
| Culoare 1 (accent, butoane primare) | `#00C4C4` |
| Culoare 2 (secundar, fundal secțiuni cc3) | `#007878` |
| Culoare 3 (fundal deschis) | `#F8F9FA` |
| Culoare 4 | `#FFFFFF` |
| Culoare 5 (titluri cc2, fundal cc5) | `#006F75` |
| Text | `#212529`; link `#008282` |
| Font text și meniu | Montserrat 300/400/700 (Google Fonts) |
| Font titluri | Manrope 300/400/600 |
| Dimensiuni titluri | h1 3rem, h2 2.5rem, h3 2rem, h4 1.5rem, h5 1.25rem, h6 1rem; bază 1rem |
| Butoane | rază 30px pe butoanele personalizate; altfel 0.125–0.25rem |
| Logo | `/web/image/website/1/logo/sevalia.ro`, afișat la 95×40 px, înălțime header logo 2.5rem |
| Header | șablon Odoo „Contact”, efect la scroll „disappears” |

Combinațiile de culori Odoo (`o_cc1`–`o_cc5`) sunt reproduse identic în CSS-ul extras.

## 5. Formulare

| Pagina | Câmpuri | Observații |
|--------|---------|-----------|
| `/contactus` | Nume, Data nașterii, Care e nevoia ta? (Reumatologie, Reabilitare medicală, Neurologie, Psihiatrie, Cardiologie pediatrică, Pediatrie, Medicină internă), La cine doriți să fiți programat (9 medici), Cum te putem contacta? (Telefon/Email/SMS), Telefon, E-mail, Mesaj, acord Politică + Termeni | Trimite e-mail prin Odoo (`mail.mail`). Data nașterii nu e necesară pentru o solicitare de programare — propunem eliminarea ei (minimizare), cu acordul clinicii. Dr. Crauciuc Andrei (cardiolog pediatru) apare pe pagina echipei, dar nu în lista formularului. |
| `/emsella-landing-oferta-vara` | Subiect, Nume, Mesaj, Cum te putem contacta, Telefon, E-mail | Pagină de campanie. |

## 6. Locații și contact (publicate pe site)

| Locație | Adresă | Telefon | Program |
|--------|--------|---------|---------|
| Cartier Unirii | str. Constandin Hagi Stoian, nr. 3C (Târgu Mureș) | 0365 403 001 | L–J 07:00–22:00, V 07:00–19:00, S–D închis |
| Cartier Maurer | str. Ion Heliade Rădulescu B26 (lângă grădiniță) | 0741 558 558 | L–J 07:00–21:00, V 07:00–19:00, S–D închis |

E-mail: `office@sevalia.ro`; DPO: `dpo@sevalia.ro`. Există și pagina `/medicina-de-familie-band-mures`
(Band, Mureș) — de confirmat dacă este punct de lucru al clinicii.

Hărțile folosesc adrese ușor diferite: „Str. Constandin Hagi Stoian, nr. 3” și „str. Ion Heliade Radulescu,
nr. 86” față de textul „nr. 3C” și „B26”.

## 7. Echipa publicată

dr. Cristina Pop (reumatolog), dr. Kinga Fülöp (reabilitare medicală), dr. Székely-Varga Margit (recuperare,
medicină fizică și balneologie), dr. Moldovan Kitti (reumatolog), dr. Mureșan-Varro Emma-Sarolta (medicină
internă), dr. Voicu Maria (reabilitare medicală), dr. Boilă Ioana (pediatru), dr. Orban Emese (neurolog),
dr. Bodo Cristina (psihiatru), dr. Crauciuc Andrei (cardiolog pediatru).

Aceasta este informația publică de pe site; nu reprezintă confirmarea specialităților contractate cu CAS.

## 8. Asigurători afișați

Pagina principală: „Servicii decontate integral prin” OMNIASIG, Allianz, CNAS (link `casan.ro`). Tipul de contract
CAS și specialitățile contractate trebuie confirmate de clinică (vezi `docs/INTREBARI.md`).

## 9. Imagini

326 de fișiere referite, 42 MB în total, toate descărcate nemodificat cu SHA-256
(`database/content/odoo-snapshot/images/index.json`). Paginile cele mai grele în imagini: `/fizioterapie-targu-mures`
(~3,7 MB), `/servicii` (~3,5 MB), `/echipa-noastra` (~3,2 MB), `/` (~2,1 MB).
Aproape toate imaginile au `alt` gol. Tarifele (`/tarife-asistenta-medicala-ambulatorie-clinica`) sunt publicate
ca 13 imagini cu tabele — textul nu este accesibil cititoarelor de ecran și motoarelor de căutare.

## 10. SEO tehnic

- 37 din 64 de URL-uri analizate au mai mult de un `<h1>` (până la 8 pe `/kinetoterapie`).
- 18 URL-uri nu au meta description (inclusiv `/contactus`, `/blog`, `/politica-de-confidentialitate`).
- Canonical prezent pe toate paginile.
- Titluri inconsecvente: „Tîrgu Mureș” / „Târgu Mureș” / „Targu Mures”; unele titluri fără diacritice.
- Versiunea `/en` duplică conținutul în română.

Remedierile SEO (un singur H1, descrieri lipsă) se aplică în structura paginilor fără a schimba textul vizibil;
orice modificare de text se propune separat clinicii.

## 11. Observații de conținut

Vezi [`OBSERVATII-CONTINUT.md`](OBSERVATII-CONTINUT.md). Nu au fost modificate în import.
