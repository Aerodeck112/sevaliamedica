<?php

namespace App\Core\Mail;

use App\Core\Settings\Settings;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mailul către clinică la fiecare solicitare de programare de pe site (formularul de contact, programarea online,
 * inclusiv cele confirmate automat și cele păstrate în „Mesaje din site” când preluarea online e oprită).
 *
 * Destinatarii: Administrare → Reguli programări → „E-mail pentru solicitări” (una sau mai multe adrese, separate
 * prin virgulă). E-mailul conține doar datele de contact și ce a ales pacientul în formular; mesajul liber (care
 * poate conține informații medicale) și data nașterii rămân doar în panou, la care trimite linkul.
 *
 * Trimiterea: mailerul aplicației (SMTP din instalare); dacă nu există unul real, programul de e-mail al serverului
 * (sendmail, prezent pe cPanel). În afara producției mesajele se scriu doar în jurnal.
 */
final class RequestAlert
{
    public const SETTING = 'scheduling.online.alert_emails';

    public const DEFAULT = 'office@vitim.ro';

    public const LAST_ERROR = 'scheduling.online.alert_last_error';

    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> */
    public function recipients(): array
    {
        $raw = (string) $this->settings->get(self::SETTING, self::DEFAULT);

        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * @param  array<string, ?string>  $fields  etichetă => valoare (cele goale se omit)
     */
    public function send(string $reference, string $status, array $fields, bool $hasMessage, ?string $panelUrl): void
    {
        $to = $this->recipients();
        if ($to === []) {
            return;
        }
        $lines = ['Solicitare nouă de programare de pe site.', '', 'Cod: '.$reference, 'Stare: '.$status];
        foreach ($fields as $label => $value) {
            if ($value !== null && trim($value) !== '') {
                $lines[] = $label.': '.trim($value);
            }
        }
        if ($hasMessage) {
            $lines[] = 'Mesaj: pacientul a scris un mesaj — îl găsiți în panou (nu se trimite prin e-mail).';
        }
        if ($panelUrl) {
            $lines[] = '';
            $lines[] = 'Deschide în panou: '.$panelUrl;
        }
        $lines[] = '';
        $lines[] = 'Mesaj automat SEVALIA. Adresele care primesc acest e-mail se schimbă din Administrare → Reguli programări.';
        $body = implode("\n", $lines);
        $subject = 'Solicitare nouă de programare – '.($fields['Nume'] ?? $reference).' ('.$reference.')';

        $deliver = function () use ($to, $subject, $body, $reference) {
            try {
                Mail::mailer($this->mailer())->raw($body, function (Message $m) use ($to, $subject) {
                    $m->to($to)->subject($subject)->from($this->fromAddress(), 'SEVALIA – site');
                });
                $this->settings->set(self::LAST_ERROR, null);
            } catch (Throwable $e) {
                // Fără date personale în jurnal: doar codul solicitării și tipul erorii.
                Log::warning('E-mailul pentru solicitarea de programare nu a putut fi trimis', ['cod' => $reference, 'eroare' => class_basename($e)]);
                $this->settings->set(self::LAST_ERROR, now()->format('d.m.Y H:i').' — '.mb_substr($e->getMessage(), 0, 200));
            }
        };
        // Pacientul nu așteaptă după serverul de e-mail: trimiterea are loc după ce pagina de confirmare a plecat.
        app()->runningInConsole() ? $deliver() : app()->terminating($deliver);
    }

    public function mailer(): string
    {
        $real = config('sevalia.stage') === 'production' || (bool) config('notifications.real_sending_outside_production');
        if (! $real) {
            return 'log';
        }
        $default = (string) config('mail.default');
        if ($default === 'log' && is_executable('/usr/sbin/sendmail')) {
            return 'sendmail';
        }

        return $default;
    }

    private function fromAddress(): string
    {
        $from = (string) config('mail.from.address');
        if ($from !== '' && ! str_ends_with($from, '@example.com')) {
            return $from;
        }
        $host = (string) preg_replace('/^www\./', '', (string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return 'noreply@'.($host !== '' ? $host : 'localhost');
    }
}
