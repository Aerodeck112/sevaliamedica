<?php

namespace Database\Seeders;

use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Models\MedicalTemplateVersion;
use App\Modules\Medical\Support\TemplateSchema;
use Illuminate\Database\Seeder;

/**
 * La instalare: un singur șablon GENERIC „Consultație”, doar cu secțiuni de text liber, marcat ca exemplu de adaptat
 * de medici, și un model de consimțământ GOL („de completat de clinică”). Nu conține protocoale, scoruri sau
 * texte juridice/medicale inventate. Idempotent: nu modifică ce a fost editat ulterior.
 */
class MedicalInstallSeeder extends Seeder
{
    public function run(): void
    {
        $template = MedicalTemplate::firstOrCreate(['name' => 'Consultație (exemplu generic)'], [
            'description' => 'Exemplu cu secțiuni de text liber. Adaptați-l conform practicii medicilor clinicii.',
            'is_example' => true,
            'draft_schema' => TemplateSchema::normalize(['sections' => [
                ['title' => 'Motivul prezentării', 'fields' => [['label' => 'Motivul prezentării', 'type' => 'textarea']]],
                ['title' => 'Anamneză', 'fields' => [['label' => 'Anamneză', 'type' => 'textarea']]],
                ['title' => 'Examen', 'fields' => [['label' => 'Examen', 'type' => 'textarea']]],
                ['title' => 'Concluzii', 'fields' => [['label' => 'Concluzii', 'type' => 'textarea']]],
                ['title' => 'Recomandări', 'fields' => [['label' => 'Recomandări', 'type' => 'textarea']]],
            ]]),
        ]);
        if ($template->wasRecentlyCreated) {
            MedicalTemplateVersion::create([
                'medical_template_id' => $template->id,
                'version' => 1,
                'schema' => $template->draft_schema,
                'notes' => 'Exemplu generic livrat la instalare',
                'published_at' => now(),
            ]);
        }

        // Model fără text: rămâne neutilizabil până când clinica introduce textul validat.
        ConsentTemplate::firstOrCreate(['name' => 'Consimțământ informat (de completat de clinică)'], [
            'scope' => 'Textul se introduce de clinică, după validarea juridică și medicală.',
        ]);
    }
}
