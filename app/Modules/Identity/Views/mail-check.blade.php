@extends('layouts.panou')
@section('title', 'Verificare e-mail')
@section('content')
    <x-panou.page-head title="Verificare e-mail"
        sub="De aici vedeți dacă solicitările de programare pot pleca prin e-mail, configurați serverul de e-mail (fără Terminal) și trimiteți un e-mail de test." />

    @if ($blocked)
        <div class="alert alert--danger" role="alert"><x-panou.icon name="warn" /><div><strong>E-mailurile nu pleacă:</strong> {{ $blocked }}</div></div>
    @else
        <div class="alert alert--ok" role="status"><x-panou.icon name="check" /><div>E-mailurile pleacă prin <strong>{{ $transport }}</strong>. Trimiteți un test ca să confirmați că ajung.</div></div>
    @endif

    <div class="grid grid--aside">
        <div class="stack">
            <x-panou.panel title="1. Trimite un e-mail de test">
                <form method="post" action="{{ route('panou.mail-check.test') }}" class="cluster">
                    @csrf
                    <x-panou.field name="to" type="email" label="Adresa" :value="old('to', $recipients[0] ?? '')" required />
                    <button class="btn btn--primary" type="submit"><x-panou.icon name="send" /> Trimite testul</button>
                </form>
                <p class="small muted">Dacă testul nu ajunge în câteva minute (verificați și Spam), completați serverul de e-mail mai jos.</p>
            </x-panou.panel>

            <x-panou.panel title="2. Serverul de e-mail (SMTP)">
                <p class="small">Datele contului de e-mail din cPanel → <strong>Email Accounts</strong> → „Connect Devices”: serverul de ieșire (ex. <code>mail.sevalia.ro</code>), portul 465 (SSL), adresa completă ca utilizator și parola contului. Lăsați „Server” gol ca să folosiți setările din instalare.</p>
                <form method="post" action="{{ route('panou.mail-check.smtp') }}" class="form-grid">
                    @csrf @method('put')
                    <x-panou.field name="host" label="Server (SMTP)" :value="old('host', $smtp['host'])" placeholder="mail.sevalia.ro" />
                    <x-panou.field name="port" type="number" label="Port" :value="old('port', $smtp['port'])" />
                    <x-panou.field name="encryption" type="select" label="Criptare" :value="old('encryption', $smtp['encryption'])" :options="['ssl' => 'SSL (port 465)', 'tls' => 'TLS / STARTTLS (port 587)', 'none' => 'fără']" :placeholder="false" />
                    <x-panou.field name="username" label="Utilizator (adresa de e-mail)" :value="old('username', $smtp['username'])" />
                    <x-panou.field name="password" type="password" label="Parola" :hint="$smtp['has_password'] ? 'Salvată. Lăsați gol ca să o păstrați.' : 'Parola contului de e-mail.'" />
                    <x-panou.field name="from_address" type="email" label="Expeditor (adresa)" :value="old('from_address', $smtp['from_address'])" hint="De obicei aceeași cu utilizatorul." />
                    <x-panou.field name="from_name" label="Expeditor (nume)" :value="old('from_name', $smtp['from_name'])" />
                    <div class="form-actions field--full"><button class="btn btn--primary" type="submit">Salvează serverul de e-mail</button></div>
                </form>
            </x-panou.panel>

            <x-panou.panel title="Ultimele trimiteri" :flush="true">
                @if ($log === [])
                    <x-panou.empty title="Nicio trimitere încă">Aici apar solicitările de programare trimise prin e-mail și testele.</x-panou.empty>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th scope="col">Când</th><th scope="col">Cod</th><th scope="col">Prin</th><th scope="col">Rezultat</th></tr></thead>
                        <tbody>
                        @foreach ($log as $row)
                            <tr>
                                <td class="num small">{{ $row['at'] }}</td>
                                <td class="num small">{{ $row['cod'] }}</td>
                                <td class="small">{{ $row['mailer'] === 'log' ? 'doar jurnal (nu a plecat)' : $row['mailer'] }}</td>
                                <td>@if ($row['ok'] && $row['mailer'] !== 'log')<x-panou.badge tone="ok">predat serverului</x-panou.badge>@elseif ($row['ok'])<x-panou.badge tone="warn">nu a plecat</x-panou.badge>@else<x-panou.badge tone="danger">eroare</x-panou.badge> <span class="small">{{ $row['eroare'] }}</span>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-panou.panel>
        </div>
        <div class="stack">
            <x-panou.panel title="Starea actuală">
                <dl class="facts">
                    <dt>Mod aplicație</dt><dd>{{ $stage }}</dd>
                    <dt>Trimitere prin</dt><dd>{{ $transport }}</dd>
                    <dt>Expeditor</dt><dd>{{ $from ?: '—' }}</dd>
                    <dt>Primesc solicitările</dt><dd>{{ $recipients ? implode(', ', $recipients) : 'nimeni (completați în Reguli programări)' }}</dd>
                </dl>
                <p class="small"><a href="{{ route('panou.scheduling-settings.edit') }}">Schimbă adresele care primesc solicitările</a></p>
            </x-panou.panel>
        </div>
    </div>
@endsection
