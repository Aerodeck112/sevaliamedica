<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->string('channel', 20)->primary()->comment('email | sms | whatsapp');
            $table->string('driver', 30)->default('neconfigurat')->comment('neconfigurat | log | smtp | http_generic | whatsapp_cloud');
            $table->boolean('enabled')->default(false);
            $table->text('config')->nullable()->comment('Criptat: setările driverului, inclusiv secretele');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('channel', 20);
            $table->unsignedInteger('version');
            $table->string('subject', 190)->nullable()->comment('Doar pentru e-mail');
            $table->text('body');
            $table->string('provider_template', 120)->nullable()->comment('WhatsApp: numele șablonului aprobat în Meta');
            $table->json('provider_params')->nullable()->comment('WhatsApp: variabilele, în ordinea parametrilor șablonului aprobat');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->unique(['type', 'channel', 'version'], 'notif_tpl_version_unique');
            $table->index(['type', 'channel', 'is_active'], 'notif_tpl_active');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('idempotency_key', 64)->unique()->comment('SHA-256 peste tip, entitate, canal, destinatar, variantă');
            $table->string('type', 40);
            $table->string('channel', 20);
            $table->string('category', 20)->default('operational')->comment('operational | marketing');
            $table->foreignId('patient_id')->nullable()->constrained();
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->string('subject_type', 60)->nullable()->comment('Entitatea care a generat mesajul, dacă nu e o programare');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates');
            $table->text('recipient')->comment('Criptat: telefon sau e-mail');
            $table->string('recipient_masked', 60);
            $table->text('payload')->nullable()->comment('Criptat: subiect, text, parametri WhatsApp');
            $table->string('variant', 60)->nullable()->comment('Versiunea programării / ora, pentru verificarea actualității');
            $table->string('status', 20)->default('in_asteptare')
                ->comment('in_asteptare | in_trimitere | trimis | simulat | esuat | esuat_definitiv | anulat | necunoscut');
            $table->string('skip_reason', 190)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->string('last_error', 300)->nullable()->comment('Fără date personale');
            $table->string('provider_message_id', 120)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['status', 'next_attempt_at'], 'notif_deliv_due');
            $table->index(['appointment_id', 'type'], 'notif_deliv_appt');
            $table->index(['patient_id', 'created_at'], 'notif_deliv_patient');
        });

        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('appointment_id')->unique('feedback_one_per_appt')->constrained();
            $table->foreignId('patient_id')->nullable()->constrained();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('rating')->nullable()->comment('1–5');
            $table->text('comment')->nullable()->comment('Criptat; pacientul e rugat să nu includă date medicale');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_requests');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_channels');
    }
};
