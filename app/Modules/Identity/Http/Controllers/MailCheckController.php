<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Mail\MailSettings;
use App\Core\Mail\RequestAlert;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/** Administrare → Verificare e-mail: diagnostic, server SMTP și e-mail de test (permisiunea settings.manage). */
class MailCheckController extends Controller
{
    public function __construct(private readonly RequestAlert $alert, private readonly MailSettings $smtp, private readonly Settings $settings) {}

    public function show(): View
    {
        $mailer = $this->alert->mailer();

        return view('identity::mail-check', [
            'blocked' => $this->alert->blockedReason(),
            'mailer' => $mailer,
            'stage' => config('sevalia.stage'),
            'transport' => $mailer === 'smtp' ? config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port') : ($mailer === 'sendmail' ? 'programul de e-mail al serverului (sendmail)' : 'doar jurnal'),
            'from' => config('mail.from.address'),
            'recipients' => $this->alert->recipients(),
            'smtp' => $this->smtp->current(),
            'log' => (array) $this->settings->get(RequestAlert::LOG, []),
        ]);
    }

    public function saveSmtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:190'], 'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'in:ssl,tls,none'], 'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'], 'from_address' => ['nullable', 'email', 'max:190'], 'from_name' => ['nullable', 'string', 'max:120'],
        ], ['from_address.email' => 'Adresa expeditorului nu este validă.']);
        $this->smtp->save($data);
        Audit::log('mail.smtp_updated', context: ['server' => $data['host'] ?? null, 'parola_schimbata' => filled($data['password'] ?? null)]);

        return back()->with('status', filled($data['host'] ?? null) ? 'Serverul de e-mail a fost salvat. Trimiteți un e-mail de test.' : 'Setările SMTP din panou au fost șterse; se folosesc cele din instalare.');
    }

    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email']], ['to.email' => 'Adresa nu este validă.']);
        $this->smtp->apply();
        $mailer = $this->alert->mailer();
        if ($reason = $this->alert->blockedReason()) {
            return back()->withErrors(['to' => 'E-mailul de test nu poate pleca: '.$reason]);
        }
        try {
            Mail::mailer($mailer)->raw("Acesta este un e-mail de test trimis din panoul SEVALIA (Administrare → Verificare e-mail).\n\nDacă l-ați primit, solicitările de programare de pe site vor ajunge la adresele din Reguli programări.",
                fn (Message $m) => $m->to($data['to'])->subject('Test e-mail SEVALIA')->from((string) config('mail.from.address') ?: 'noreply@'.preg_replace('/^www\./', '', (string) parse_url((string) config('app.url'), PHP_URL_HOST)), 'SEVALIA'));
        } catch (Throwable $e) {
            $this->alert->record('TEST', false, mb_substr($e->getMessage(), 0, 300));

            return back()->withErrors(['to' => 'Serverul de e-mail a refuzat trimiterea: '.mb_substr($e->getMessage(), 0, 300)]);
        }
        $this->alert->record('TEST', true, null);
        Audit::log('mail.test_sent');

        return back()->with('status', 'E-mailul de test a fost predat serverului ('.$mailer.') pentru '.$data['to'].'. Verificați și folderul Spam.');
    }
}
