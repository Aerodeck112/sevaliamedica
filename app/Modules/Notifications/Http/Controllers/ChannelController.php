<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Notifications\Channels\OutgoingMessage;
use App\Modules\Notifications\Models\NotificationChannelConfig;
use App\Modules\Notifications\Services\ChannelManager;
use App\Modules\Notifications\Services\NotificationSettings;
use App\Modules\Notifications\Support\MessageType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Canalele (driver, credențiale) și regulile de trimitere. Secretele nu se afișează niciodată înapoi:
 * un câmp secret lăsat gol păstrează valoarea existentă.
 */
class ChannelController extends Controller
{
    /** Câmpurile fiecărui driver: [cheie => [etichetă, tip, secret?, ajutor]]. */
    public const FIELDS = [
        'smtp' => [
            'from_address' => ['Adresa expeditorului', 'email', false, 'Implicit: MAIL_FROM_ADDRESS din configurare. Serverul SMTP se configurează în .env.'],
            'from_name' => ['Numele expeditorului', 'text', false, null],
        ],
        'http_generic' => [
            'url' => ['Adresa API (HTTPS)', 'url', false, 'Din documentația oficială a furnizorului SMS.'],
            'method' => ['Metoda HTTP', 'select:POST,GET', false, null],
            'body_format' => ['Format corp cerere', 'select:json,form', false, null],
            'auth_header' => ['Antet de autentificare', 'text', false, 'ex. Authorization sau X-API-Key'],
            'auth_value' => ['Valoarea antetului (cheie API)', 'password', true, 'Se păstrează criptat. Lăsați gol pentru a nu o schimba.'],
            'field_to' => ['Câmpul pentru destinatar', 'text', false, 'ex. to'],
            'field_message' => ['Câmpul pentru text', 'text', false, 'ex. message'],
            'field_sender' => ['Câmpul pentru expeditor (opțional)', 'text', false, null],
            'sender' => ['Expeditor afișat (opțional)', 'text', false, 'Numele sau numărul aprobat de furnizor.'],
            'message_id_path' => ['Calea identificatorului în răspuns (opțional)', 'text', false, 'ex. data.id'],
        ],
        'whatsapp_cloud' => [
            'api_version' => ['Versiune Graph API', 'text', false, 'ex. vNN.N — conform documentației Meta în vigoare.'],
            'phone_number_id' => ['Phone number ID', 'text', false, 'Din WhatsApp Manager → API Setup.'],
            'access_token' => ['Token de acces (permanent, de sistem)', 'password', true, 'Se păstrează criptat. Lăsați gol pentru a nu-l schimba.'],
            'language_code' => ['Cod limbă șabloane', 'text', false, 'ex. ro'],
            'test_template' => ['Șablon aprobat folosit la mesajul de test', 'text', false, 'Numele unui șablon fără parametri, aprobat în contul Meta.'],
        ],
    ];

    public function edit(NotificationSettings $settings, ChannelManager $manager): View
    {
        $channels = [];
        foreach (array_keys(MessageType::CHANNELS) as $channel) {
            $config = NotificationChannelConfig::for($channel);
            $channels[$channel] = [
                'config' => $config,
                'errors' => $config->driver !== 'neconfigurat' ? $manager->realDriver($config)->configurationErrors() : [],
            ];
        }

        return view('notifications::channels.edit', [
            'channels' => $channels,
            'groups' => collect(NotificationSettings::definitions())->map(fn ($d, $k) => $d + ['key' => $k, 'field' => str_replace('.', '_', $k)])->groupBy('group'),
            'values' => $settings->all(),
            'realAllowed' => $manager->realSendingAllowed(),
            'stage' => config('sevalia.stage'),
        ]);
    }

