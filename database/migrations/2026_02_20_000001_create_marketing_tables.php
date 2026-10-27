<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auditul SEO automat: o rulare verifică toate paginile publicate, pe loturi, din cron.
        Schema::create('seo_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20)->comment('cron | manual');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('in_curs')->comment('in_curs | finalizat | esuat');
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_done')->default(0);
            $table->unsignedTinyInteger('score')->nullable()->comment('media scorurilor paginilor, 0–100');
            $table->unsignedInteger('errors_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->json('site_checks')->nullable()->comment('verificări la nivel de site + cuvinte cheie');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });

        Schema::create('seo_audit_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
            $table->string('path', 300);
            $table->string('title', 300)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('issues')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['seo_audit_run_id', 'checked_at']);
        });

        // Cuvintele cheie urmărite și pagina care trebuie să răspundă la fiecare.
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('phrase', 150)->unique();
            $table->string('target_path', 300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Cuvintele cheie cerute de clinică la 2026-10-26, cu pagina care le tratează (modificabile din panou).
        $targets = [
            '/kinetoterapie' => ['kinetoterapie Târgu Mureș', 'kinetoterapie Tg. Mureș', 'kinetoterapie Mureș', 'kinetoterapie copii', 'kinetoterapie copii Târgu Mureș'],
            '/masaj' => ['masaj Târgu Mureș', 'masaj terapeutic Tg. Mureș', 'masaj Mureș', 'masaj terapeutic Mureș'],
            '/reabilitare-medicala-mures' => ['reabilitare medicală Târgu Mureș', 'reabilitare Mureș', 'reabilitare Tg. Mureș'],
            '/recuperare-medicala-mures' => ['recuperare medicală Târgu Mureș', 'recuperare Mureș', 'recuperare Tg. Mureș'],
            '/kinetoterapie-atm-articulatia-temporo-mandibulara' => ['kinetoterapie ATM', 'kinetoterapie temporo-mandibulară', 'kinetoterapie mandibulară Mureș', 'kinetoterapie ATM Târgu Mureș'],
            '/emsella-tratament-pentru-incontinenta-urinara' => ['terapia planșeului pelvin Mureș', 'terapia planșeului pelvin Tg. Mureș', 'terapie incontinență urinară'],
        ];
        $now = now();
        foreach ($targets as $path => $phrases) {
            foreach ($phrases as $phrase) {
                DB::table('seo_keywords')->insertOrIgnore(['phrase' => $phrase, 'target_path' => $path, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // IndexNow: adresele de anunțat motoarelor de căutare care acceptă protocolul (Bing, Yandex, Seznam…).
        Schema::create('index_queue', function (Blueprint $table) {
            $table->id();
            $table->string('path', 300)->unique();
            $table->timestamp('queued_at');
        });

        Schema::create('index_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 40);
            $table->unsignedInteger('urls_count');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('result', 300)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_submissions');
        Schema::dropIfExists('index_queue');
        Schema::dropIfExists('seo_keywords');
        Schema::dropIfExists('seo_audit_pages');
        Schema::dropIfExists('seo_audit_runs');
    }
};
