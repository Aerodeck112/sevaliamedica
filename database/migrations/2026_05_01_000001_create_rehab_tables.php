<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pachete de ședințe: doar structura configurată de clinică (fără prețuri în acest modul).
        Schema::create('rehab_package_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedSmallInteger('validity_days')->nullable()->comment('null = fără termen');
            $table->json('service_ids')->comment('Serviciile din catalog care pot consuma pachetul');
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('patient_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('rehab_package_definition_id')->constrained();
            $table->unsignedSmallInteger('sessions_total');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('activ')->comment('activ | anulat');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('rehab_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('medical_record_id')->nullable()->comment('Consultația pe care se bazează planul')->constrained();
            $table->foreignId('physician_staff_id')->comment('Medicul care a întocmit planul')->constrained('staff_members');
            $table->foreignId('location_id')->constrained();
            $table->string('status', 20)->default('draft')->comment('draft | aprobat | in_desfasurare | suspendat | incheiat');
            $table->string('payer_type', 20)->default('privat')->comment('privat | cas | pachet | asigurator');
            $table->foreignId('patient_package_id')->nullable()->constrained();
            $table->text('objectives')->nullable()->comment('Criptat');
            $table->text('notes')->nullable()->comment('Criptat');
            $table->date('planned_start')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspend_reason', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->text('closing_conclusion')->nullable()->comment('Criptat');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['patient_id', 'status']);
        });

        Schema::create('rehab_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rehab_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->comment('Procedura din catalog (durată, sală/aparat)')->constrained();
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedTinyInteger('sessions_per_week')->nullable();
            $table->foreignId('preferred_therapist_id')->nullable()->constrained('staff_members');
            $table->string('payer_type', 20)->nullable()->comment('Suprascrie sursa de plată a planului');
            $table->string('instructions', 500)->nullable()->comment('Instrucțiuni organizatorice, fără conținut clinic');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('rehab_item_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rehab_plan_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_series_id')->unique()->constrained('appointment_series');
            $table->timestamp('created_at')->nullable();
        });

        // Ședința efectuată: marcată EXPLICIT de terapeut; creează serviciul efectuat (service_records).
        Schema::create('rehab_session_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rehab_plan_item_id')->constrained();
            $table->foreignId('appointment_id')->unique()->constrained();
            $table->foreignId('service_record_id')->constrained();
            $table->foreignId('therapist_staff_id')->constrained('staff_members');
            $table->foreignId('patient_package_id')->nullable()->constrained();
            $table->dateTime('performed_at');
            $table->text('observations')->nullable()->comment('Criptat');
            $table->text('evolution')->nullable()->comment('Criptat');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rehab_session_records');
        Schema::dropIfExists('rehab_item_series');
        Schema::dropIfExists('rehab_plan_items');
        Schema::dropIfExists('rehab_plans');
        Schema::dropIfExists('patient_packages');
        Schema::dropIfExists('rehab_package_definitions');
    }
};
