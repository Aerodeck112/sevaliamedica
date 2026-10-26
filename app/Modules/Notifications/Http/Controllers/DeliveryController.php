<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Services\DeliveryDispatcher;
use App\Modules\Notifications\Services\NotificationPlanner;
use App\Modules\Notifications\Support\MessageType;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(NotificationDelivery::LABELS))],
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp', 'niciunul'])],
            'type' => ['nullable', Rule::in(array_keys(MessageType::all()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'patient_id' => ['nullable', 'integer'],
        ]);

        $deliveries = NotificationDelivery::with(['patient', 'appointment'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['patient_id'] ?? null, fn ($q, $v) => $q->where('patient_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \Carbon\Carbon::parse($v)->addDay()))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('notifications::deliveries.index', [
            'deliveries' => $deliveries,
            'filters' => $filters,
            'counts' => NotificationDelivery::selectRaw('status, count(*) as n')->where('created_at', '>=', now()->subDays(7))->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(NotificationDelivery $delivery): View
    {
        Audit::log('notifications.viewed', $delivery, ['tip' => $delivery->type], $delivery->patient_id);

        return view('notifications::deliveries.show', ['delivery' => $delivery->load(['patient', 'appointment.location', 'template', 'creator'])]);
    }

    public function retry(Request $request, NotificationDelivery $delivery, DeliveryDispatcher $dispatcher): RedirectResponse
    {
        if (! $delivery->canRetry()) {
            return back()->with('warning', 'Această livrare nu poate fi reîncercată (stare: '.$delivery->statusLabel().').');
        }
        $dispatcher->retry($delivery, $request->user());

        return back()->with('status', 'Livrarea a fost repusă în coadă; pleacă la următoarea rulare (cel mult un minut).');
    }

    public function create(Request $request, NotificationPlanner $planner): View
    {
        $appointment = Appointment::with(['patient', 'location', 'service'])->findOrFail((int) $request->query('programare'));
        abort_unless($appointment->patient_id, 422, 'Programarea nu are un pacient identificat.');

        return view('notifications::deliveries.create', [
            'appointment' => $appointment,
            'channels' => collect($planner->recipientsFor($appointment->patient, $appointment, ignoreStrategy: true))
                ->mapWithKeys(fn ($r) => [$r['channel'] => MessageType::CHANNELS[$r['channel']].' — '.NotificationPlanner::mask($r['address'])])->all(),
            'nonce' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, NotificationPlanner $planner): RedirectResponse
    {
        $data = $request->validate([
            'appointment_id' => ['required', 'integer', 'exists:appointments,id'],
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'nonce' => ['required', 'uuid'],
        ]);
        $appointment = Appointment::findOrFail($data['appointment_id']);
        $delivery = $planner->manual(MessageType::FOLLOW_UP, $appointment, $data['channel'], $data['nonce'], $request->user());
        if ($delivery) {
            Audit::log('notifications.manual_sent', $delivery, ['tip' => MessageType::FOLLOW_UP, 'canal' => $data['channel']], $appointment->patient_id);

            return redirect()->route('panou.notifications.deliveries.show', $delivery)->with('status', 'Mesajul a fost pus în coadă.');
        }

        return redirect()->route('panou.notifications.deliveries', ['patient_id' => $appointment->patient_id])
            ->with('warning', 'Mesajul nu a fost creat: există deja (trimitere dublă) sau canalul nu este disponibil.');
    }
}
