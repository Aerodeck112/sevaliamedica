# Manual scurt — Recepție

Capturi de ecran: `docs/capturi/etapa-4/` (date fictive).

## Ecranul principal

„Recepție — azi” arată: programările zilei în ordinea orei, câți pacienți sunt de sosit, sosiți, finalizați,
solicitările noi de pe site și lista de așteptare. Butonul **Sosit** marchează sosirea direct din listă.

## Programare nouă

1. **Calendar → Programare nouă** (sau dublu-clic pe o zonă liberă din calendar).
2. Căutați pacientul după nume, telefon sau CNP. Dacă nu există, **Creează fișă nouă**: aplicația verifică
   duplicatele — la CNP identic vă trimite la fișa existentă; la nume + dată de naștere sau telefon identic vă
   cere să confirmați că este altă persoană.
3. Alegeți serviciul, locația, persoana, data și ora. **Arată intervale libere** propune intervale disponibile.
4. **Rezervă**. Sala și aparatul se aleg automat dintre cele libere; le puteți alege și manual.
5. Dacă intervalul nu e liber, vedeți motivul exact (ex. „aparatul AlterG e rezervat”) și intervale alternative.

Dacă un serviciu cere o sală sau un aparat care nu au fost încă adăugate, mesajul vă spune unde se configurează
(Administrare → Săli / Aparatură).

## Calendarul

- **Zi / Săptămână**, coloane pe **Personal** sau pe **Săli**; filtre pe specialitate, persoană, sală, aparat.
- Linia turcoaz marchează ora curentă. Fiecare programare arată ora, pacientul, serviciul și **statusul în text**.
- **Mutare:** trageți programarea în altă oră sau coloană; trageți marginea de jos pentru a schimba durata.
  Alegeți motivul. Dacă noul interval nu e liber, programarea revine la loc și primiți explicația.
- **Fără mouse:** deschideți programarea și folosiți **Mută programarea** (dată, oră, persoană, motiv, intervale
  libere sugerate).

## Statusuri

Solicitare → Confirmată → Sosit → În consultație / procedură → Finalizată. Anulată și Neprezentat cer motiv.
Nimic nu se schimbă singur când trece ora. Neprezentarea se poate marca doar după ora programării.

## Solicitări de pe site

**Solicitări** listează cererile primite. Pentru fiecare:
1. Identificați pacientul (fișă existentă sugerată după telefon/nume) sau creați fișa (precompletată din formular).
2. Alegeți intervalul și **Confirmă programarea**. Anunțați pacientul.
3. Dacă nu se poate programa, **Închide** solicitarea cu motiv.

Tot aici apar programările confirmate automat (dacă clinica a activat regula) care așteaptă identificarea
pacientului și pacienții care au cerut reprogramarea prin link.

### Programarea online (`/programare-online`)

Pacientul alege serviciul, locația, eventual specialistul, apoi ziua și ora dintre intervalele **libere reale**
din calendar (aceleași verificări ca la recepție: program, pauze, concedii, săli, aparate). Apar doar:
- serviciile marcate „programare online” (Administrare → Servicii);
- orele din programul public al locației, între avansul minim și maxim din Setări programări;
- numele specialiștilor afișați pe site (ceilalți apar ca „oricare specialist disponibil”).

La trimitere intervalul se verifică din nou. Dacă regula de confirmare automată e pornită și serviciul o permite,
programarea e confirmată imediat; altfel devine o **solicitare** cu ora dorită, pe care o confirmați de aici.

## Serii de ședințe

**Serii de ședințe → Serie nouă**: pacient, serviciu, terapeut, zilele săptămânii, ora, numărul de ședințe.
Aplicația verifică fiecare ședință; la conflict propune alternative. Seria se creează parțial numai dacă bifați
acordul. Din pagina seriei vedeți ședințele efectuate, anulate și rămase și puteți reprograma toate ședințele viitoare.

## Lista de așteptare

Adăugați pacientul cu serviciul, persoana preferată, zilele și orele potrivite. Când anulați o programare,
pagina ei afișează pacienții compatibili cu intervalul eliberat: **Oferă intervalul** înregistrează oferta; dacă
pacientul acceptă, **Programează**.

## Servicii efectuate

După sosirea pacientului, din pagina programării: **Înregistrează serviciul efectuat** (serviciu, cine l-a efectuat,
sursa de plată). Programarea finalizată NU înregistrează automat serviciul. O greșeală se corectează cu
**Anulează / corectează** (cu motiv); nimic nu se șterge.

## Linkul pentru pacient

Pentru o programare confirmată: **Generează link** — linkul permite pacientului să anuleze sau să ceară
reprogramarea. Este afișat o singură dată. Nu trimiteți prin SMS sau e-mail informații medicale.
