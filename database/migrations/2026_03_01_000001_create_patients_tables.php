<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('last_name', 80);
            $table->string('first_name', 80);
            $table->string('search_name', 170)->index()->comment('Nume normalizat fără diacritice, pentru căutare');
            $table->date('birth_date')->nullable()->index();
            $table->char('sex', 1)->nullable()->comment('F | M');
            $table->text('cnp')->nullable()->comment('Criptat');
            $table->char('cnp_index', 64)->nullable()->unique()->comment('Index orb HMAC al CNP');
            $table->string('citizenship', 60)->default('română');
            $table->string('foreign_id_type', 40)->nullable()->comment('pașaport, carte de identitate UE etc.');
            $table->text('foreign_id_number')->nullable()->comment('Criptat');
            $table->char('foreign_id_index', 64)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('email', 190)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('county', 80)->nullable();
            $table->boolean('allow_phone')->default(true);
            $table->boolean('allow_sms')->default(false);
            $table->boolean('allow_email')->default(false);
            $table->boolean('allow_whatsapp')->default(false);
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->string('marketing_consent_source', 120)->nullable();
            $table->text('admin_notes')->nullable()->comment('Observații administrative, fără conținut medical');
            $table->foreignId('merged_into_id')->nullable()->constrained('patients');
            $table->timestamp('merged_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('patient_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('relationship', 40)->comment('parinte | tutore | curator | imputernicit | altul');
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('document_type', 80)->nullable()->comment('Actul care dovedește calitatea');
            $table->text('document_number')->nullable()->comment('Criptat');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('care_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->string('source', 30)->default('manual')->comment('manual | plan | trimitere');
            $table->string('reason', 255)->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['staff_member_id', 'patient_id']);
        });

        Schema::create('break_glass_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('patient_id')->constrained();
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'patient_id', 'expires_at']);
        });

        Schema::create('patient_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_patient_id')->constrained('patients');
            $table->foreignId('target_patient_id')->constrained('patients');
            $table->foreignId('user_id')->constrained();
            $table->string('reason', 500);
            $table->json('summary')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merges');
        Schema::dropIfExists('break_glass_accesses');
        Schema::dropIfExists('care_relations');
        Schema::dropIfExists('patient_representatives');
        Schema::dropIfExists('patients');
    }
};
