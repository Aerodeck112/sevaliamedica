<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255)->unique()->comment('Relativ la directorul public media/');
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64)->index();
            $table->string('alt', 500)->nullable();
            $table->string('title', 255)->nullable();
            $table->json('variants')->nullable()->comment('{"webp":{"480":"cale",...}}');
            $table->string('source', 20)->default('upload')->comment('import | upload');
            $table->string('source_url', 500)->nullable()->comment('Calea originală pe siteul Odoo');
            $table->boolean('is_public')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('source_url');
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255)->unique();
            $table->string('kind', 20)->default('page')->comment('page | article | global');
            $table->string('title', 255);
            $table->string('status', 20)->default('draft')->comment('draft | published | archived');
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0)->comment('Versiune optimistă pentru editare simultană');
            $table->foreignId('editing_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('editing_until')->nullable();
            $table->char('import_hash', 64)->nullable()->comment('Hash-ul conținutului importat; diferit de cel curent = editat din CMS');
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('published_at')->nullable()->comment('Pentru articole: data afișată');
            $table->string('author_name', 160)->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });

        Schema::create('cms_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 255);
            $table->json('seo')->nullable();
            $table->json('settings')->nullable();
            $table->longText('blocks');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unique(['page_id', 'number']);
        });

        Schema::create('cms_section_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('snippet', 80)->nullable();
            $table->longText('block')->comment('Blocul JSON complet, cu textele exemplului');
            $table->foreignId('source_page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cms_menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('menu', 30)->default('header');
            $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->cascadeOnDelete();
            $table->string('label', 160);
            $table->string('url', 500)->nullable();
            $table->string('kind', 20)->default('link')->comment('link | dropdown | mega');
            $table->longText('mega_block')->nullable()->comment('Bloc JSON cu conținutul meniului extins');
            $table->boolean('new_tab')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['menu', 'parent_id', 'sort']);
        });

        Schema::create('cms_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source_path', 255)->unique();
            $table->string('target', 500);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('form', 40);
            $table->text('payload')->comment('Criptat (Laravel encrypter)');
            $table->string('status', 20)->default('noua');
            $table->string('reference', 40)->nullable()->unique();
            $table->char('ip_hash', 64)->nullable();
            $table->string('source_url', 255)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['form', 'status']);
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->foreign('photo_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropForeign(['photo_media_id']);
        });
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('cms_redirects');
        Schema::dropIfExists('cms_menu_items');
        Schema::dropIfExists('cms_section_templates');
        Schema::dropIfExists('cms_page_versions');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('media');
    }
};
