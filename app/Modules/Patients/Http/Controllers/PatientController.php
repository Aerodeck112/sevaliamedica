<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Patients\Services\DuplicateDetector;
use App\Modules\Patients\Support\PatientNormalizer;
use App\Modules\Patients\Support\PatientTabRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PatientController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $patients = Patient::query()->active()
            ->when($q !== '', fn ($query) => $query->search($q), fn ($query) => $query->latest('updated_at'))
            ->paginate(25)->withQueryString();

        if ($q !== '') {
            Audit::log('patients.searched', context: ['rezultate' => $patients->total()]);
        }

        return view('patients::index', ['patients' => $patients, 'q' => $q]);
    }

    /** Căutare rapidă (JSON) pentru selectorul de pacient din calendar. Fără CNP în răspuns. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(Patient::query()->active()->search($q)->limit(10)->get()->map(fn (Patient $p) => [
            'id' => $p->id,
            'name' => $p->fullName(),
            'birth_date' => $p->birth_date?->format('d.m.Y'),
            'phone' => $p->phone,
        ]));
    }

    public function create(Request $request): View
    {
        // Precompletare (ex. din solicitarea de pe site): doar câmpuri administrative simple.
        $prefill = array_filter($request->only(['last_name', 'first_name', 'phone', 'email']), fn ($v) => is_string($v) && mb_strlen($v) <= 190);

        return view('patients::form', [
            'patient' => new Patient(['citizenship' => 'română', 'allow_phone' => true] + $prefill),
            'returnTo' => $this->safeReturn($request->query('return_to')),
        ]);
    }

    public function checkDuplicates(Request $request, DuplicateDetector $detector): JsonResponse
    {
        $result = $detector->check($request->only(['cnp', 'foreign_id_number', 'last_name', 'first_name', 'birth_date', 'phone']));

        return response()->json($this->duplicatesPayload($result));
    }

    public function store(Request $request, DuplicateDetector $detector): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request);
        $dupes = $detector->check($data);

        if ($response = $this->duplicateResponse($request, $dupes)) {
            return $response;
        }

        $patient = DB::transaction(function () use ($data, $request) {
            $patient = new Patient;
            $this->fill($patient, $data, $request);
            $patient->created_by = $request->user()->id;
            $patient->save();
            Audit::log('patients.created', $patient, ['avertizari_duplicat_confirmate' => $request->boolean('confirm_not_duplicate')], $patient->id);

            return $patient;
        });

        if ($request->expectsJson()) {
            return response()->json(['id' => $patient->id, 'name' => $patient->fullName(), 'birth_date' => $patient->birth_date?->format('d.m.Y'), 'phone' => $patient->phone], 201);
        }

        $return = $this->safeReturn($request->input('return_to'));

        return redirect($return ? $return.(str_contains($return, '?') ? '&' : '?').'patient_id='.$patient->id : route('panou.patients.show', $patient))
            ->with('status', 'Fișa pacientului a fost creată.');
    }

    public function show(Request $request, Patient $patient, PatientTabRegistry $registry, CareAccess $care): View|RedirectResponse
    {
        if ($patient->merged_into_id) {
            return redirect()->route('panou.patients.show', $patient->merged_into_id)
                ->with('warning', 'Fișa #'.$patient->id.' a fost unită în această fișă.');
        }

        $user = $request->user();
        $tabs = $registry->visibleFor($user, $patient, $care);
        $current = collect($tabs)->firstWhere('key', $request->query('tab')) ?? $tabs[0] ?? null;
        abort_if($current === null, 403);

        Audit::log('patients.viewed', $patient, ['tab' => $current->key], $patient->id);

        return view('patients::show', [
            'patient' => $patient,
            'tabs' => $tabs,
            'current' => $current,
            'content' => ($current->render)($patient, $user),
            'careReason' => $care->resolve($user, $patient),
            'breakGlass' => $care->activeBreakGlass($user, $patient),
        ]);
    }

    public function edit(Patient $patient): View
    {
        abort_if($patient->merged_into_id !== null, 404);

        return view('patients::form', ['patient' => $patient, 'returnTo' => null]);
    }

    public function update(Request $request, Patient $patient, DuplicateDetector $detector): RedirectResponse
    {
        abort_if($patient->merged_into_id !== null, 404);
        $data = $this->validated($request, $patient);
        if (! $request->filled('cnp')) {
            unset($data['cnp']); // câmpul gol nu șterge CNP-ul existent; ștergerea se face explicit
        }
        $dupes = $detector->check($data + ['cnp' => $request->filled('cnp') ? $data['cnp'] : null], $patient->id);
        if ($response = $this->duplicateResponse($request, $dupes)) {
            return $response;
        }

        DB::transaction(function () use ($patient, $data, $request) {
            $this->fill($patient, $data, $request);
            if ($request->boolean('remove_cnp')) {
                $patient->cnp = null;
            }
            $patient->updated_by = $request->user()->id;
            $changed = array_keys($patient->getDirty());
            $patient->save();
            Audit::log('patients.updated', $patient, ['campuri' => array_values(array_diff($changed, ['updated_by', 'search_name', 'phone_normalized', 'updated_at']))], $patient->id);
        });

        return redirect()->route('panou.patients.show', $patient)->with('status', 'Datele au fost salvate.');
    }

    public function revealCnp(Request $request, Patient $patient): JsonResponse|RedirectResponse
    {
        Audit::log('patients.cnp_revealed', $patient, patientId: $patient->id);
        if ($request->expectsJson()) {
            return response()->json(['cnp' => $patient->cnp]);
        }

        return back()->with('revealed_cnp', $patient->cnp);
    }

    private function validated(Request $request, ?Patient $patient = null): array
    {
        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'sex' => ['nullable', Rule::in(array_keys(Patient::SEXES))],
            'cnp' => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                $digits = PatientNormalizer::cnp($value);
                if ($digits && ! PatientNormalizer::cnpIsValid($digits)) {
                    $fail('CNP-ul nu are o structură validă (13 cifre și cifra de control).');
                }
            }],
            'citizenship' => ['nullable', 'string', 'max:60'],
            'foreign_id_type' => ['nullable', 'string', 'max:40'],
            'foreign_id_number' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+ ().\-\/]{6,40}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'county' => ['nullable', 'string', 'max:80'],
            'allow_phone' => ['sometimes', 'boolean'],
            'allow_sms' => ['sometimes', 'boolean'],
            'allow_email' => ['sometimes', 'boolean'],
            'allow_whatsapp' => ['sometimes', 'boolean'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'phone.regex' => 'Numărul de telefon conține caractere nepermise.',
        ]);
        $data['cnp'] = PatientNormalizer::cnp($data['cnp'] ?? null);
        if ($data['cnp'] && ($derived = PatientNormalizer::fromCnp($data['cnp']))) {
            $data['birth_date'] = ($data['birth_date'] ?? null) ?: $derived['birth_date'];
            $data['sex'] = ($data['sex'] ?? null) ?: $derived['sex'];
            if ($data['birth_date'] !== $derived['birth_date']) {
                throw ValidationException::withMessages(['birth_date' => 'Data nașterii nu corespunde CNP-ului.']);
            }
        }

        return $data;
    }

    private function fill(Patient $patient, array $data, Request $request): void
    {
        $wasConsent = (bool) $patient->marketing_consent;
        foreach (['allow_phone', 'allow_sms', 'allow_email', 'allow_whatsapp', 'marketing_consent'] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }
        $patient->fill($data);
        if ($data['marketing_consent'] && ! $wasConsent) {
            $patient->marketing_consent_at = now();
            $patient->marketing_consent_source = 'panou: '.$request->user()->name;
        } elseif (! $data['marketing_consent'] && $wasConsent) {
            $patient->marketing_consent_at = now();
            $patient->marketing_consent_source = 'retras în panou: '.$request->user()->name;
        }
    }

    /** Blochează la CNP identic; la avertizări cere confirmarea explicită. */
    private function duplicateResponse(Request $request, array $dupes): RedirectResponse|JsonResponse|null
    {
        if ($dupes['blocking']->isNotEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Există deja o fișă cu acest identificator.'] + $this->duplicatesPayload($dupes), 422);
            }

            return back()->withInput($request->except('cnp', 'foreign_id_number'))
                ->withErrors(['cnp' => 'Există deja o fișă cu acest CNP / document. Deschideți fișa existentă în loc să creați una nouă.'])
                ->with('duplicates', $this->duplicatesPayload($dupes));
        }
        if ($dupes['warnings']->isNotEmpty() && ! $request->boolean('confirm_not_duplicate')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Posibil duplicat. Confirmați că este o persoană diferită.'] + $this->duplicatesPayload($dupes), 409);
            }

            return back()->withInput($request->except('cnp', 'foreign_id_number'))
                ->with('duplicates', $this->duplicatesPayload($dupes))
                ->with('warning', 'Există fișe asemănătoare. Verificați lista și confirmați dacă este o persoană diferită.');
        }

        return null;
    }

    private function duplicatesPayload(array $dupes): array
    {
        $row = fn (Patient $p, ?string $reason) => [
            'id' => $p->id, 'name' => $p->fullName(), 'birth_date' => $p->birth_date?->format('d.m.Y'),
            'url' => route('panou.patients.show', $p), 'reason' => $reason,
        ];

        return [
            'blocking' => $dupes['blocking']->map(fn ($p) => $row($p, 'același CNP / document'))->all(),
            'warnings' => $dupes['warnings']->map(fn ($w) => $row($w['patient'], $w['reason']))->all(),
        ];
    }

    /** Acceptă doar întoarceri către panou (fără redirecționări deschise). */
    private function safeReturn(?string $url): ?string
    {
        return $url && str_starts_with($url, '/panou/') && ! str_starts_with($url, '//') ? $url : null;
    }
}
