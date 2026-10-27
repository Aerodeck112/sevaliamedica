<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabletele de la recepție. Se asociază o singură dată cu un cod generat în panou; tokenul de dispozitiv
        // se păstrează doar ca hash și poate fi revocat oricând.
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('mode', 20)->default('ambele')->comment('consimtamant | recenzie | ambele');
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->char('pairing_code_hash', 64)->nullable();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // O cerere trimisă pe tabletă: semnarea unui consimțământ sau o recenzie după serviciu.
        // Codul de 6 cifre se păstrează doar ca HMAC; expiră și se blochează după încercări greșite.
        Schema::create('kiosk_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->comment('consimtamant | recenzie');
            $table->foreignId('kiosk_device_id')->nullable()->comment('Tableta țintă; null = orice tabletă potrivită')->constrained()->nullOnDelete();
            $table->char('code_hash', 64)->index();
            $table->string('status', 20)->default('in_asteptare')->comment('in_asteptare | deschis | finalizat | refuzat | expirat | anulat | blocat');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('consent_template_version_id')->nullable()->constrained();
            $table->foreignId('service_record_id')->nullable()->constrained();
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->foreignId('service_id')->nullable()->constrained();
            $table->boolean('auto_open')->default(false)->comment('Recenzie: se deschide singură pe tableta țintă, fără cod');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('opened_on_device_id')->nullable()->constrained('kiosk_devices')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('patient_consent_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('patient_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('kiosk_session_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('service_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating')->comment('1–5 stele');
            $table->text('comment')->nullable()->comment('Criptat; poate conține date despre sănătate');
            $table->boolean('publish_consent')->default(false)->comment('Pacientul acceptă publicarea pe site, fără nume complet');
            $table->boolean('published')->default(false);
            $table->foreignId('moderated_by')->nullable()->constrained('users');
            $table->timestamp('moderated_at')->nullable();
            $table->string('source', 20)->default('tableta');
            $table->timestamps();
            $table->index(['staff_member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_reviews');
        Schema::dropIfExists('kiosk_sessions');
        Schema::dropIfExists('kiosk_devices');
    }
};
