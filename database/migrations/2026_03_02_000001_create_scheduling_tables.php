<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->unsignedTinyInteger('weekday')->comment('1 = luni ... 7 = duminică');
            $table->time('start_time');
            $table->time('end_time');
            $table->json('breaks')->nullable()->comment('[["12:00","12:30"], ...]');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['staff_member_id', 'weekday']);
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_member_id')->nullable()->comment('null = închiderea locației/clinicii')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->string('kind', 30)->comment('concediu | liber | ore_suplimentare | inchidere');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('appointment_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->foreignId('location_id')->constrained();
            $table->json('rule')->comment('{"weekdays":[1,3,5],"time":"09:00","count":10,"start_date":"..."}');
            $table->unsignedSmallInteger('planned_count');
            $table->string('status', 20)->default('activa')->comment('activa | inchisa');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->nullable()->constrained();
            $table->foreignId('service_id')->nullable()->constrained();
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->foreignId('series_id')->nullable()->constrained('appointment_series');
            $table->unsignedSmallInteger('series_index')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('blocked_until')->nullable()->comment('ends_at + pauza de după');
            $table->string('status', 20)->default('solicitare');
            $table->string('source', 20)->default('receptie')->comment('receptie | telefon | site | serie');
            $table->string('payer_type', 20)->nullable()->comment('privat | cas | pachet | asigurator');
            $table->string('notes', 1000)->nullable()->comment('Observații administrative');
            $table->text('request_contact')->nullable()->comment('Criptat: date de contact din formularul public');
            $table->string('request_reference', 20)->nullable()->unique();
            $table->foreignId('request_specialty_id')->nullable()->constrained('specialties');
            $table->dateTime('request_preferred_start')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reschedule_requested_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['starts_at', 'status']);
            $table->index(['staff_member_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
        });

        Schema::create('appointment_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 20)->comment('staff | room | equipment');
            $table->unsignedBigInteger('resource_id');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->comment('Inclusiv pauza de după');
            $table->timestamp('released_at')->nullable()->comment('Eliberată la anulare/neprezentare');
            $table->dateTime('held_until')->nullable()->comment('Ținere temporară pentru o solicitare online; expirată = liberă');
            $table->index(['resource_type', 'resource_id', 'starts_at', 'ends_at'], 'appt_res_lookup');
        });

        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('type', 30)->comment('creata | status | mutata | resurse | nota | reprogramare_ceruta | anulata_pacient');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at', 3)->nullable();
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('service_id')->nullable()->constrained();
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->json('preferences')->nullable()->comment('{"weekdays":[1,2],"from":"08:00","to":"12:00"}');
            $table->unsignedTinyInteger('priority')->default(2)->comment('1 = urgent, 2 = normal, 3 = flexibil');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('activ')->comment('activ | oferit | programat | expirat | anulat');
            $table->string('notes', 500)->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['status', 'priority']);
        });

        Schema::create('waitlist_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waitlist_entry_id')->constrained()->cascadeOnDelete();
            $table->dateTime('slot_start');
            $table->dateTime('slot_end');
            $table->foreignId('staff_member_id')->nullable()->constrained();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->foreignId('freed_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('outcome', 20)->default('in_asteptare')->comment('in_asteptare | acceptat | refuzat | expirat');
            $table->foreignId('offered_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('patient_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 20)->comment('gestionare | anulare | reprogramare');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('staff_member_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->dateTime('performed_at');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('payer_type', 20)->comment('privat | cas | pachet | asigurator');
            $table->string('insurer_name', 120)->nullable();
            $table->string('status', 20)->default('efectuat')->comment('efectuat | anulat');
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('corrects_id')->nullable()->comment('Înregistrarea pe care aceasta o înlocuiește')->constrained('service_records');
            $table->foreignId('corrected_by_id')->nullable()->constrained('service_records');
            $table->string('manual_reason', 500)->nullable()->comment('Motiv obligatoriu dacă nu provine dintr-o programare');
            $table->string('notes', 1000)->nullable()->comment('Observații administrative');
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'performed_at']);
            $table->index(['payer_type', 'status', 'performed_at']);
        });
    }

    public function down(): void
    {
        foreach (['service_records', 'patient_access_tokens', 'waitlist_offers', 'waitlist_entries', 'appointment_events', 'appointment_resources', 'appointments', 'appointment_series', 'schedule_exceptions', 'staff_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
