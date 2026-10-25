<?php

namespace App\Core\Http\Crud;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Controller generic pentru nomenclatoare și date de referință (listă, adăugare, editare, ștergere).
 * Pentru ecrane operaționale complexe (calendar, fișe) se scriu controllere dedicate.
 */
abstract class ResourceController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Prefixul numelor de rută, ex. „panou.clinic.locations”. */
    abstract protected function routeBase(): string;

    abstract protected function titles(): array; // ['plural' => ..., 'singular' => ..., 'new' => ...]

    /** @return list<Field> */
    abstract protected function fields(): array;

    protected function description(): ?string
    {
        return null;
    }

    protected function query(): Builder
    {
        return ($this->model())::query();
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    protected function auditKey(): string
    {
        return str_replace('panou.', '', $this->routeBase());
    }

    /** Ajustări înainte de salvare (ex. slug). */
    protected function prepare(array $data, ?Model $model): array
    {
        return $data;
    }

    public static function routes(string $uri, string $name): void
    {
        $controller = static::class;
        Route::get($uri, [$controller, 'index'])->name("$name.index");
        Route::get("$uri/nou", [$controller, 'create'])->name("$name.create");
        Route::post($uri, [$controller, 'store'])->name("$name.store");
        Route::get("$uri/{id}", [$controller, 'edit'])->whereNumber('id')->name("$name.edit");
        Route::put("$uri/{id}", [$controller, 'update'])->whereNumber('id')->name("$name.update");
        Route::delete("$uri/{id}", [$controller, 'destroy'])->whereNumber('id')->name("$name.destroy");
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $query = $this->query();
        $searchable = array_filter($this->fields(), fn (Field $f) => $f->searchable);
        if ($q !== '' && $searchable) {
            $query->where(function (Builder $w) use ($searchable, $q) {
                foreach ($searchable as $field) {
                    $w->orWhere($field->name, 'like', '%'.addcslashes($q, '%_').'%');
                }
            });
        }

        return view('crud.index', [
            'titles' => $this->titles(),
            'description' => $this->description(),
            'fields' => array_values(array_filter($this->fields(), fn (Field $f) => $f->inList)),
            'searchable' => (bool) $searchable,
            'items' => $this->orderBy($query)->paginate(30)->withQueryString(),
            'routeBase' => $this->routeBase(),
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return $this->form(new ($this->model()));
    }

    public function edit(int $id): View
    {
        return $this->form($this->query()->findOrFail($id));
    }

    public function store(Request $request): RedirectResponse
    {
        $model = new ($this->model());
        $this->save($request, $model);
        Audit::log($this->auditKey().'.created', $model);

        return redirect()->route($this->routeBase().'.index')->with('status', $this->titles()['singular'].' a fost adăugat(ă).');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $model = $this->query()->findOrFail($id);
        $before = $model->getAttributes();
        $this->save($request, $model);
        Audit::log($this->auditKey().'.updated', $model, ['campuri' => array_keys(array_diff_assoc(array_map('strval', $model->getAttributes()), array_map('strval', $before)))]);

        return redirect()->route($this->routeBase().'.index')->with('status', 'Modificările au fost salvate.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $model = $this->query()->findOrFail($id);
        try {
            DB::transaction(function () use ($model) {
                $model->delete();
                Audit::log($this->auditKey().'.deleted', $model);
            });
        } catch (QueryException) {
            return back()->with('warning', 'Înregistrarea este folosită în alte date și nu poate fi ștearsă. O puteți dezactiva.');
        }

        return redirect()->route($this->routeBase().'.index')->with('status', 'Înregistrarea a fost ștearsă.');
    }

    protected function form(Model $model): View
    {
        return view('crud.form', [
            'titles' => $this->titles(),
            'fields' => $this->fields(),
            'model' => $model,
            'routeBase' => $this->routeBase(),
        ]);
    }

    protected function save(Request $request, Model $model): void
    {
        $rules = [];
        foreach ($this->fields() as $field) {
            $rules[$field->name] = $field->rules ?: ['nullable'];
            if ($field->type === 'multiselect') {
                $rules[$field->name] = ['array'];
                $rules[$field->name.'.*'] = ['integer'];
            }
            if ($field->type === 'checkbox') {
                $rules[$field->name] = ['boolean'];
            }
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($data, $model) {
            $data = $this->prepare($data, $model->exists ? $model : null);
            $relations = [];
            foreach ($this->fields() as $field) {
                if ($field->type === 'multiselect') {
                    $relations[$field->relation ?? $field->name] = $data[$field->name] ?? [];
                    unset($data[$field->name]);
                } elseif ($field->type === 'checkbox') {
                    $data[$field->name] = (bool) ($data[$field->name] ?? false);
                }
            }
            $model->fill($data)->save();
            foreach ($relations as $relation => $ids) {
                $model->{$relation}()->sync($ids);
            }
        });
    }
}
