@extends('layouts.panou')
@section('title', 'Canale notificări')
@section('content')
    @php use App\Modules\Notifications\Support\MessageType; use App\Modules\Notifications\Models\NotificationChannelConfig as C; use App\Modules\Notifications\Http\Controllers\ChannelController as CC; @endphp
    <x-panou.page-head title="Canale și reguli de notificare" sub="Mesajele pleacă doar pe canalele configurate aici și doar pe cele permise de fiecare pacient în fișă (canale operaționale, separate de marketing)." />

    @unless ($realAllowed)
        <div class="alert alert--warn" role="note"><x-panou.icon name="warn" /><div>
            <strong>Mediu „{{ $stage }}”: trimiterea reală este oprită.</strong> Livrările se înregistrează ca „Simulat”, fără să plece către pacienți.
            Se activează automat în producție (sau explicit, pentru un test controlat, cu <code>SEVALIA_NOTIFICATIONS_REAL_SENDING=true</code>).
        </div></div>
    @endunless

    <div class="grid grid--3">
        @foreach ($channels as $channel => $info)
            @php $cfg = $info['config']; @endphp
            <x-panou.panel :title="MessageType::CHANNELS[$channel]">
                <x-slot:actions>
                    @if ($cfg->isUsable())<x-panou.badge tone="ok">activ</x-panou.badge>@elseif ($cfg->driver === 'neconfigurat')<x-panou.badge>neconfigurat</x-panou.badge>@else<x-panou.badge tone="warn">dezactivat</x-panou.badge>@endif
                </x-slot:actions>
                <form method="post" action="{{ route('panou.notifications.channels.update', $channel) }}" class="stack">
                    @csrf @method('put')
                    <x-panou.field :name="'driver'" :id="'driver_'.$channel" type="select" label="Mod de trimitere" :value="$cfg->driver" :options="C::DRIVERS[$channel]" :placeholder="false" />
                    @foreach (CC::FIELDS as $driver => $fields)
                        @if ($driver === $cfg->driver)
                            @foreach ($fields as $key => [$label, $type, $secret, $hint])
                                @php $isSelect = str_starts_with($type, 'select:'); @endphp
                                @if ($isSelect)
                                    @php $opts = collect(explode(',', substr($type, 7)))->mapWithKeys(fn ($o) => [$o => $o])->all(); @endphp
                                    <x-panou.field :name="'config['.$key.']'" :id="$channel.'_'.$key" type="select" :label="$label" :value="$cfg->setting($key)" :options="$opts" :placeholder="false" :hint="$hint" />
                                @else
                                    <x-panou.field :name="'config['.$key.']'" :id="$channel.'_'.$key" :type="$type" :label="$label"
                                        :value="$secret ? null : $cfg->setting($key)" :hint="$secret && $cfg->setting($key) ? 'Setat. '.$hint : $hint" autocomplete="off" />
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                    @if ($cfg->driver === 'neconfigurat')
                        <p class="small muted">Alegeți modul de trimitere și salvați; apoi apar câmpurile de configurare.</p>
                    @endif
                    <x-panou.field :name="'enabled'" :id="'enabled_'.$channel" type="checkbox" label="Canal activ" :value="$cfg->enabled" />
                    @if ($info['errors'])
                        <div class="alert alert--warn"><x-panou.icon name="warn" /><div><ul>@foreach ($info['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
                    @endif
                    <div><button class="btn btn--primary" type="submit">Salvează</button></div>
                </form>
                @if ($cfg->isUsable())
                    <hr class="sep">
                    <form method="post" action="{{ route('panou.notifications.channels.test', $channel) }}" class="stack">
                        @csrf
                        <x-panou.field name="test_recipient" :id="'test_'.$channel" :label="$channel === 'email' ? 'Trimite test la adresa' : 'Trimite test la numărul'" />
                        <div><button class="btn" type="submit"><x-panou.icon name="send" />Trimite test</button></div>
                    </form>
                @endif
            </x-panou.panel>
        @endforeach
    </div>

    <x-panou.panel title="Reguli de trimitere" style="margin-top:1.25rem">
        <form method="post" action="{{ route('panou.notifications.channels.settings') }}">
            @csrf @method('put')
            @foreach ($groups as $group => $defs)
                <fieldset style="margin-bottom:1.25rem">
                    <legend>{{ $group }}</legend>
                    <div class="form-grid">
                        @foreach ($defs as $d)
                            <x-panou.field :name="$d['field']" :type="$d['type']" :label="$d['label'].(isset($d['unit']) ? ' ('.$d['unit'].')' : '')"
                                :value="$values[$d['key']]" :options="$d['options'] ?? []" :placeholder="false" :hint="$d['hint'] ?? null" />
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <button class="btn btn--primary" type="submit">Salvează regulile</button>
        </form>
    </x-panou.panel>
@endsection
