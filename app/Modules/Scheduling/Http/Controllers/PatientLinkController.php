<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Services\PatientLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Pagina publică a linkului pentru pacient. Afișează doar data, ora, locația și serviciul generic.
 * Răspunsurile nu se pun în cache și nu sunt indexate.
 */
class PatientLinkController extends Controller
{
    public function __construct(private readonly PatientLinkService $links) {}

    public function show(string $token): Response
    {
        $t = $this->links->find($token);

        return $this->noStore(response()->view('scheduling::public.link', [
            'token' => $token,
            't' => $t,
            'appointment' => $t?->appointment,
            'canCancel' => $t ? $this->links->canCancel($t) : false,
            'valid' => $t?->isUsable() ?? false,
        ], $t ? 200 : 404));
    }

    public function cancel(string $token): RedirectResponse
    {
        $t = $this->links->find($token);
        abort_unless($t, 404);
        try {
            $this->links->cancel($t);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('patient-link.show', $token)->with('status', 'Programarea a fost anulată. Vă mulțumim că ne-ați anunțat.');
    }

    public function reschedule(Request $request, string $token): RedirectResponse
    {
        $t = $this->links->find($token);
        abort_unless($t, 404);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:500']]);
        try {
            $this->links->requestReschedule($t, (string) ($data['message'] ?? ''));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('patient-link.show', $token)->with('status', 'Am primit cererea de reprogramare. Vă vom contacta pentru un interval nou. Programarea actuală rămâne valabilă până atunci.');
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