    public function updateChannel(Request $request, string $channel, ChannelManager $manager): RedirectResponse
    {
        abort_unless(isset(MessageType::CHANNELS[$channel]), 404);
        $drivers = array_keys(NotificationChannelConfig::DRIVERS[$channel]);
        $data = $request->validate([
            'driver' => ['required', Rule::in($drivers)],
            'enabled' => ['boolean'],
            'config' => ['array'],
            'config.*' => ['nullable', 'string', 'max:500'],
        ]);

        $config = NotificationChannelConfig::for($channel);
        $old = $config->config ?? [];
        $new = [];
        foreach (self::FIELDS[$data['driver']] ?? [] as $key => [$label, $type, $secret]) {
            $value = trim((string) ($data['config'][$key] ?? ''));
            $new[$key] = ($secret && $value === '') ? ($old[$key] ?? null) : ($value === '' ? null : $value);
        }
        $config->fill([
            'driver' => $data['driver'],
            'enabled' => (bool) ($data['enabled'] ?? false) && $data['driver'] !== 'neconfigurat',
            'config' => $new,
            'updated_by' => $request->user()->id,
        ])->save();
        $manager->forget();

        Audit::log('notifications.channel_updated', null, [
            'canal' => $channel, 'driver' => $config->driver, 'activ' => $config->enabled,
            // doar numele câmpurilor modificate; valorile (inclusiv secretele) nu intră în audit
            'campuri_modificate' => array_keys(array_diff_assoc(array_map('strval', array_filter($new)), array_map('strval', array_filter($old)))),
        ]);

        $errors = $config->driver !== 'neconfigurat' ? $manager->realDriver($config)->configurationErrors() : [];

        return back()->with($errors ? 'warning' : 'status', $errors
            ? 'Canalul a fost salvat, dar configurarea e incompletă: '.implode(' ', $errors)
            : 'Canalul '.MessageType::CHANNELS[$channel].' a fost salvat.');
    }

    public function updateSettings(Request $request, NotificationSettings $settings): RedirectResponse
    {
        $rules = [];
        foreach (NotificationSettings::definitions() as $key => $def) {
            $rules[str_replace('.', '_', $key)] = $def['rules'];
        }
        $data = $request->validate($rules);
        $values = [];
        foreach (array_keys(NotificationSettings::definitions()) as $key) {
            $field = str_replace('.', '_', $key);
            if (array_key_exists($field, $data)) {
                $values[$key] = $data[$field];
            }
        }
        $changed = $settings->update($values);

        return back()->with('status', $changed ? 'Regulile au fost salvate ('.count($changed).' modificări, înregistrate în audit).' : 'Nicio modificare.');
    }

    /** Mesaj de test către o adresă introdusă de administrator (în afara producției: simulat). */
    public function test(Request $request, string $channel, ChannelManager $manager): RedirectResponse
    {
        abort_unless(isset(MessageType::CHANNELS[$channel]), 404);
        $data = $request->validate(['test_recipient' => ['required', 'string', 'max:190']]);
        $config = NotificationChannelConfig::for($channel);
        if (! $config->isUsable()) {
            return back()->with('warning', 'Activați și configurați canalul înainte de test.');
        }
        $result = $manager->driver($channel)->send(new OutgoingMessage(
            channel: $channel,
            recipient: $data['test_recipient'],
            body: 'SEVALIA: mesaj de test al canalului '.MessageType::CHANNELS[$channel].'. Nu necesită răspuns.',
            subject: 'Test notificări SEVALIA',
            providerTemplate: $config->setting('test_template'),
            reference: 'test',
        ));
        Audit::log('notifications.channel_tested', null, ['canal' => $channel, 'rezultat' => $result->ok ? ($result->simulated ? 'simulat' : 'trimis') : 'eroare']);

        return back()->with($result->ok ? 'status' : 'warning', $result->ok
            ? ($result->simulated ? 'Test simulat: în acest mediu trimiterea reală este oprită.' : 'Mesajul de test a fost acceptat de furnizor.')
            : 'Testul a eșuat: '.$result->error);
    }
}
