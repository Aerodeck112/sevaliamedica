<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('county', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('map_query', 255)->nullable()->comment('Textul folosit pentru harta încorporată');
            $table->json('opening_hours')->nullable()->comment('{"1":[["07:00","22:00"]],...} 1=luni');
            $table->string('cas_work_point_code', 40)->nullable()->comment('Codul punctului de lucru, dacă e contractat CAS');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('cas_code', 20)->nullable()->comment('Cod din nomenclatorul CNAS de specialități, completat din sursa oficială');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('medic')->comment('medic | terapeut | asistent | altul');
            $table->string('honorific', 20)->nullable()->comment('ex. dr.');
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('display_name', 160)->comment('Numele afișat, exact ca pe site');
            $table->string('professional_title', 190)->nullable()->comment('ex. medic specialist reumatolog');
            $table->string('stencil_no', 10)->nullable()->comment('Parafa (pentru raportări CAS)');
            $table->string('calendar_color', 7)->default('#00C4C4');
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_site')->default(false);
            $table->unsignedSmallInteger('site_sort')->default(0);
            $table->unsignedBigInteger('photo_media_id')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::create('specialty_staff_member', function (Blueprint $table) {
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained()->cascadeOnDelete();
            $table->primary(['staff_member_id', 'specialty_id']);
        });

        Schema::create('location_staff_member', function (Blueprint $table) {
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->primary(['staff_member_id', 'location_id']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained();
            $table->string('name', 120);
            $table->string('kind', 60)->nullable()->comment('ex. cabinet, sala_kinetoterapie, hidroterapie');
            $table->unsignedSmallInteger('capacity')->default(1)->comment('Programări simultane permise');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('room_id')->nullable()->comment('Dacă aparatul e fix într-o sală')->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('kind', 60)->comment('Tipul cerut de servicii, ex. alterg, emsella, shockwave');
            $table->string('serial', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('kind');
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique()->comment('Cod intern');
            $table->string('name', 190);
            $table->string('kind', 30)->comment('consultatie | procedura | investigatie | sedinta');
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('staff_kind', 20)->nullable()->comment('Tipul de personal care o efectuează');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_minutes')->default(0)->comment('Timp de pregătire după');
            $table->decimal('price', 10, 2)->nullable()->comment('Tarif privat; null = neconfirmat');
            $table->string('price_note', 190)->nullable();
            $table->boolean('cas_reportable')->default(false);
            $table->string('cas_code', 40)->nullable()->comment('Cod din nomenclatorul CNAS, validat de modulul CAS');
            $table->boolean('online_booking')->default(false);
            $table->boolean('online_auto_confirm')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 20)->comment('room | equipment');
            $table->string('resource_kind', 60);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unique(['service_id', 'resource_type', 'resource_kind'], 'service_req_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requirements');
        Schema::dropIfExists('services');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('location_staff_member');
        Schema::dropIfExists('specialty_staff_member');
        Schema::dropIfExists('staff_members');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('locations');
    }
};
