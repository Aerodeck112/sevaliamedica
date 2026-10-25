<div class="grid grid--aside">
    <x-panou.panel title="Documente" :flush="true">
        @if ($documents->isEmpty())
            <x-panou.empty title="Niciun document">Rezultatele, scrisorile medicale și consimțămintele scanate încărcate apar aici.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Încărcat</th><th>Document</th><th>Categorie</th><th>Fișier</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td class="num">{{ $doc->created_at->format('d.m.Y') }}<div class="small muted">{{ $doc->uploader?->name }}</div></td>
                            <td>
                                @if ($doc->status === 'activ')
                                    <a class="row-link" href="{{ route('panou.medical.documents.show', $doc) }}" target="_blank" rel="noopener">{{ $doc->title }}</a>
                                @else
                                    <s>{{ $doc->title }}</s> <x-panou.badge>retras</x-panou.badge>
                                    <div class="small muted">{{ $doc->retract_reason }}</div>
                                @endif
                            </td>
                            <td>{{ $doc->categoryLabel() }}</td>
                            <td class="small">{{ strtoupper(\Illuminate\Support\Str::after($doc->mime, '/')) }} · {{ $doc->humanSize() }}</td>
                            <td class="text-right">
                                @if ($doc->status === 'activ')
                                    <a class="btn btn--small" href="{{ route('panou.medical.documents.download', $doc) }}">Descarcă</a>
                                    @can('medical.documents.retract')
                                        <details style="display:inline-block;text-align:left">
                                            <summary class="btn btn--small btn--ghost">Retrage</summary>
                                            <form method="post" action="{{ route('panou.medical.documents.retract', $doc) }}" class="stack" style="margin-top:.4rem;min-width:220px">
                                                @csrf
                                                <input type="text" name="reason" required minlength="5" placeholder="Motiv (ex. încărcat la alt pacient)" aria-label="Motivul retragerii">
                                                <button class="btn btn--small btn--danger" type="submit">Retrage documentul</button>
                                            </form>
                                        </details>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>

    @can('medical.write')
        <x-panou.panel title="Încarcă document">
            <form method="post" action="{{ route('panou.medical.documents.store', $patient) }}" enctype="multipart/form-data" class="stack">
                @csrf
                <x-panou.field name="title" label="Titlu" required />
                <x-panou.field name="category" type="select" label="Categorie" :options="\App\Modules\Medical\Models\MedicalDocument::CATEGORIES" value="rezultat" :placeholder="false" />
                <div class="field">
                    <label class="field__label" for="f_file">Fișier<span class="req" aria-hidden="true">*</span></label>
                    <input type="file" id="f_file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp" aria-describedby="f_file_h">
                    <span class="field__hint" id="f_file_h">PDF, JPG, PNG sau WEBP, maximum {{ round(config('medical.max_upload_kb') / 1024) }} MB. Imaginile se reîncodează (fără metadate); PDF-urile cu elemente active sunt respinse. Fișierul se păstrează criptat.</span>
                    @error('file')<span class="field__error">{{ $message }}</span>@enderror
                </div>
                <button class="btn btn--primary" type="submit"><x-panou.icon name="upload" />Încarcă</button>
            </form>
        </x-panou.panel>
    @endcan
</div>
