<?php

namespace App\Modules\Cms\Services\Import;

/**
 * Sursele locale ale conținutului siteului original (care va fi șters): importul nu accesează rețeaua.
 *
 *  1. Arhiva completă, randată în browser:  database/content/odoo-archive/
 *       index.json  — resursele: { "<URL>": {"file": "files/…", "sha256": "…", "pages": ["/…"], "type": "image/…"}, … }
 *                     (acceptat și sub cheia "resources"); opțional "pages": { "<URL pagină>": {"file": "rendered/….html"} }
 *       rendered/   — HTML-ul paginilor după JavaScript (numele fișierului: home.html, kinetoterapie.html,
 *                     blog__blog-sevalia-1__slug-3.html; sau maparea din index.json)
 *       files/      — fișierele resurselor
 *  2. Instantaneul inițial:  database/content/odoo-snapshot/  (pages/ + pages.json, images/ + index.json, files/ + index.json)
 *
 * La rezolvarea resurselor (imagini, documente) arhiva are prioritate. Pentru structura paginilor se folosește HTML-ul
 * servit de server (instantaneu), mai curat; HTML-ul randat din arhivă se folosește pentru paginile care lipsesc din
 * instantaneu, după curățarea modificărilor făcute de JavaScript.
 */
class SourceCatalog
{
    public const BASE = 'https://www.sevalia.ro';

    /** @var array<string, array{path:string, sha256:?string, source:string, type:?string}> */
    private array $assets = [];

    /** @var array<string, array{path:string, source:string, rendered:bool}> */
    private array $pages = [];

    public function __construct(private readonly ?string $snapshot = null, private readonly ?string $archive = null)
    {
        $this->loadArchive($this->archive ?? database_path('content/odoo-archive'));
        $this->loadSnapshot($this->snapshot ?? database_path('content/odoo-snapshot'));
    }

    public static function normalize(string $url): string
    {
        $url = html_entity_decode(trim($url, " \t\n\"'"));
        foreach (['https://www.sevalia.ro', 'http://www.sevalia.ro', 'https://sevalia.ro', 'http://sevalia.ro', '//www.sevalia.ro'] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                $url = substr($url, strlen($prefix)) ?: '/';
            }
        }

        return rawurldecode($url);
    }

    /** Căutare exactă, apoi fără parametri (?unique=…, ?access_token=…). */
    public function asset(string $url): ?array
    {
        $key = self::normalize($url);
        if (isset($this->assets[$key])) {
            return $this->assets[$key];
        }
        $bare = strtok($key, '?');
        foreach ($this->assets as $k => $asset) {
            if (strtok($k, '?') === $bare) {
                return $asset;
            }
        }

        return null;
    }

    /** @return array<string, array{path:string, sha256:?string, source:string, type:?string}> */
    public function assets(): array
    {
        return $this->assets;
    }

    /** @return array<string, array{path:string, source:string, rendered:bool}> cale publică => fișier HTML */
    public function pages(): array
    {
        ksort($this->pages);

        return $this->pages;
    }

    public function hasArchive(): bool
    {
        foreach ($this->assets as $a) {
            if ($a['source'] === 'arhiva') {
                return true;
            }
        }

        return (bool) array_filter($this->pages, fn ($p) => $p['source'] === 'arhiva');
    }

    private function loadArchive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $index = is_file($dir.'/index.json') ? (json_decode((string) file_get_contents($dir.'/index.json'), true) ?: []) : [];
        $resources = $index['resources'] ?? array_filter($index, fn ($v, $k) => is_array($v) && isset($v['file']) && $k !== 'pages', ARRAY_FILTER_USE_BOTH);
        foreach ($resources as $url => $info) {
            $file = $this->resolve($dir, (string) ($info['file'] ?? ''));
            if ($file) {
                $this->assets[self::normalize((string) $url)] ??= ['path' => $file, 'sha256' => $info['sha256'] ?? null, 'source' => 'arhiva', 'type' => $info['type'] ?? null];
            }
        }
        foreach ((array) ($index['pages'] ?? []) as $url => $info) {
            $file = $this->resolve($dir, is_array($info) ? (string) ($info['file'] ?? '') : (string) $info);
            if ($file) {
                $this->pages[self::pathOf((string) $url)] = ['path' => $file, 'source' => 'arhiva', 'rendered' => true];
            }
        }
        // Paginile care pe siteul vechi nu au răspuns 200 (ex. „Page Not Found” pentru /neurologie) sunt păstrate în
        // arhivă ca dovadă, dar nu se importă ca pagini.
        $notOk = [];
        foreach ((array) ($index['pages'] ?? []) as $info) {
            if (is_array($info) && isset($info['rendered'], $info['status']) && (int) $info['status'] !== 200) {
                $notOk[basename((string) $info['rendered'])] = true;
            }
        }
        foreach ((array) ($index['pages'] ?? []) as $info) {
            if (is_array($info) && isset($info['rendered'], $info['status']) && (int) $info['status'] === 200) {
                unset($notOk[basename((string) $info['rendered'])]);
            }
        }
        foreach (glob($dir.'/rendered/*.html') ?: [] as $file) {
            if (isset($notOk[basename($file)])) {
                continue;
            }
            $slug = basename($file, '.html');
            $path = $slug === 'home' || $slug === 'index' ? '/' : '/'.str_replace('__', '/', $slug);
            $this->pages[$path] ??= ['path' => $file, 'source' => 'arhiva', 'rendered' => true];
        }
    }

    private function loadSnapshot(string $dir): void
    {
        foreach (['images/index.json' => 'images', 'files/index.json' => 'files'] as $indexFile => $sub) {
            if (! is_file("$dir/$indexFile")) {
                continue;
            }
            foreach (json_decode((string) file_get_contents("$dir/$indexFile"), true) ?: [] as $url => $info) {
                if (empty($info['file']) || ! is_file("$dir/$sub/{$info['file']}")) {
                    continue;
                }
                $this->assets[self::normalize((string) $url)] ??= ['path' => "$dir/$sub/{$info['file']}", 'sha256' => $info['sha256'] ?? null, 'source' => 'instantaneu', 'type' => $info['type'] ?? null];
            }
        }
        if (is_file("$dir/pages.json")) {
            foreach (json_decode((string) file_get_contents("$dir/pages.json"), true) ?: [] as $url => $info) {
                if (($info['code'] ?? null) !== 200 || ! is_file("$dir/pages/{$info['file']}.html")) {
                    continue;
                }
                $path = self::pathOf((string) $url);
                // Instantaneul (HTML de server) are prioritate pentru structura paginilor.
                $this->pages[$path] = ['path' => "$dir/pages/{$info['file']}.html", 'source' => 'instantaneu', 'rendered' => false];
            }
        }
    }

    private static function pathOf(string $url): string
    {
        $path = self::normalize($url);
        $path = strtok($path, '?') ?: '/';

        return $path === '' ? '/' : '/'.trim($path, '/');
    }

    private function resolve(string $dir, string $file): ?string
    {
        if ($file === '' || str_contains($file, '..')) {
            return null;
        }
        foreach ([$dir.'/'.$file, $dir.'/files/'.$file, $dir.'/rendered/'.$file] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
