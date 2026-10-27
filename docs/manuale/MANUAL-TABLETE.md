# Tabletele de la recepție — manual

Două tablete (sau una singură, în modul „Consimțăminte și recenzii”):

- **tableta de consimțăminte** — pacientul introduce codul de 6 cifre primit la recepție, citește documentul,
  bifează acordul și semnează cu degetul pe ecran (sau apasă „Nu sunt de acord”);
- **tableta de recenzii** — după serviciu, pacientul vede serviciul și specialistul, alege 1–5 stele și poate
  scrie un comentariu.

Tableta nu are acces la panou și nu afișează date medicale: pentru confirmare arată doar prenumele și inițiala
numelui. Capturi: `docs/capturi/etapa-tablete/`.

## 1. Pregătirea tabletei (o singură dată)

1. Pe tabletă deschideți în browser adresa `https://<domeniul-clinicii>/tableta`. Apare ecranul „Asociere tabletă”.
2. În panou: **Administrare → Tablete recepție** (permisiunea „Tablete: administrare”). Completați numele
   (ex. „Tableta consimțăminte — Unirii”), alegeți la ce folosește și locația, apoi **Adaugă și generează codul**.
3. Codul de 8 caractere apare o singură dată și e valabil 30 de minute. Introduceți-l pe tabletă → **Asociază**.
4. Recomandat: fixați browserul pe ecran din setările tabletei (Android: „Fixare aplicație”; iPad: „Acces ghidat”),
   dezactivați blocarea automată a ecranului în timpul programului și adăugați pagina pe ecranul de pornire.

Dacă o tabletă se pierde sau se schimbă: **Deconectează** în panou (tableta pierde accesul imediat), apoi adăugați
tableta nouă. **Reasociază** generează un cod nou pentru aceeași tabletă (ex. după ștergerea datelor browserului).

## 2. Consimțământ semnat pe tabletă

1. Deschideți fișa pacientului → tabul **Tabletă**.
2. Alegeți modelul de consimțământ (versiunea publicată curent) și, opțional, tableta. **Generează codul de 6 cifre**.
3. Spuneți-i pacientului codul (apare o singură dată pe ecranul dumneavoastră). Codul expiră după numărul de
   minute din **Setări** (implicit 20) și se blochează după 5 coduri greșite introduse pe tabletă.
4. După semnare, consimțământul apare în tabul **Consimțăminte** cu metoda „semnat pe tabletă”, iar imaginea
   semnăturii se păstrează criptat la **Documente** (categoria „consimțământ”). Refuzul apare în lista „Cereri
   trimise pe tabletă” cu starea „Refuzată”.

Semnătura desenată pe ecran este o semnătură electronică **simplă**; nu ține locul unei semnături calificate.

## 3. Recenzie după serviciu

1. În tabul **Tabletă** al pacientului, la „Recenzie după serviciu”, apar serviciile efectuate (marcate explicit
   „efectuat”) fără recenzie.
2. Alegeți tableta de recenzii și bifați **Deschide direct pe tabletă**: tableta afișează singură formularul în câteva
   secunde. Fără bifă primiți un cod de 6 cifre, ca la consimțământ.
3. Rezultatele: **Rapoarte → Recenzii** (medie, distribuție pe stele, pe specialiști și pe servicii, filtre).
   Comentariile se păstrează criptat. O recenzie se poate publica pe site doar dacă pacientul a bifat acordul.

### Recenzii Google

În **Tablete recepție → Setări** puteți pune linkul „Cereți recenzii” din Google Business Profile. După **orice**
recenzie (indiferent de nota acordată) tableta afișează un cod QR către Google — regulile Google interzic cererea
de recenzii doar de la pacienții mulțumiți.
