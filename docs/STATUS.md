# Starea sinceră a funcționalităților

Legendă: ✅ da · ⚠️ parțial · ❌ nu · — nu se aplică. „Testat staging” și „verificat cu serviciul extern” rămân ❌
până la existența unui staging pe hostingul clinicii.

## Pacienți și programări (etapa 4)

| Funcționalitate | Implementat | Testat local | Testat staging | Verificat cu serviciul extern | Blocat de acces/date |
|---|---|---|---|---|---|
| Fișa pacientului (identificare, contact, reprezentanți, preferințe comunicare, observații) | ✅ | ✅ | ❌ | — | — |
| CNP criptat + index orb, afișare mascată, dezvăluire auditată | ✅ | ✅ | ❌ | — | — |
| Duplicate: blocare la CNP/document, avertizare nume+dată/telefon, fără fuziuni automate | ✅ | ✅ | ❌ | — | — |
| Unirea fișelor (permisiune, reconfirmare, motiv, audit, mutare prin participanți) | ✅ | ✅ | ❌ | — | — |
| Relația de îngrijire + acces de urgență | ✅ | ✅ | ❌ | — | — |
| Program de lucru, pauze, concedii, ore suplimentare, închideri | ✅ | ✅ | ❌ | — | Programul real al personalului: îl introduce clinica |
| Rezervare simultană persoană + sală (capacitate) + aparat, alocare automată / manuală | ✅ | ✅ | ❌ | — | Sălile și aparatele reale: le introduce clinica (în demo sunt fictive) |
| Prevenirea conflictelor la cereri concurente (2 procese reale + control negativ) | ✅ | ✅ | ❌ | — | — |
| Statusuri și tranziții validate, istoric cu motive | ✅ | ✅ | ❌ | — | — |
| Serii de ședințe (plan cu conflicte și alternative, creare parțială cu acord, reprogramare) | ✅ | ✅ | ❌ | — | — |
| Listă de așteptare + potrivire la anulare + ofertă (eveniment pentru notificări) | ✅ | ✅ | ❌ | — | Trimiterea mesajului: modulul Notifications |
| Calendar zi/săptămână, coloane personal/săli, filtre, drag-and-drop, alternativă accesibilă | ✅ | ⚠️ randare testată automat; tragerea verificată manual în capturi, fără test automat de browser | ❌ | — | — |
| Solicitări de pe site (contract AppointmentIntake), confirmare automată după reguli | ✅ | ✅ | ❌ | — | Formularul public îl leagă modulul Site |
| Linkuri pentru pacient (anulare, cerere reprogramare) | ✅ | ✅ | ❌ | — | Trimiterea linkului: modulul Notifications |
| Servicii efectuate (service_records) cu corectare fără ștergere | ✅ | ✅ | ❌ | — | — |
| Reguli de programare și acces editabile din panou, auditate | ✅ | ✅ | ❌ | — | Valorile reale: le stabilește clinica |
| Dashboard recepție / medic-terapeut | ✅ | ✅ | ❌ | — | — |

## Limitări cunoscute (etapa 4)

- Tragerea în calendar nu are încă test automat de browser (Playwright); validarea conflictelor e pe server și
  testată prin endpointul JSON.
- Seriile acceptă o ședință pe zi; mai multe ședințe în aceeași zi se programează individual.
- Capacitatea sălii se calculează ca suprapunere maximă în interval (corect pentru programări cu durate diferite).
- Fereastra relației de îngrijire bazată pe programări include programările viitoare confirmate.
