@extends('layouts.panou')
@section('title', 'Șablon — '.$meta['label'])
@section('content')
    @php use App\Modules\Notifications\Support\MessageType; @endphp
    <x-panou.page-head :title="$meta['label'].' — '.MessageType::CHANNELS[$channel]" :sub="$meta['help']"
        :crumbs="['Șabloane mesaje' => route('panou.notifications.templates'), $meta['label'] => null]" />
    <div class="grid grid--aside">
        <div class="stack">
            <x-panou.panel title="Text">
                <form method="post" action="{{ route('panou.notifications.templates.update', [$type, $channel]) }}" class="stack">
                    @csrf @method('put')
                    @if ($channel === 'email')
                        <x-panou.field name="subject" label="Subiect" :value="$draft['subject']" required />
                    @endif
                    <x-panou.field name="body" type="textarea" label="Text" :value="$draft['body']" required rows="8"
                        :hint="$channel === 'email' ? 'Text simplu; variabilele între acolade.' : 'Maximum '.\App\Modules\Notifications\Services\TemplateRenderer::SMS_MAX.' caractere, fără HTML.'" />
                    @if ($channel === 'whatsapp')
                        <div class="alert alert--info"><x-panou.icon name="warn" /><div>WhatsApp permite mesaje inițiate de clinică doar prin <strong>șabloane aprobate de Meta</strong>. Creați șablonul în WhatsApp Manager cu același conținut, apoi completați numele lui și variabilele, în ordinea parametrilor @{{1}}, @{{2}} …</div></div>
                        <div class="form-grid">
                            <x-panou.field name="provider_template" label="Numele șablonului aprobat" :value="$draft['provider_template']" hint="litere mici, cifre și _" />
                            <x-panou.field name="provider_params" label="Variabile, în ordine" :value="$draft['provider_params']" hint="ex. prenume,data,ora,locatia" />
                        </div>
                    @endif
                    <div class="form-actions">
                        <button class="btn btn--primary" type="submit" name="action" value="save">Salvează ca versiune nouă</button>
                        <button class="btn" type="submit" name="action" value="preview">Previzualizează</button>
                    </div>
                </form>
            </x-panou.panel>
            <x-panou.panel title="Previzualizare (date fictive)">
                @if ($preview['subject'])<p><strong>{{ $preview['subject'] }}</strong></p>@endif
                <p style="white-space:pre-line;background:var(--mist);padding:1rem;border-radius:var(--radius)">{{ $preview['body'] }}</p>
                @if ($channel !== 'email')<p class="small muted">{{ mb_strlen($preview['body']) }} caractere în previzualizare (lungimea reală depinde de valori).</p>@endif
            </x-panou.panel>
        </div>
        <div class="stack">
            <x-panou.panel title="Variabile permise">
                <p class="small muted">Lista este închisă: mesajele nu pot conține diagnostice, observații, documente sau denumirea exactă a procedurii.</p>
                <dl class="facts">
                    @foreach ($variables as $name => $label)<dt><code>{{ '{'.$name.'}' }}</code></dt><dd class="small" style="font-weight:400">{{ $label }}</dd>@endforeach
                </dl>
            </x-panou.panel>
            <x-panou.panel title="Istoric versiuni" :flush="true">
                @if ($history->isEmpty())
                    <x-panou.empty title="Nicio versiune">Prima salvare creează versiunea 1.</x-panou.empty>
                @else
                    <table class="table">
                        <tbody>
                        @foreach ($history as $v)
                            <tr>
                                <td><strong>v{{ $v->version }}</strong> @if ($v->is_active)<x-panou.badge tone="ok">activă</x-panou.badge>@endif<br><span class="small muted">{{ $v->created_at?->format('d.m.Y H:i') }} · {{ $v->author?->name ?? 'instalare' }}</span></td>
                                <td class="text-right">@unless ($v->is_active)<form method="post" action="{{ route('panou.notifications.templates.restore', $v) }}">@csrf<button class="btn btn--small" type="submit">Restaurează</button></form>@endunless</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-panou.panel>
        </div>
    </div>
@endsection
