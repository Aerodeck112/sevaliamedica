<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 20)->comment('database | documents');
            $table->string('status', 20)->default('running')->comment('running | completed | verified | failed');
            $table->string('trigger', 20)->default('manual')->comment('manual | schedule | update');
            $table->string('file', 255)->comment('Cale relativă la shared/backups');
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('key_fingerprint', 16)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->json('summary')->nullable()->comment('Instrucțiuni, rânduri pe tabel sau fișiere; fără date personale');
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('update_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // 1 cât timp actualizarea e activă; NULL după încheiere. Indexul unic împiedică două actualizări simultane.
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->string('from_version', 40)->nullable();
            $table->string('to_version', 40);
            $table->string('package_type', 10);
            $table->string('package_sha256', 64);
            $table->string('key_id', 16);
            $table->json('manifest_summary');
            $table->text('changelog')->nullable();
            $table->string('status', 20)->comment('prepared | running | failed | completed | rolled_back | cancelled');
            $table->string('current_step', 40)->nullable();
            $table->json('steps');
            $table->json('preflight')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_runs');
        Schema::dropIfExists('backups');
    }
};
