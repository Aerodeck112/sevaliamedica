{{--
    Banner și panou de preferințe pentru cookie-uri (construit intern, fără servicii terțe).
    Refuzul este la fel de ușor ca acceptarea (butoane de aceeași mărime, pe primul ecran).
    Se afișează din JavaScript doar dacă vizitatorul nu a ales încă; „Setări cookie” din subsol îl redeschide.
--}}
@php $t = $consent['texts']; @endphp
<div id="sv-cookie" class="sv-cookie" role="dialog" aria-modal="false" aria-labelledby="sv-cookie-title" aria-describedby="sv-cookie-body" hidden>
    <div class="sv-cookie__inner">
        <h2 id="sv-cookie-title" class="sv-cookie__title">{{ $t['title'] }}</h2>
        <p id="sv-cookie-body" class="sv-cookie__body">{{ $t['body'] }} <a href="{{ $t['policy_url'] }}">Politica de confidențialitate</a></p>
        <form class="sv-cookie__prefs" id="sv-cookie-prefs" hidden>
            <fieldset>
                <legend class="visually-hidden">Categorii de cookie-uri</legend>
                <label class="sv-cookie__opt"><input type="checkbox" checked disabled> <span>{{ $t['necessary'] }}</span></label>
                <label class="sv-cookie__opt"><input type="checkbox" name="statistici" value="1"> <span>{{ $t['statistici'] }}</span></label>
                <label class="sv-cookie__opt"><input type="checkbox" name="marketing" value="1"> <span>{{ $t['marketing'] }}</span></label>
            </fieldset>
        </form>
        <div class="sv-cookie__actions">
            <button type="button" class="sv-cookie__btn" data-consent="reject">{{ $t['reject'] }}</button>
            <button type="button" class="sv-cookie__btn" data-consent="accept">{{ $t['accept'] }}</button>
            <button type="button" class="sv-cookie__btn sv-cookie__btn--link" data-consent="customize" aria-controls="sv-cookie-prefs" aria-expanded="false">{{ $t['customize'] }}</button>
            <button type="button" class="sv-cookie__btn" data-consent="save" hidden>{{ $t['save'] }}</button>
        </div>
    </div>
</div>
{{-- Afișare imediată (înaintea scriptului principal), ca bannerul să nu întârzie prima afișare a paginii. --}}
<script nonce="{{ app('csp-nonce') }}">try{if(!/(?:^|;\s*){{ \App\Modules\Site\Services\ConsentConfig::COOKIE }}=/.test(document.cookie)){document.getElementById('sv-cookie').hidden=false;}}catch(e){}</script>
