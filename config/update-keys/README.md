# Chei publice de încredere pentru pachetele de actualizare

Fiecare fișier `*.pub` conține o cheie publică Ed25519 (32 de octeți, base64, un singur rând).
Identificatorul cheii (`key_id`) este format din primele 16 caractere hex ale SHA-256 al cheii publice și apare în
`manifest.sig`.

- Cheia **privată** nu se pune niciodată în repository sau pe hosting. Se generează pe mașina de build cu
  `php artisan sevalia:release:keygen --out=<director în afara repository-ului>`.
- Adăugarea sau eliminarea unei chei se face numai printr-un release (cod revizuit), nu din panou.
- Rotația cheilor este descrisă în `docs/ACTUALIZARI.md`.

La data livrării nu există încă o cheie de producție: se generează de responsabilul de release al clinicii,
iar fișierul `.pub` se adaugă aici înainte de primul pachet semnat.
