<?php

namespace App\Modules\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Cloud API (Meta, Graph API): POST https://graph.facebook.com/{versiune}/{phone_number_id}/messages
 * cu „Authorization: Bearer {token}”. Mesajele inițiate de clinică folosesc șabloane aprobate în contul Meta
 * (type = template); textul din panou servește doar pentru previzualizare și ca referință.
 *
 * Configurare: api_version (ex. din documentația Meta în vigoare), phone_number_id, access_token (secret),
 * language_code (ex. ro). NEVALIDAT ÎN PRODUCȚIE până la testul cu contul clinicii.
 */
class WhatsAppCloudChannel implements NotificationChannel
{
    public function __construct(private readonly array $config) {}

    public function send(OutgoingMessage $message): SendResult
    {
        if ($errors = $this->configurationErrors()) {
            return SendResult::permanent(implode(' ', $errors));
        }
        if (! $message->providerTemplate) {
            return SendResult::permanent('Tipul de mesaj nu are asociat un șablon WhatsApp aprobat.');
        }

        $to = preg_replace('/\D/', '', $message->recipient);
        if (str_starts_with($to, '07') && strlen($to) === 10) {
            $to = '4'.$to; // număr românesc fără prefix internațional
        }

        $template = ['name' => $message->providerTemplate, 'language' => ['code' => $this->config['language_code'] ?? 'ro']];
        if ($message->providerParams) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($message->providerParams)),
            ]];
        }

        $url = sprintf('https://graph.facebook.com/%s/%s/messages', rawurlencode($this->config['api_version']), rawurlencode($this->config['phone_number_id']));

        try {
            $response = Http::timeout(15)->withToken($this->config['access_token'])->acceptJson()->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => $template,
            ]);
        } catch (ConnectionException) {
            return SendResult::temporary('API-ul WhatsApp nu răspunde (conexiune).');
        }

        if ($response->successful()) {
            return SendResult::sent($response->json('messages.0.id'));
        }

        $code = $response->json('error.code');
        $error = 'WhatsApp a răspuns cu codul HTTP '.$response->status().($code ? ' (eroare '.$code.')' : '').'.';

        return ($response->status() === 429 || $response->serverError()) ? SendResult::temporary($error) : SendResult::permanent($error);
    }

    public function configurationErrors(): array
    {
        $errors = [];
        if (empty($this->config['api_version']) || ! preg_match('/^v\d+\.\d+$/', (string) $this->config['api_version'])) {
            $errors[] = 'Versiunea Graph API trebuie completată (formatul vNN.N, conform documentației Meta).';
        }
        if (empty($this->config['phone_number_id']) || ! ctype_digit((string) $this->config['phone_number_id'])) {
            $errors[] = 'Lipsește identificatorul numărului de telefon (Phone number ID).';
        }
        if (empty($this->config['access_token'])) {
            $errors[] = 'Lipsește tokenul de acces.';
        }

        return $errors;
    }
}
