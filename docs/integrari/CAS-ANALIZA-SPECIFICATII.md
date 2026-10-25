# CAS / PIAS — analiza specificațiilor oficiale

Surse (copii nemodificate în `resources/cas/pias/2026-10-24/`, sume SHA-256 în `SHA256SUMS`):

| Document | Versiune / dată | Folosit pentru |
|----------|-----------------|----------------|
| Specificaţii de interfaţare cu SIUI+PE+CEAS pentru aplicaţiile de raportare | 3.7.34 din 20.10.2026 | flux de raportare (§3.2), validare online (§3.3), securitate (§2.3) |
| Anexa 007 – Ambulatoriu de specialitate și specialități clinice | `Last-Modified` 24.10.2026 | structura raportării clinice, enumerări |
| Anexa 011 – Servicii de recuperare ambulatorie | `Last-Modified` 24.10.2026 | structura raportării de recuperare |
| `ImportClinicReport.xsd` | fișier datat 31.12.2025 în arhivă | validarea XML clinic |
| `ImportRecoveryAmbReport.xsd` | fișier datat 02.10.2023 în arhivă | validarea XML recuperare ambulatorie |
| `Feedback_Clinic.xsd`, `Feedback_RECA_RECS.xsd` | 10.07.2020 | importul fișierelor de răspuns |
| `NomenclatoareClinic.xsd`, `NomenclatoareRecuperare.xsd` | 17.08.2026 | structura nomenclatoarelor |

Descărcat de la `https://portal.cnas.ro/cnas/pias/specificatii` la 2026-10-25. Toate fișierele au namespace-ul
`http://www.cnas.ro/siui/2.0`.

**Atenție:** nu știm încă ce tip de contract are clinica (vezi `docs/INTREBARI.md` §D). Ambele structuri de mai jos
sunt pregătite; se activează numai cea (cele) confirmată(e).

## 1. Ce spune specificația despre flux (§3.2)

1. Colectarea și validarea locală a datelor pe parcursul perioadei (regulile de validare sunt în anexe).
2. Raportarea electronică: **online prin serviciul web** sau **offline, pe un mediu de stocare**, prezentat la casa de
   asigurări (de regulă împreună cu formularele tipărite).
3. Preluarea rezultatelor (fișierul de răspuns), online sau offline.
4. Corectarea erorilor și reluarea până nu mai există erori corectabile.
5. Tipărirea și depunerea formularelor; factura poate fi depusă odată cu ele.
6. Decontul este un **PDF** de sinteză (nu se importă ca date).

Consecință pentru CRM: fluxul offline (fișier XML exportat → depus / încărcat în aplicația de raportare) este un
mecanism prevăzut oficial, deci fluxul manual documentat este legitim până la validarea unei integrări online.

## 2. Identificatori stabili (§3.3.3)

> „Pentru re-validarea după modificarea datelor privind serviciului medical efectuat aplicaţia de raportare va trebui
> să transmită acelaşi identificator de serviciu, în caz contrar operaţia va fi tratată ca o adăugare şi va fi
> invalidată (serviciul medical efectuat îşi păstrează identificatorul unic indiferent de câte ori este modificat).”

Decizie: fiecare serviciu efectuat raportabil primește un `AppID` **numeric, stabil, unic** (≤ 20 caractere), atribuit
la prima includere într-un lot și păstrat la toate corecturile și retransmiterile. Motiv suplimentar: în fișierele de
răspuns (`Feedback_Clinic.xsd`, `Feedback_RECA_RECS.xsd`) `AppID` are tipul `xs:decimal`, deci un identificator
alfanumeric nu ar putea fi reconciliat.

## 3. Securitate și semnare (§2.3.1–2.3.2)

- Autentificarea la SIUI: certificat digital calificat, HTTPS cu certificat client; certificatul se înregistrează
  în SIUI; poate fi pe eToken.
- Fișierele XML raportate **se semnează electronic** cu certificatul utilizatorului care raportează.

Serverul cPanel nu are acces la tokenul USB. Semnarea și transmiterea rămân în aplicația de raportare a clinicii sau
într-un conector local (vezi `CAS-CONECTOR-LOCAL.md`). Starea „transmis” se marchează în CRM numai cu dovadă
(încărcarea confirmării / a fișierului de răspuns sau declarația explicită a responsabilului, cu dată și autor).

## 4. Raportarea clinică — `ImportClinicReport.xsd`

Element rădăcină `report` (atribute obligatorii): `AppID`, `clinic` (denumire unitate), `fiscalCode`, `contractNo`,
`insuranceHouse` (codul casei), `reportingDate`, `startFrom`, `endTo`, `labValue`, `hspValue`. Opționale: `AppKey`,
`invoiceNo`, `invoiceDate`, `invoiceValue`, `numericReport`.

`report/clinicServices/clinicService` — atribute **obligatorii**:

