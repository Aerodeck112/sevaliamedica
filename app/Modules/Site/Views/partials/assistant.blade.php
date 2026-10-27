{{-- Asistentul Sevalia: răspunsuri din informațiile publice ale siteului (modulul Assistant). --}}
<div class="sv-assist" data-assistant data-endpoint="{{ route('asistent.ask', absolute: false) }}">
    <button type="button" class="sv-assist__fab" aria-expanded="false" aria-controls="sv-assist-panel">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6A.5.5 0 0 1 5 19.2V16h0a2.5 2.5 0 0 1-1-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9.5h7M8.5 12.5h4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        <span>Întrebați asistentul</span>
    </button>
    <section id="sv-assist-panel" class="sv-assist__panel" role="dialog" aria-modal="false" aria-labelledby="sv-assist-title" hidden>
        <header class="sv-assist__head">
            <div>
                <h2 id="sv-assist-title" class="sv-assist__title">Asistentul Sevalia</h2>
                <p class="sv-assist__sub">Răspunsuri din informațiile clinicii</p>
            </div>
            <button type="button" class="sv-assist__close" data-assistant-close aria-label="Închide asistentul">×</button>
        </header>
        <div class="sv-assist__log" aria-live="polite">
            <div class="sv-assist__msg sv-assist__msg--bot">Bună ziua! Vă pot spune programul, adresele, cum vă programați și ce terapii oferim. Ce vă interesează?</div>
        </div>
        <div class="sv-assist__chips">
            @foreach (['Care este programul?', 'Unde vă aflați?', 'Cum mă programez?', 'Kinetoterapie pentru copii', 'Ce este EMSELLA?', 'Tarife'] as $chip)
                <button type="button" class="sv-assist__chip" data-ask="{{ $chip }}">{{ $chip }}</button>
            @endforeach
        </div>
        <form class="sv-assist__form" data-assistant-form>
            <label class="visually-hidden" for="sv-assist-q">Întrebarea dumneavoastră</label>
            <input id="sv-assist-q" name="q" maxlength="300" autocomplete="off" placeholder="Scrieți o întrebare…" required>
            <button type="submit" aria-label="Trimite întrebarea">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M4 12 20 4l-6 16-2.5-6.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </button>
        </form>
        <p class="sv-assist__note">Nu oferă sfaturi medicale. Nu scrieți date personale sau medicale. Urgențe: <strong>112</strong>.</p>
    </section>
</div>
