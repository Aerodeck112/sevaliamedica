<?php

namespace App\Core\Mail;

use App\Core\Settings\Settings;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Serverul de e-mail (SMTP) configurat din panou (Administrare → Verificare e-mail), fără Terminal. Dacă este
 * completat, înlocuiește setările MAIL_* din .env. Parola se păstrează criptată cu cheia aplicației și nu se afișează.
 */
final class MailSettings
{
    public const SETTING = 'mail.smtp';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{host: string, port: int, encryption: string, username: string, has_password: bool, from_address: string, from_name: string} */
    public function current(): array
    {
        $s = (array) $this->settings->get(self::SETTING, []);

        return [
            'host' => (string) ($s['host'] ?? ''), 'port' => (int) ($s['port'] ?? 465), 'encryption' => (string) ($s['encryption'] ?? 'ssl'),
            'username' => (string) ($s['username'] ?? ''), 'has_password' => ! empty($s['password']),
            'from_address' => (string) ($s['from_address'] ?? ''), 'from_name' => (string) ($s['from_name'] ?? 'SEVALIA'),
        ];
    }

    /** @param array{host?: ?string, port?: ?int, encryption?: ?string, username?: ?string, password?: ?string, from_address?: ?string, from_name?: ?string} $data */
    public function save(array $data): void
    {
        $old = (array) $this->settings->get(self::SETTING, []);
        $new = [
            'host' => trim((string) ($data['host'] ?? '')), 'port' => (int) ($data['port'] ?? 465),
            'encryption' => in_array($data['encryption'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $data['encryption'] : 'ssl',
            'username' => trim((string) ($data['username'] ?? '')),
            // Parola goală în formular = păstrează parola salvată.
            'password' => filled($data['password'] ?? null) ? Crypt::encryptString((string) $data['password']) : ($old['password'] ?? null),
            'from_address' => trim((string) ($data['from_address'] ?? '')), 'from_name' => trim((string) ($data['from_name'] ?? 'SEVALIA')),
        ];
        $this->settings->set(self::SETTING, $new['host'] === '' ? [] : $new);
    }

    /** Aplică setările din panou peste configurarea mailerului (la pornirea aplicației). */
    public function apply(): void
    {
        try {
            $s = (array) $this->settings->get(self::SETTING, []);
        } catch (Throwable) {
            return; // baza de date încă indisponibilă (instalare)
        }
        if (empty($s['host'])) {
            return;
        }
        try {
            $password = ! empty($s['password']) ? Crypt::decryptString($s['password']) : null;
        } catch (Throwable) {
            $password = null;
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => array_merge((array) config('mail.mailers.smtp'), [
                'transport' => 'smtp', 'url' => null, 'host' => $s['host'], 'port' => (int) $s['port'],
                'scheme' => ($s['encryption'] ?? 'ssl') === 'ssl' ? 'smtps' : 'smtp',
                'username' => $s['username'] ?: null, 'password' => $password, 'timeout' => 15,
            ]),
        ]);
        if (! empty($s['from_address'])) {
            config(['mail.from.address' => $s['from_address'], 'mail.from.name' => $s['from_name'] ?: 'SEVALIA']);
        }
    }
}