| Atribut | Sursa în CRM | Observații |
|---------|--------------|-----------|
| `AppID` | identificatorul stabil al serviciului raportat | numeric, ≤ 20 |
| `stencilNo` | parafa medicului (`staff_members.stencil_no`) | ≤ 6 |
| `specialityCode` | codul CNAS al specialității medicului | din nomenclator |
| `patientInsuranceHouse` | casa de asigurări a pacientului | din nomenclator |
| `reportedService` | codul serviciului (`services.cas_code`) | din nomenclator |
| `medSrvPack` | pachetul medical | din nomenclator / contract |
| `quantity` | număr servicii | |
| `serviceDate` | data și ora efectuării (`service_records.performed_at`) | `xs:dateTime` |
| `initialDiagnostic` | diagnostic inițial (cod) | introdus de medic |
| `presentationMode` | modul de prezentare | enumerare 1–14 (mai jos) |
| `serviceType` | tipul de serviciu | enumerare 1–8 (mai jos) |
| `diseaseType` | 0 acut, 1 subacut, 2 cronic | introdus de medic |

Opționale relevante: `personPID` (CNP), `CID`, `cardNo` (16 caractere), `signature` (semnătura cu cardul pacientului —
necesită cititor de card și componentă locală), `certificateDate`, `exceptedFromCard` (1|2), `authorizedPersonCid`,
`serviceValue`, `merNo`/`merDate`, `confirmedDiagnostic`, `serviceHour` (ora×60+minute), datele biletului de
trimitere (`refferedBy*`), `personType`, `foreignPerson` pentru cetățeni străini.

### Enumerări documentate în Anexa 007

`presentationMode`: 1 Urgență · 2 Bilet de trimitere · 3 Scrisoare medicală · 4 Control · 5 Prezentare directă ·
6 Anexa 13 · 7 Bilet de externare · 8 Potențial endemoepidemic · 9 Afecțiune acută intercurentă · 10 Cronice –
urmărirea evoluției · 11 Cronice și PNS (Anexa 39A) – examen periodic · 12 Telemedicină · 13 Bilet de trimitere
management de caz · 14 Anexa 22C.

`serviceType`: 1 Consultație inițială · 2 Consultație de control · 3 Consultație specifică inițială · 4 Consultație
specifică control · 5 Servicii · 6 Servicii conexe · 7 Consultație conexă inițială · 8 Consultație conexă de control.

Aceste liste se încarcă în CRM ca nomenclatoare versionate cu sursa „Anexa 007, versiunea din 24.10.2026”.
Regulile de eligibilitate și combinațiile permise **nu** sunt deduse; se validează doar structura și valorile
admise de XSD.

## 5. Recuperare ambulatorie — `ImportRecoveryAmbReport.xsd`

Rădăcina `report`: `AppID`, `medicalUnit`, `fiscalCode`, `contractNo`, `insuranceHouse`, `reportingDate`,
`startFrom`, `endTo` (obligatorii); factura opțională.

`recoveryAmbServices/recoveryAmbService` = o fișă/cură. Obligatorii: `AppID`, `personType`, `presentationMode`,
`merNo`, `merDate`, `interruptedCure`. Opționale: identificarea persoanei (`personIdentifier`, `cid`, nume, data
nașterii, date pentru asigurați UE), biletul de trimitere (`refferedBy*`), `merEndDate`, `diagnosticDate`,
`hospitalizationExitDate`, `recDailyCure` (număr de zile recomandate), `interruptedCureReason`.

- `services/service`: `AppID`, `code` (obligatorii), `date` (obligatoriu), card/semnătură opționale, `stencilNo`,
  `reportedPrice`.
- `service/dailyCure`: o ședință din cură (`date`, card/semnătură opționale), cu `details/detail` = procedurile
  efectuate (`AppID`, `code` din catalogul de proceduri de recuperare, opțional asistentul).
- `diagnostics/diagnostic` și `initialConsultDiagnostics/diagnostic`.

Maparea în CRM: plan de recuperare aprobat → `recoveryAmbService`; ședințele efectuate (marcate explicit de terapeut)
→ `dailyCure`; procedurile din ședință → `detail`. O ședință neefectuată sau anulată nu apare.

## 6. Fișierele de răspuns

`Feedback_Clinic.xsd`: `report@AppID` (decimal); pentru fiecare `clinicService@AppID` un `state` (`0` = decontat
fără erori, `1` = nedecontat) și `errors/error@code` cu `errorType` (0|1, severitate). `Feedback_RECA_RECS.xsd`
are structură analogă pe fișă, serviciu și ședință.

Textul explicativ al codurilor de eroare nu este în XSD. CRM-ul păstrează codul primit și permite asocierea unei
explicații **numai** dintr-o sursă confirmată (nomenclatorul oficial de erori, dacă e disponibil, sau nota
responsabilului CAS cu sursa).

## 7. Nomenclatoare

Structurile (`NomenclatoareClinic.xsd`, `NomenclatoareRecuperare.xsd`) sunt publice; **valorile** se obțin prin
serviciul web de sincronizare (cu certificat) sau din aplicația de raportare a clinicii. Până la acces, CRM-ul
permite importul unui fișier XML de nomenclatoare conform XSD-ului (validat la import), cu perioadă de valabilitate,
sursă și versiune, și nu inventează coduri.

## 8. Ce rămâne blocat

| Element | De ce |
|---------|-------|
| Tipul de contract, casa, punctele de lucru, codurile furnizorului | necesită confirmarea clinicii |
| Valorile nomenclatoarelor (servicii, specialități, case, pachete) | necesită fișier oficial / sincronizare cu certificat |
| Semnarea XML și transmiterea online | certificat calificat + token, componentă locală |
| Semnătura cu cardul pacientului (`signature`) | cititor de card + eCard SDK local (Anexa 102) |
| Acceptarea de către CAS | se constată doar din fișierul de răspuns / decont |
