<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Cms\Services\MediaLibrary;
use App\Modules\Site\Services\PageCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $filter = $request->query('filtru');
        $media = Media::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('original_name', 'like', "%$q%")->orWhere('alt', 'like', "%$q%")->orWhere('id', $q)))
            ->when($filter === 'fara-alt', fn ($w) => $w->where(fn ($x) => $x->whereNull('alt')->orWhere('alt', '')))
            ->orderByDesc('id')
            ->paginate(48)
            ->withQueryString();

        return view('cms::media.index', ['media' => $media, 'q' => $q, 'filter' => $filter]);
    }

    /** Lista pentru selectorul de imagini din editor (JSON). */
    public function picker(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $items = Media::where('mime', 'like', 'image/%')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('original_name', 'like', "%$q%")->orWhere('alt', 'like', "%$q%")->orWhere('id', $q)))
            ->orderByDesc('id')->limit(120)->get();

        return response()->json($items->map(fn (Media $m) => [
            'id' => $m->id,
            'name' => (string) $m->original_name,
            'thumb' => $m->bestUrl(320),
            'alt' => $m->alt,
        ]));
    }

    public function store(Request $request, MediaLibrary $library): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'max:'.(MediaLibrary::MAX_UPLOAD_BYTES / 1024)],
            'alt' => ['nullable', 'string', 'max:300'],
        ], ['files.*.max' => 'Fiecare fișier poate avea cel mult 10 MB.']);

        $count = 0;
        foreach ($request->file('files') as $file) {
            $media = $library->upload($file, $request->input('alt'));
            Audit::log('cms.media.uploaded', $media, ['nume' => $media->original_name]);
            $count++;
        }

        return back()->with('status', $count === 1 ? 'Imaginea a fost încărcată.' : "Au fost încărcate $count imagini.");
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $data = $request->validate(['alt' => ['nullable', 'string', 'max:300'], 'title' => ['nullable', 'string', 'max:255']]);
        $media->update($data);
        Audit::log('cms.media.updated', $media);
        PageCache::flush();

        return back()->with('status', 'Textul alternativ a fost salvat. Se aplică imaginilor alese de acum înainte; imaginile deja puse în pagini își păstrează textul din pagină.');
    }

    public function destroy(Media $media): RedirectResponse
    {
        if ($where = $this->usage($media)) {
            return back()->with('warning', 'Imaginea este folosită ('.$where.') și nu poate fi ștearsă.');
        }
        $paths = [$media->path, ...array_values((array) ($media->variants['webp'] ?? [])), ...(isset($media->variants['display']) ? [$media->variants['display']] : [])];
        foreach ($paths as $path) {
            @unlink(Media::baseDirectory().'/'.$path);
        }
        Audit::log('cms.media.deleted', $media, ['nume' => $media->original_name]);
        $media->delete();

        return back()->with('status', 'Imaginea a fost ștearsă.');
    }

    /** Unde este folosită o imagine (în orice versiune, meniu, fișă de personal) — ca să nu rupem istoricul. */
    private function usage(Media $media): ?string
    {
        if (StaffMember::where('photo_media_id', $media->id)->exists()) {
            return 'fotografie de personal';
        }
        if (Page::where('cover_media_id', $media->id)->exists()) {
            return 'copertă de articol';
        }
        // Imaginile din conținut sunt referite prin adresa fișierului (src / url()), inclusiv în versiunile vechi.
        $path = str_replace('/', '\\/', $media->path);
        if (PageVersion::where('blocks', 'like', '%'.$media->path.'%')->orWhere('blocks', 'like', '%'.$path.'%')->exists()) {
            return 'în conținutul unei pagini sau al unei versiuni din istoric';
        }
        if (MenuItem::where('mega_block', 'like', '%'.$media->path.'%')->exists()) {
            return 'în meniu';
        }

        return null;
    }
}
