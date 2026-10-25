<x-panou.panel title="Resurse necesare" style="margin-top:1.25rem">
    <p class="muted small">La programare se rezervă automat, pe lângă persoana care efectuează serviciul, câte o resursă liberă din fiecare tip de mai jos, din aceeași locație.</p>
    @if ($service->requirements->isEmpty())
        <p>Serviciul nu necesită sală sau aparat dedicat.</p>
    @else
        <table class="table" style="max-width:560px">
            <thead><tr><th>Resursă</th><th>Tip</th><th>Cantitate</th><th></th></tr></thead>
            <tbody>
            @foreach ($service->requirements as $req)
                <tr>
                    <td>{{ $req->resource_type === 'room' ? 'Sală' : 'Aparat' }}</td>
                    <td><code>{{ $req->resource_kind }}</code></td>
                    <td>{{ $req->quantity }}</td>
                    <td class="text-right">
                        <form method="post" action="{{ route('panou.clinic.services.requirements.destroy', [$service->id, $req->id]) }}">@csrf @method('delete')<button class="btn btn--small btn--danger" type="submit">Elimină</button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <form method="post" action="{{ route('panou.clinic.services.requirements.store', $service->id) }}" class="cluster" style="margin-top:1rem;align-items:flex-end">
        @csrf
        <x-panou.field name="resource_type" type="select" label="Resursă" :options="['room' => 'Sală', 'equipment' => 'Aparat']" :placeholder="false" />
        <x-panou.field name="resource_kind" label="Tip (cheie)" required />
        <x-panou.field name="quantity" type="number" label="Cantitate" value="1" required />
        <button class="btn" type="submit">Adaugă</button>
    </form>
</x-panou.panel>
