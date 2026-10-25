<?php

namespace App\Modules\Medical;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Medical\Dashboard\DraftRecordsWidget;
use App\Modules\Medical\Models\ClinicalAlert;
use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Medical\Support\AlertAnnotations;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientMergeRegistry;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;
use App\Modules\Scheduling\Support\AppointmentAnnotations;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'medical';
    }

    public function label(): string
    {
        return 'Dosar medical';
    }

    public function permissions(): array
    {
        return [
            'medical.view' => 'Consultă fișele, documentele, consimțămintele și avertizările pacienților în îngrijire',
            'medical.write' => 'Redactează fișe (draft), încarcă documente, introduce avertizări',
            'medical.finalize' => 'Finalizează propriile fișe (cu reconfirmarea identității)',
            'medical.amend' => 'Completează / rectifică fișe finalizate (versiune nouă, cu motiv)',
            'medical.consents' => 'Înregistrează și retrage consimțămintele pacienților',
            'medical.templates' => 'Configurează șabloanele de fișă pe specialități',
            'medical.consent_templates' => 'Administrează modelele de consimțământ (text furnizat de clinică)',
            'medical.documents.retract' => 'Retrage documente încărcate greșit (cu motiv, fără ștergere)',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::MEDIC => ['medical.view', 'medical.write', 'medical.finalize', 'medical.amend', 'medical.consents', 'medical.templates', 'medical.documents.retract'],
            Roles::TERAPEUT => ['medical.view', 'medical.consents'],
            Roles::ASISTENT => ['medical.view', 'medical.consents'],
            Roles::MANAGER => ['medical.templates', 'medical.consent_templates'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Fișe de finalizat', 'panou.medical.drafts', 'medical.write', 'Medical', 'file', 10),
            new NavItem('Șabloane fișe', 'panou.medical.templates.index', 'medical.templates', 'Medical', 'clipboard', 40),
            new NavItem('Modele consimțământ', 'panou.medical.consent-templates.index', 'medical.consent_templates', 'Medical', 'shield', 41),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [new DraftRecordsWidget];
    }

    public function boot(): void
    {
        parent::boot();

        $tabs = $this->app->make(PatientTabRegistry::class);
        $tabs->add(new PatientTab('fise', 'Fișe medicale', 'medical.view', 30,
            fn (Patient $p, User $u) => view('medical::tabs.records', [
                'patient' => $p,
                'user' => $u,
                'records' => MedicalRecord::where('patient_id', $p->id)->originals()->where('status', '!=', MedicalRecord::ABANDONED)
                    ->with(['staffMember', 'amendments', 'specialty'])->orderByDesc('created_at')->get(),
                'alerts' => ClinicalAlert::where('patient_id', $p->id)->with('staffMember')->orderByRaw('ended_at is not null')->latest()->get(),
                'templates' => MedicalTemplate::usable()->with('specialty')->orderBy('name')->get(),
            ]),
            requiresCare: true,
            badge: fn (Patient $p) => ClinicalAlert::where('patient_id', $p->id)->active()->exists() ? '!' : null));
        $tabs->add(new PatientTab('documente', 'Documente', 'medical.view', 31,
            fn (Patient $p, User $u) => view('medical::tabs.documents', [
                'patient' => $p,
                'user' => $u,
                'documents' => MedicalDocument::where('patient_id', $p->id)->with('uploader')->latest()->get(),
            ]),
            requiresCare: true));
        $tabs->add(new PatientTab('consimtaminte', 'Consimțăminte', 'medical.view', 32,
            fn (Patient $p, User $u) => view('medical::tabs.consents', [
                'patient' => $p,
                'user' => $u,
                'consents' => PatientConsent::where('patient_id', $p->id)->with(['templateVersion.template', 'collector', 'document'])->latest('given_at')->get(),
                'templates' => ConsentTemplate::where('is_active', true)->with('latestVersion')->orderBy('name')->get()->filter->isReady(),
                'documents' => MedicalDocument::where('patient_id', $p->id)->active()->where('category', 'consimtamant')->latest()->get(),
            ]),
            requiresCare: true));

        $merge = $this->app->make(PatientMergeRegistry::class);
        $merge->add(PatientMergeRegistry::table('Fișe medicale', 'medical_records'));
        $merge->add(PatientMergeRegistry::table('Documente medicale', 'medical_documents'));
        $merge->add(PatientMergeRegistry::table('Consimțăminte', 'patient_consents'));
        $merge->add(PatientMergeRegistry::table('Avertizări clinice', 'clinical_alerts'));

        $this->app->make(AppointmentAnnotations::class)->add(new AlertAnnotations);
    }
}
