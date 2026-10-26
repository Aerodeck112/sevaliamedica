<?php

namespace App\Modules\Notifications\Channels;

/**
 * Interfața comună a driverelor de trimitere. Un driver primește doar destinatarul și textul deja randat
 * (fără date medicale — șabloanele nu au acces la ele) și întoarce rezultatul, fără să arunce excepții
 * cu date personale.
 */
interface NotificationChannel
{
    public function send(OutgoingMessage $message): SendResult;

    /** Verifică dacă driverul are configurarea minimă (fără a trimite nimic). */
    public function configurationErrors(): array;
}
