<?php

namespace App\Modules\Notifications\Channels;

use Illuminate\Support\Facades\Log;

/**
 * Driver de test: nu trimite nimic. Scrie în jurnal doar canalul, referința și lungimea textului
 * (nu destinatarul și nu textul), ca jurnalul să nu conțină date personale.
 */
class LogChannel implements NotificationChannel
{
    public function send(OutgoingMessage $message): SendResult
    {
        Log::info('Notificare simulată', ['canal' => $message->channel, 'referinta' => $message->reference, 'caractere' => mb_strlen($message->body)]);

        return SendResult::simulated();
    }

    public function configurationErrors(): array
    {
        return [];
    }
}
