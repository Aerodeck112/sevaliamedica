<?php

namespace App\Modules\Kiosk\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Kiosk\Models\PatientReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'staff_member_id' => ['nullable', 'integer'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $base = PatientReview::query()
            ->when($filters['staff_member_id'] ?? null, fn ($q, $v) => $q->where('staff_member_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \Carbon\Carbon::parse($v)->addDay()));

        $byStaff = (clone $base)->select('staff_member_id', DB::raw('COUNT(*) as total'), DB::raw('AVG(rating) as media'))
            ->groupBy('staff_member_id')->with('staffMember')->get()->sortByDesc('media');
        $byService = (clone $base)->select('service_id', DB::raw('COUNT(*) as total'), DB::raw('AVG(rating) as media'))
            ->groupBy('service_id')->with('service')->get()->sortByDesc('total');
        $distribution = (clone $base)->select('rating', DB::raw('COUNT(*) as total'))->groupBy('rating')->pluck('total', 'rating');

        Audit::log('kiosk.reviews_viewed', context: ['filtre' => array_filter($filters)]);

        return view('kiosk::panel.reviews', [
            'reviews' => (clone $base)->when($filters['rating'] ?? null, fn ($q, $v) => $q->where('rating', $v))
                ->with(['staffMember', 'service', 'patient'])->latest()->paginate(30)->withQueryString(),
            'total' => (clone $base)->count(),
            'average' => (clone $base)->avg('rating'),
            'byStaff' => $byStaff,
            'byService' => $byService,
            'distribution' => $distribution,
            'staff' => StaffMember::active()->pluck('display_name', 'id')->all(),
            'filters' => $filters,
        ]);
    }

    public function publish(Request $request, PatientReview $review): RedirectResponse
    {
        $publish = $request->boolean('published');
        abort_if($publish && ! $review->publish_consent, 422, 'Pacientul nu a acceptat publicarea acestei recenzii.');
        $review->forceFill(['published' => $publish, 'moderated_by' => $request->user()->id, 'moderated_at' => now()])->save();
        Audit::log($publish ? 'kiosk.review_published' : 'kiosk.review_unpublished', $review, patientId: $review->patient_id);

        return back()->with('status', $publish ? 'Recenzia apare pe site.' : 'Recenzia nu mai apare pe site.');
    }
}
