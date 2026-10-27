# Cheia de semnare a actualizărilor

- `cheie-semnare-e9582d2db2648293.enc` — cheia **privată** Ed25519 de producție, **criptată** cu o frază de acces
  (Argon2id + XSalsa20-Poly1305). Fără fraza de acces fișierul nu poate fi folosit.
- Cheia **publică** corespunzătoare este `config/update-keys/e9582d2db2648293.pub`; aplicația instalată acceptă doar
  pachete semnate cu această cheie.
- Fraza de acces o deține clinica (comunicată o singură dată, în afara repository-ului). Se cere la construirea
  fiecărei actualizări:

```
SEVALIA_FRAZA='…' php tools/release/cheie-protejata.php decripteaza release/cheie-semnare-e9582d2db2648293.enc /tmp/cheie.key
php artisan sevalia:release:build --type=update --from=0.9.1-0.9.99 --key=/tmp/cheie.key --out=/tmp/dist
rm /tmp/cheie.key
```

Istoric: cheia `fb3391f2ed370c28` (2026-10-26) a fost înlocuită la 2026-10-27, înainte de orice instalare, cu
`e9582d2db2648293` (fraza veche nu mai era disponibilă pe mașina de build). Nicio instalare nu a avut încredere în ea.

Dacă fraza de acces se pierde: se generează o cheie nouă (`sevalia:release:keygen`), cheia publică nouă se adaugă în
`config/update-keys/` și se livrează **un pachet complet de instalare** sau o actualizare semnată cu cheia veche
(cât timp e disponibilă). Rotația: `docs/ACTUALIZARI.md`.
