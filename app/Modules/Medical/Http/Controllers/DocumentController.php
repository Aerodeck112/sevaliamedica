<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Services\DocumentStorage;
use App\Modules\Medical\Services\MedicalAccess;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    public function __construct(private readonly MedicalAccess $access, private readonly DocumentStorage $storage) {}

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $this->access->authorize($request->user(), $patient, 'medical.write');
        $data = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['required', 'string', 'max:190'],
            'category' => ['required', Rule::in(array_keys(MedicalDocument::CATEGORIES))],
            'medical_record_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['medical_record_id']) && ! MedicalRecord::whereKey($data['medical_record_id'])->where('patient_id', $patient->id)->exists()) {
            abort(422, 'Fișa nu aparține pacientului.');
        }

        $stored = $this->storage->store($request->file('file'));
        $document = DB::transaction(function () use ($stored, $data, $patient, $request) {
            $document = MedicalDocument::create($stored + [
                'patient_id' => $patient->id,
                'medical_record_id' => $data['medical_record_id'] ?? null,
                'title' => $data['title'],
                'category' => $data['category'],
                'uploaded_by' => $request->user()->id,
            ]);
            Audit::log('medical.document_uploaded', $document, ['tip' => $document->mime, 'categorie' => $document->category], $patient->id);

            return $document;
        });

        return redirect()->route('panou.patients.show', [$patient, 'tab' => 'documente'])->with('status', "Documentul „{$document->title}” a fost încărcat și criptat.");
    }

    /** Afișare în browser (imagini, PDF). */
    public function show(Request $request, MedicalDocument $document): Response
    {
        return $this->serve($request, $document, 'inline', 'medical.document_viewed');
    }

    public function download(Request $request, MedicalDocument $document): Response
    {
        return $this->serve($request, $document, 'attachment', 'medical.document_downloaded');
    }

    public function retract(Request $request, MedicalDocument $document): RedirectResponse
    {
        abort_unless($request->user()->can('medical.documents.retract'), 403);
        $this->access->authorize($request->user(), $document->patient);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        if ($document->status !== 'activ') {
            return back()->with('warning', 'Documentul este deja retras.');
        }
        $document->forceFill(['status' => 'retras', 'retract_reason' => $data['reason'], 'retracted_by' => $request->user()->id, 'retracted_at' => now()])->save();
        Audit::log('medical.document_retracted', $document, [], $document->patient_id);

        return back()->with('status', 'Documentul a fost retras. Rămâne vizibil în istoric pentru personalul autorizat.');
    }

    private function serve(Request $request, MedicalDocument $document, string $disposition, string $action): Response
    {
        $reason = $this->access->authorize($request->user(), $document->patient);
        try {
            $bytes = $this->storage->read($document);
        } catch (RuntimeException $e) {
            Audit::log('medical.document_unreadable', $document, [], $document->patient_id);
            abort(500, 'Documentul nu poate fi citit. Administratorul tehnic a fost înregistrat în jurnal.');
        }
        Audit::log($action, $document, ['acces' => $reason], $document->patient_id);

        $name = $document->original_name ?: 'document';

        return response($bytes, 200, [
            'Content-Type' => $document->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => $disposition.'; filename="'.addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\').'"; filename*=UTF-8\'\''.rawurlencode($name),
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
