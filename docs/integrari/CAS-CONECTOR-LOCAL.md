# Conector local CAS — proiect (nevalidat în producție)

Stare: **proiectat, neimplementat, nevalidat**. Necesită certificat calificat, token, acces SIUI de test și acordul
clinicii.

## De ce e nevoie de o componentă locală

- Semnarea XML-ului și autentificarea la SIUI folosesc certificatul calificat al utilizatorului, de regulă pe un
  eToken USB (specificația PIAS 3.7.34, §2.3). Cheia privată nu părăsește tokenul.
- Semnătura cu cardul pacientului cere cititor de card și eCard SDK (Anexa 102), pe calculatorul din cabinet.
- Hostingul cPanel este un server partajat, fără acces la dispozitivele USB ale clinicii. **Serverul nu poate și nu
  trebuie să semneze în numele utilizatorului.**

## Arhitectura propusă

```
[CRM pe cPanel]  ⇄ HTTPS (pull) ⇄  [Conector pe PC-ul responsabilului CAS]  ⇄  token USB / cititor card
                                                    ⇣
                                       SIUI (HTTPS cu certificat client)
```

1. Conectorul rulează pe calculatorul din clinică (serviciu Windows sau aplicație în bara de sistem). Nu deschide
   porturi către internet; **inițiază** el conexiunile către CRM (pull), deci nu e nevoie de IP public.
2. Asocierea se face o singură dată: responsabilul CAS generează în CRM un cod de asociere de unică folosință
   (valabil 10 minute); conectorul primește un token de dispozitiv cu drepturi limitate la endpointurile CAS,
   revocabil din CRM.
3. Conectorul descarcă loturile marcate „gata de transmitere”, le afișează utilizatorului (rezumat + checksum),
   cere confirmarea explicită și PIN-ul tokenului, semnează local, transmite la SIUI prin serviciul web oficial
   (Anexa 001), descarcă fișierul de răspuns și le încarcă înapoi în CRM: fișierul exact semnat, dovada transmiterii,
   răspunsul.
4. CRM-ul verifică checksumul fișierului semnat față de lot (nicio modificare de conținut în afara semnăturii),
   marchează lotul „transmis” cu autor/dată și apoi importă răspunsul.

## Limite asumate

- Fără certificat și acces de test, endpointurile, formatul exact al semnăturii și comportamentul serviciului web nu
  pot fi verificate. Nu le implementăm „din presupuneri”.
- Până la validare, fluxul oficial offline/manual rămâne cel folosit: export XML din CRM → încărcare în aplicația de
  raportare existentă (sau depunere la casă) → import răspuns în CRM. Vezi `docs/manuale/MANUAL-CAS.md`.
