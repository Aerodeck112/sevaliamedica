<?php

namespace App\Modules\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * SMS prin API-ul HTTP al furnizorului ales de clinică, configurat complet din panou (URL, autentificare,
 * numele câmpurilor). Nu presupune un anumit furnizor; parametrii se iau din documentația oficială a acestuia.
 *
 * Configurare: url, method (POST), body_format (json|form), auth_header (ex. Authorization), auth_value (secret),
 * field_to, field_message, field_sender (opțional), sender, message_id_path (opțional, ex. „data.id”).
 * Succes = răspuns HTTP 2xx. 429 și 5xx = eroare temporară; restul 4xx = eroare permanentă.
 */
class GenericHttpSmsChannel implements NotificationChannel
{
    public function __construct(private readonly array $config) {}

    public function send(OutgoingMessage $message): SendResult
    {
        if ($errors = $this->configurationErrors()) {
            return SendResult::permanent(implode(' ', $errors));
        }

        $payload = [
            $this->config['field_to'] => $message->recipient,
            $this->config['field_message'] => $message->body,
        ];
        if (! empty($this->config['field_sender']) && ! empty($this->config['sender'])) {
            $payload[$this->config['field_sender']] = $this->config['sender'];
        }

        $request = Http::timeout(15)->acceptJson();
        if (! empty($this->config['auth_header']) && ! empty($this->config['auth_value'])) {
            $request = $request->withHeaders([$this->config['auth_header'] => $this->config['auth_value']]);
        }
        if (($this->config['body_format'] ?? 'json') === 'form') {
            $request = $request->asForm();
        }

        try {
            $response = strtoupper($this->config['method'] ?? 'POST') === 'GET'
                ? $request->get($this->config['url'], $payload)
                : $request->post($this->config['url'], $payload);
        } catch (ConnectionException $e) {
            return SendResult::temporary('Furnizorul SMS nu răspunde (conexiune).');
        }

        if ($response->successful()) {
            $id = ! empty($this->config['message_id_path']) ? Arr::get((array) $response->json(), $this->config['message_id_path']) : null;

            return SendResult::sent(is_scalar($id) ? (string) $id : null);
        }

        $error = 'Furnizorul SMS a răspuns cu codul '.$response->status().'.';

        return ($response->status() === 429 || $response->serverError()) ? SendResult::temporary($error) : SendResult::permanent($error);
    }

    public function configurationErrors(): array
    {
        $errors = [];
        if (empty($this->config['url']) || ! str_starts_with((string) $this->config['url'], 'https://')) {
            $errors[] = 'Adresa API trebuie să fie HTTPS.';
        }
        foreach (['field_to' => 'câmpul pentru destinatar', 'field_message' => 'câmpul pentru text'] as $key => $label) {
            if (empty($this->config[$key])) {
                $errors[] = 'Lipsește '.$label.'.';
            }
        }

        return $errors;
    }
}
