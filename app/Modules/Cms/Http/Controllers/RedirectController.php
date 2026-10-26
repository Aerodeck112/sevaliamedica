<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Services\Html\StructureSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Redirecturi 301/302 individuale. Nu se permit lanțuri (A→B→C) și nici bucle: la salvare, destinațiile care sunt
 * la rândul lor surse de redirect se înlocuiesc cu destinația finală, iar redirecturile existente spre sursa nouă
 * se actualizează (aplatizare).
 */
class RedirectController extends Controller
{
    public function index(): View
    {
        return view('cms::redirects.index', ['redirects' => Redirect::orderBy('source_path')->paginate(50)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $redirect = Redirect::create($data + ['created_by' => $request->user()->id]);
        $this->flatten($redirect);
        Audit::log('cms.redirect.created', $redirect, ['din' => $redirect->source_path, 'spre' => $redirect->target]);

        return back()->with('status', 'Redirectul a fost adăugat.');
    }

    public function update(Request $request, Redirect $redirect): RedirectResponse
    {
        $data = $this->validated($request, $redirect);
        $redirect->update($data);
        $this->flatten($redirect);
        Audit::log('cms.redirect.updated', $redirect, ['din' => $redirect->source_path, 'spre' => $redirect->target]);

        return back()->with('status', 'Redirectul a fost actualizat.');
    }

    public function destroy(Redirect $redirect): RedirectResponse
    {
        Audit::log('cms.redirect.deleted', $redirect, ['din' => $redirect->source_path]);
        $redirect->delete();

        return back()->with('status', 'Redirectul a fost șters.');
    }

    private function validated(Request $request, ?Redirect $redirect = null): array
    {
        $data = $request->validate([
            'source_path' => ['required', 'string', 'max:255', 'regex:~^/[^\s?#]*$~', Rule::unique('cms_redirects', 'source_path')->ignore($redirect?->id)],
            'target' => ['required', 'string', 'max:500'],
            'status_code' => ['required', Rule::in([301, 302])],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], ['source_path.regex' => 'Sursa trebuie să fie o cale care începe cu „/”, fără parametri.']);
        $data['source_path'] = '/'.trim($data['source_path'], '/');
        $data['target'] = trim($data['target']);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        if (! StructureSanitizer::safeUrl($data['target']) || str_starts_with($data['target'], '#')) {
            throw ValidationException::withMessages(['target' => 'Destinația trebuie să fie o cale internă (/…) sau o adresă https://.']);
        }
        if (str_starts_with($data['target'], '/')) {
            $data['target'] = '/'.trim($data['target'], '/');
            $data['target'] = $this->finalTarget($data['target'], $data['source_path']);
        }
        if ($data['target'] === $data['source_path']) {
            throw ValidationException::withMessages(['target' => 'Redirectul ar trimite pagina spre ea însăși (buclă).']);
        }
        if (Page::published()->where('path', $data['source_path'])->exists()) {
            throw ValidationException::withMessages(['source_path' => 'La această adresă există o pagină publicată. Retrageți pagina înainte de a o redirecționa.']);
        }

        return $data;
    }

    /** Urmează redirecturile existente până la destinația finală; detectează buclele. */
    private function finalTarget(string $target, string $source): string
    {
        $seen = [$source => true];
        while (($next = Redirect::where('source_path', $target)->where('is_active', true)->value('target')) !== null) {
            if (isset($seen[$target]) || isset($seen[$next])) {
                throw ValidationException::withMessages(['target' => 'Redirectul ar crea o buclă.']);
            }
            $seen[$target] = true;
            $target = $next;
        }

        return $target;
    }

    /** Redirecturile care duceau spre sursa nouă sunt mutate direct spre destinația ei (fără lanț). */
    private function flatten(Redirect $redirect): void
    {
        Redirect::where('target', $redirect->source_path)->where('id', '!=', $redirect->id)->update(['target' => $redirect->target]);
    }
}
