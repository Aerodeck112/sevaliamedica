<?php

namespace App\Modules\Notifications\Channels;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * E-mail prin mailerul Laravel (SMTP configurat în .env: MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD).
 * Din panou se configurează doar expeditorul afișat.
 */
class EmailChannel implements NotificationChannel
{
    public function __construct(private readonly array $config) {}

    public function send(OutgoingMessage $message): SendResult
    {
        if (! filter_var($message->recipient, FILTER_VALIDATE_EMAIL)) {
            return SendResult::permanent('Adresa de e-mail a destinatarului nu este validă.');
        }

        try {
            Mail::raw($message->body, function (Message $mail) use ($message) {
                $mail->to($message->recipient)->subject($message->subject ?: 'SEVALIA');
                if (! empty($this->config['from_address'])) {
                    $mail->from($this->config['from_address'], $this->config['from_name'] ?? 'SEVALIA');
                }
            });
        } catch (TransportExceptionInterface $e) {
            return SendResult::temporary('Serverul de e-mail nu a acceptat mesajul: '.$e->getMessage());
        } catch (Throwable $e) {
            return SendResult::temporary('Eroare la trimiterea e-mailului: '.class_basename($e));
        }

        return SendResult::sent();
    }

    public function configurationErrors(): array
    {
        $errors = [];
        if (! empty($this->config['from_address']) && ! filter_var($this->config['from_address'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresa expeditorului nu este validă.';
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $errors[] = 'Mailerul aplicației este „'.config('mail.default').'”: configurați SMTP în .env (MAIL_MAILER=smtp).';
        }

        return $errors;
    }
}
