<?php

namespace Database\Seeders;

use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Support\MessageType;
use Illuminate\Database\Seeder;

/**
 * Șabloanele implicite (texte neutre, operaționale, fără conținut medical). Creează doar ce lipsește:
 * nu suprascrie niciodată textele editate de clinică.
 */
class NotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MessageType::defaults() as $type => $channels) {
            foreach ($channels as $channel => $text) {
                if (NotificationTemplate::where('type', $type)->where('channel', $channel)->exists()) {
                    continue;
                }
                NotificationTemplate::create([
                    'type' => $type,
                    'channel' => $channel,
                    'version' => 1,
                    'subject' => $text['subject'] ?? null,
                    'body' => $text['body'],
                    'is_active' => true,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
