# După instalare — primii pași în panou (SEVALIA 0.9.1)

Ordinea recomandată. Toate se fac din browser, din panoul `https://<domeniu>/panou`.

1. **Prima autentificare**: e-mailul și parola alese în instalator, apoi configurarea autentificării în doi pași
   (Microsoft Authenticator, Google Authenticator sau 2FAS). Păstrați codurile de rezervă.
2. **Utilizatori**: Administrare → Utilizatori — conturi pentru recepție, medici, terapeuți, manager (fiecare cu
   rolul lui; datele medicale le văd doar cei cu relație de îngrijire).
3. **Clinica**: Administrare → Locații (programul real; atenție la programul de vineri, vezi
   `audit/OBSERVATII-CONTINUT.md`), Personal, Program de lucru (al fiecăruia), Săli, Aparatură, Catalog servicii
   (durate, tarife confirmate).
4. **Programare online**: Administrare → Catalog servicii → bifați „Programare online pe site” la serviciile dorite (și, opțional,
   „Confirmare automată online”); Administrare → Reguli programări → avans minim/maxim, confirmare automată. Până nu bifați niciun serviciu, pagina
   `/programare-online` spune „disponibilă în curând”.
5. **Notificări**: Administrare → Canale notificări → e-mail (SMTP din cPanel), SMS/WhatsApp dacă aveți furnizor.
6. **Google și AI** (Marketing → Google și AI): codul Search Console, linkul Google Business, IndexNow pornit.
   În Search Console trimiteți o dată `https://<domeniu>/sitemap.xml`.
7. **Măsurare**: Site → Etichete și cookie — Google Analytics 4 / Google Ads (pornesc doar cu acordul vizitatorului).
8. **Tablete recepție**: Administrare → Tablete recepție — vezi `manuale/MANUAL-TABLETE.md`; linkul „Cereți recenzii”
   din Google Business în Setări.
9. **Consimțăminte**: Medical → Modele consimțământ — textele validate de clinică (necesare pentru tableta de
   consimțăminte).
10. **Verificare**: Marketing → Audit SEO → „Verifică acum”; deschideți siteul pe telefon; testați o programare
    online și o întrebare la asistent.

Actualizările viitoare: Administrare → Actualizări → încărcați ZIP-ul primit (backupul se face automat înainte).
