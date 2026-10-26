<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Șabloane de fișă, pe specialitate. Structura editată este „draft_schema”; fișele folosesc doar versiuni publicate.
        Schema::create('medical_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialty_id')->nullable()->comment('null = utilizabil pentru orice specialitate')->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->json('draft_schema')->nullable();
            $table->boolean('is_example')->default(false)->comment('Exemplu generic de adaptat de medici');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('medical_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('schema');
            $table->string('notes', 500)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at');
            $table->unique(['medical_template_id', 'version'], 'med_tpl_version_unique');
        });

        // Fișe / documente medicale redactate în aplicație. Conținutul este criptat (JSON cu valorile câmpurilor).
        // După finalizare conținutul este imuabil; corecturile sunt înregistrări noi cu amends_id → fișa corectată.
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->foreignId('staff_member_id')->comment('Autorul clinic')->constrained();
            $table->foreignId('specialty_id')->nullable()->constrained();
            $table->foreignId('template_version_id')->nullable()->constrained('medical_template_versions');
            $table->unsignedBigInteger('rehab_plan_id')->nullable()->index()->comment('Reevaluare legată de un plan de recuperare');
            $table->string('kind', 20)->default('consultatie')->comment('consultatie | reevaluare | nota | completare | rectificare');
            $table->string('title', 190);
            $table->string('status', 20)->default('draft')->comment('draft | finalizat');
            $table->longText('content')->nullable()->comment('Criptat');
            $table->foreignId('amends_id')->nullable()->comment('Fișa finalizată pe care o completează / rectifică')->constrained('medical_records');
            $table->text('amendment_reason')->nullable()->comment('Criptat');
            $table->char('content_hash', 64)->nullable()->comment('SHA-256 al conținutului la finalizare');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1)->comment('Blocare optimistă a draftului');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['patient_id', 'created_at']);
            $table->index(['staff_member_id', 'status']);
        });

        // Documente încărcate (PDF / imagini), criptate pe discul privat.
        Schema::create('medical_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('medical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 190);
            $table->string('category', 40)->default('altul')->comment('rezultat | scrisoare | bilet | consimtamant | imagistica | altul');
            $table->string('original_name', 190);
            $table->string('mime', 80);
            $table->unsignedInteger('size')->comment('Octeți, conținut necriptat');
            $table->char('sha256', 64)->comment('Suma conținutului necriptat');
            $table->string('storage_path', 190)->unique();
            $table->string('status', 20)->default('activ')->comment('activ | retras');
            $table->string('retract_reason', 500)->nullable();
            $table->foreignId('retracted_by')->nullable()->constrained('users');
            $table->timestamp('retracted_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['patient_id', 'created_at']);
        });

        Schema::create('consent_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('scope', 190)->nullable()->comment('Procedura / situația la care se aplică');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('consent_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consent_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->longText('body')->comment('Textul furnizat de clinică');
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at');
            $table->unique(['consent_template_id', 'version'], 'consent_tpl_version_unique');
        });

        Schema::create('patient_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('consent_template_version_id')->constrained();
            $table->string('method', 30)->comment('hartie_scanat | verbal_consemnat');
            $table->string('given_by', 30)->default('pacient')->comment('pacient | reprezentant');
            $table->string('representative_name', 160)->nullable();
            $table->timestamp('given_at');
            $table->foreignId('collected_by')->constrained('users');
            $table->foreignId('medical_document_id')->nullable()->comment('Scanarea formularului semnat')->constrained()->nullOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users');
            $table->string('withdraw_reason', 500)->nullable();
            $table->timestamps();
        });

        // Avertizări clinice introduse de clinician (alergii, contraindicații etc.). Textul este criptat.
        Schema::create('clinical_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->string('severity', 20)->default('atentie')->comment('info | atentie | critic');
            $table->text('text')->comment('Criptat');
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->string('end_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_alerts');
        Schema::dropIfExists('patient_consents');
        Schema::dropIfExists('consent_template_versions');
        Schema::dropIfExists('consent_templates');
        Schema::dropIfExists('medical_documents');
        Schema::dropIfExists('medical_records');
        Schema::dropIfExists('medical_template_versions');
        Schema::dropIfExists('medical_templates');
    }
};
