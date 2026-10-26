<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\FeedbackRequest;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Formularul public de feedback: o notă 1–5 și un comentariu opțional, fără date medicale. */
class FeedbackService
{
    public function __construct(private readonly NotificationSettings $settings) {}

    /** Creează (o singură dată per programare) cererea și întoarce linkul, sau null dacă există deja. */
    public function issue(Appointment $appointment): ?string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            FeedbackRequest::create([
                'token_hash' => hash('sha256', $token),
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'expires_at' => now()->addDays((int) $this->settings->get('notifications.feedback_ttl_days')),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return route('feedback.show', $token);
    }

    public function find(string $token): ?FeedbackRequest
    {
        if (strlen($token) < 40 || strlen($token) > 64) {
            return null;
        }

        return FeedbackRequest::with('appointment.location')->where('token_hash', hash('sha256', $token))->first();
    }

    public function submit(FeedbackRequest $request, int $rating, ?string $comment): void
    {
        $updated = FeedbackRequest::whereKey($request->id)->whereNull('submitted_at')->where('expires_at', '>', now())
            ->update(['rating' => max(1, min(5, $rating)), 'submitted_at' => now()]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['rating' => 'Linkul a fost deja folosit sau a expirat.']);
        }
        if ($comment !== null && trim($comment) !== '') {
            $request->refresh()->forceFill(['comment' => mb_substr(trim($comment), 0, 1000)])->save();
        }
    }
}
