@extends('layouts.panou')
@section('title', 'Șabloane mesaje')
@section('content')
    @php use App\Modules\Notifications\Support\MessageType; @endphp
    <x-panou.page-head title="Șabloane mesaje" sub="Textele trimise pacienților, pe tip de mesaj și canal. Fiecare salvare creează o versiune nouă; versiunile vechi rămân în istoric." />
    <x-panou.panel :flush="true">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Tip de mesaj</th>@foreach (MessageType::CHANNELS as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($types as $type => $meta)
                    <tr>
                        <td><strong>{{ $meta['label'] }}</strong><br><span class="small muted">{{ $meta['help'] }}</span></td>
                        @foreach (array_keys(MessageType::CHANNELS) as $channel)
                            @php $t = $active[$type.'|'.$channel] ?? null; @endphp
                            <td>
                                <a class="btn btn--small" href="{{ route('panou.notifications.templates.edit', [$type, $channel]) }}">{{ $t ? 'Editează' : 'Creează' }}</a>
                                <div class="small muted" style="margin-top:.3rem">{{ $t ? 'versiunea '.$t->version : 'fără șablon' }}@if ($channel === 'whatsapp' && $t && ! $t->provider_template) · <span style="color:var(--warn)">șablon Meta neasociat</span>@endif</div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-panou.panel>
@endsection
