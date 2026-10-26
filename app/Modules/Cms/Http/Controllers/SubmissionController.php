<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mesajele primite prin formularele siteului (când nu sunt preluate direct de modulul de programări).
 * Conținutul e criptat în baza de date; fiecare consultare se înregistrează în jurnalul de audit.
 */
class SubmissionController extends Controller
{
    public const STATUSES = ['noua' => 'Nouă', 'preluata' => 'Preluată', 'arhivata' => 'Arhivată'];

    public function index(Request $request): View
    {
        $status = $request->query('stare', 'noua');
        $items = FormSubmission::when(isset(self::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('cms::submissions.index', ['items' => $items, 'status' => $status]);
    }

    public function show(FormSubmission $submission): View
    {
        Audit::log('cms.submission.viewed', $submission);

        return view('cms::submissions.show', [
            'item' => $submission,
            'specialty' => ($id = $submission->payload['specialitate_id'] ?? null) ? Specialty::find($id) : null,
            'staff' => ($id = $submission->payload['medic_id'] ?? null) ? StaffMember::find($id) : null,
        ]);
    }

    public function update(Request $request, FormSubmission $submission): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))]]);
        $submission->update(['status' => $data['status'], 'handled_by' => $request->user()->id, 'handled_at' => now()]);
        Audit::log('cms.submission.status', $submission, ['stare' => $data['status']]);

        return back()->with('status', 'Starea mesajului a fost actualizată.');
    }
}
