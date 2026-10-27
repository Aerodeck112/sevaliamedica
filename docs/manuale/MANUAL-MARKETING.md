# Marketing și SEO — manual

Meniul **Marketing** din panou (permisiunile „marketing.seo” și „marketing.integrations”). Capturi:
`docs/capturi/etapa-marketing/`.

## Audit SEO automat

- Rulează singur în fiecare noapte (03:17) și se continuă pe loturi din cronul de la minut; butonul
  **Verifică acum** pornește o verificare imediată.
- Scorul siteului este media scorurilor paginilor (100 = nicio problemă). Lângă scor vedeți diferența față de
  auditul anterior și evoluția ultimelor audituri.
- **Ce merită corectat întâi** grupează problemele frecvente. **Erori** (roșu): titlu sau descriere lipsă, H1 lipsă,
  linkuri rupte, trimiteri la siteul vechi, date structurate stricate. **Avertismente**: lungimi nepotrivite,
  duplicate, imagini fără text alternativ, text puțin. **Informații**: nu scad scorul.
- Butonul **Editează** deschide pagina în CMS. Titlul și descrierea pentru Google se schimbă din Pagină → SEO
  (textul vizibil al paginii rămâne neatins).

## Cuvinte cheie

Lista expresiilor pentru care vreți să apară Sevalia (22 implicite, cerute de clinică), fiecare cu pagina care îi
răspunde. Auditul arată unde apare expresia: titlu (cel mai important), H1, descriere, text — „exact” sau
„parțial” (cuvintele în altă ordine). Diacriticele și „Tg.”/„Târgu” sunt tratate la fel.

## Google și AI — pași recomandați la lansare

1. **Search Console**: adăugați proprietatea `sc-domain:sevalia.ro`, alegeți „Etichetă HTML”, copiați eticheta în
   panou, salvați, apăsați „Verificați”. Trimiteți o dată `https://www.sevalia.ro/sitemap.xml` la „Sitemapuri”.
2. **Bing Webmaster Tools**: importați siteul din Search Console (sau eticheta msvalidate.01) și activați
   **IndexNow** în panou — paginile publicate se anunță automat.
3. **Google Business Profile**: puneți linkul profilului în panou și linkul „Cereți recenzii” în Tablete recepție.
4. **Reindexare Google**: după o modificare importantă, linkul „Inspectează în Search Console” → „Solicitați
   indexarea”. (Google nu permite asta automat pentru siteuri obișnuite.)
5. **Asistenți AI**: implicit au acces (vizibilitate maximă). `/llms.txt` se generează din paginile publicate și
   din datele locațiilor; puteți adăuga un rezumat și note — doar informații adevărate și publice.
6. **Măsurare**: Google Analytics 4 / Google Ads se activează din Site → Etichete și cookie (doar cu acordul
   vizitatorului).

Ce nu face aplicația: nu are acces la contul Google al clinicii, nu citește pozițiile din Google și nu garantează
locul 1 — ordinea rezultatelor o decide Google.
