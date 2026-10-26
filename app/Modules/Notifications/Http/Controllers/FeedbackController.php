<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\FeedbackRequest;
use App\Modules\Notifications\Services\FeedbackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    /** Pagina publică: nu afișează date medicale, doar data vizitei și locația. */
    public function show(string $token, FeedbackService $feedback): Response
    {
        $request = $feedback->find($token);

        return $this->noStore(response()->view('notifications::public.feedback', ['request' => $request, 'token' => $token, 'done' => false], $request ? 200 : 404));
    }

    public function submit(Request $http, string $token, FeedbackService $feedback): Response
    {
        $request = $feedback->find($token);
        abort_unless($request, 404);
        $data = $http->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'max:0'], // capcană anti-spam
        ]);
        $feedback->submit($request, (int) $data['rating'], $data['comment'] ?? null);

        return $this->noStore(response()->view('notifications::public.feedback', ['request' => $request->fresh(), 'token' => $token, 'done' => true]));
    }

    public function index(): View
    {
        $responses = FeedbackRequest::whereNotNull('submitted_at')->with(['appointment.location', 'appointment.staffMember'])->latest('submitted_at')->paginate(30);
        Audit::log('feedback.viewed');

        return view('notifications::feedback.index', [
            'responses' => $responses,
            'average' => FeedbackRequest::whereNotNull('submitted_at')->where('submitted_at', '>=', now()->subDays(90))->avg('rating'),
            'sent' => FeedbackRequest::where('created_at', '>=', now()->subDays(90))->count(),
            'answered' => FeedbackRequest::whereNotNull('submitted_at')->where('created_at', '>=', now()->subDays(90))->count(),
        ]);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
