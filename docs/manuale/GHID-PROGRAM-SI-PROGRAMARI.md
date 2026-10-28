# Programul medicilor și programările — ghid pas cu pas

Capturile (date fictive) sunt în `docs/capturi/ghid-programari/`. Pe telefon meniul se deschide din butonul ☰ din
stânga sus.

## De ce nu se poate face încă nicio programare pe siteul instalat

La instalare s-au preluat de pe siteul vechi doar **locațiile, specialitățile și cei 10 medici**. Lipsesc, pentru că
nu existau pe siteul vechi și nu le putem inventa:

- **serviciile** (consultații, ședințe) cu durata lor — fără ele lista „Serviciu” din programare e goală;
- **programul de lucru** al fiecărui medic și terapeut;
- **terapeuții** (kinetoterapeuți, fizioterapeuți, maseuri) — pe siteul vechi apăreau doar medicii.

Pașii A–D de mai jos se fac **o singură dată**, apoi recepția lucrează zilnic doar cu pasul E.

---

## A. Catalogul de servicii — Administrare → Catalog servicii

Pentru fiecare serviciu: **Adaugă** și completați:

| Câmp | Ce înseamnă |
|---|---|
| Denumire | ex. „Consultație reumatologie”, „Ședință kinetoterapie” |
| Tip | consultație, ședință, procedură… |
| Specialitate | pentru consultații: specialitatea medicului (ex. Reumatologie) |
| Efectuat de | medic sau terapeut — calendarul propune doar persoanele potrivite |
| Durată (minute) | ex. 30 |
| Pauză după (minute) | timp de pregătire între pacienți (opțional) |
| Tarif privat (lei) | doar tariful confirmat de clinică; gol = „neconfirmat” |
| Programare online pe site | bifat = pacienții îl pot programa singuri pe `/programare-online` |
| Confirmare automată online | bifat = programarea de pe site se confirmă singură dacă intervalul e liber |

## B. Personalul — Administrare → Personal

- Verificați medicii: **Locații** (bifați doar locațiile unde consultă fiecare — implicit sunt bifate ambele),
  **Specialități**, **Culoare în calendar**.
- Adăugați **terapeuții**: Categorie „terapeut”, Locații, Afișat pe site (dacă apar pe pagina echipei).
- **Cont de utilizator**: legați persoana de contul ei din Administrare → Utilizatori, ca să-și vadă pe ecranul principal „Pacienții mei de azi”.

## C. Programul de lucru — Administrare → Program de lucru → numele persoanei

**Program săptămânal** (stânga):

1. Bifați zilele (ex. Luni–Vineri).
2. Alegeți **Locația**.
3. **De la / Până la** — ex. 08:00–16:00.
4. **Pauză de la / până la** — opțional (ex. 12:00–12:30).
5. **Valabil de la** — de când se aplică (implicit azi); **Valabil până la** — gol = permanent.
6. **Adaugă în program**.

Repetați pentru alt interval sau altă locație (ex. marți și joi 14:00–20:00 la Maurer). Ștergerea unui rând nu
modifică programările deja făcute.

**Concedii, zile libere, ore suplimentare** (dreapta): Tip „Concediu”, De la / Până la, **Adaugă**. În acele zile
persoana nu mai apare liberă în calendar și nici pe site.

**Sărbători / închiderea unei locații**: Administrare → Program de lucru (pagina principală) → „Închideri” → Locație,
De la / Până la → **Adaugă închidere**.

> Fără program configurat, calendarul folosește orarul locației și doar avertizează — de aceea e important pasul C.

## D. Regulile — Administrare → Reguli programări

Pasul grilei din calendar (ex. 15 minute), avansul minim și maxim pentru programările de pe site, confirmarea
automată online.

---

## E. Programarea unui pacient (recepție, zilnic)

1. **Calendar → Programare nouă** (sau dublu clic în calendar pe ora dorită, în coloana persoanei).
2. **Pacient**: căutați după nume, telefon sau CNP. Dacă nu există: **Creează fișă nouă** (nume, prenume, telefon).
3. **Serviciu → Locație → Persoana care efectuează** (sau „oricine disponibil”) **→ Data**.
4. **Arată intervale libere** → alegeți ora (sunt doar intervalele în care persoana lucrează și nu e ocupată).
5. **Rezervă**. Dacă intervalul nu mai e liber, primiți motivul și alternative.

**În ziua programării**, din programare (clic în calendar) se schimbă starea: Confirmată → **Sosit** →
**În consultație / procedură** → **Finalizată** (sau **Anulată** / **Neprezentat**). Nimic nu se marchează singur
„efectuat” când trece ora.

- **Mutare**: trageți programarea în calendar sau deschideți-o → **Mută**.
- **Serii de ședințe** (ex. 10 ședințe de kinetoterapie): Activitate → Serii de ședințe → Serie nouă (zilele, ora,
  numărul de ședințe); seria arată din start conflictele și propune alternative.
- **Listă de așteptare**: când nu e loc, pacientul intră pe listă; la o anulare, aplicația propune primul potrivit.
- **Solicitări de pe site**: Activitate → Solicitări — identificați pacientul (sau creați fișa) și confirmați ora.

## F. Programările online de pe site

După pașii A–C, bifați la serviciile dorite „Programare online pe site”. Pacientul vede pe `/programare-online`
doar orele libere reale ale acelor servicii; programarea apare în Calendar (confirmată automat dacă ați bifat
„Confirmare automată online”) sau la Solicitări.
