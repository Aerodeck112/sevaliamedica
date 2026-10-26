<?php

namespace App\Modules\Medical\Services;

use App\Modules\Medical\Models\MedicalDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Stocarea documentelor medicale:
 *  - verifică extensia, tipul MIME real (finfo) și dimensiunea;
 *  - reencodează imaginile (elimină metadatele EXIF/GPS și orice conținut atașat);
 *  - respinge PDF-urile cu JavaScript, acțiuni automate, lansare de programe sau fișiere încorporate;
 *  - criptează conținutul (XSalsa20-Poly1305, sodium secretbox) și îl scrie pe discul privat, la o cale aleatorie.
 */
class DocumentStorage
{
    /** Marcaje PDF care pot executa cod sau conținut activ. */
    private const PDF_ACTIVE = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/RichMedia', '/OpenAction', '/AA', '/XFA', '/SubmitForm', '/ImportData'];

    private const MAGIC = 'SVMD1';

    /**
     * @return array{mime: string, size: int, sha256: string, storage_path: string, original_name: string}
     */
    public function store(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Fișierul nu a fost încărcat corect. Reîncercați.']);
        }
        $maxBytes = (int) config('medical.max_upload_kb') * 1024;
        if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Fișierul depășește dimensiunea maximă de '.round($maxBytes / 1048576, 1).' MB.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = (array) config('medical.allowed');
        if (! isset($allowed[$extension])) {
            throw ValidationException::withMessages(['file' => 'Sunt acceptate doar fișiere PDF, JPG, PNG sau WEBP.']);
        }
        $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        if ($realMime !== $allowed[$extension]) {
            throw ValidationException::withMessages(['file' => 'Conținutul fișierului nu corespunde extensiei (.'.$extension.'). Fișierul a fost respins.']);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        if ($realMime === 'application/pdf') {
            $this->assertSafePdf($bytes);
        } else {
            [$bytes, $realMime] = $this->reencodeImage($bytes, $realMime);
        }

        $relative = Str::lower(Str::random(2)).'/'.Str::lower(Str::random(2)).'/'.Str::random(40).'.bin';
        $this->write($relative, $bytes);

        return [
            'mime' => $realMime,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_path' => $relative,
            'original_name' => Str::limit(preg_replace('/[^\pL\pN ._()-]/u', '_', $file->getClientOriginalName()) ?: 'document', 180, ''),
        ];
    }

    /** Conținutul decriptat; verifică suma de control. */
    public function read(MedicalDocument $document): string
    {
        $path = $this->absolute($document->storage_path);
        $raw = @file_get_contents($path);
        if ($raw === false || ! str_starts_with($raw, self::MAGIC)) {
            throw new RuntimeException('Fișierul documentului lipsește sau este deteriorat.');
        }
        $nonce = substr($raw, strlen(self::MAGIC), SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false || ! hash_equals($document->sha256, hash('sha256', $plain))) {
            throw new RuntimeException('Documentul nu a putut fi decriptat sau suma de control nu corespunde.');
        }

        return $plain;
    }

    public function absolute(string $relative): string
    {
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Cale invalidă.');
        }

        return rtrim((string) config('medical.documents_path'), '/').'/'.$relative;
    }

    private function write(string $relative, string $bytes): void
    {
        $path = $this->absolute($relative);
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Directorul documentelor nu poate fi creat.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = self::MAGIC.$nonce.sodium_crypto_secretbox($bytes, $nonce, $this->key());
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Documentul nu a putut fi salvat.');
        }
        @chmod($path, 0600);
        sodium_memzero($bytes);
    }

    private function key(): string
    {
        $configured = (string) config('medical.documents_key');
        if ($configured !== '') {
            $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7)) : $configured;
            if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('SEVALIA_DOCUMENTS_KEY trebuie să aibă 32 de octeți (base64:...).');
            }

            return $key;
        }
        // Soluție de rezervă: cheie derivată din APP_KEY (documentat; recomandată o cheie separată).
        $appKey = (string) config('app.key');
        $appKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;

        return hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'sevalia-medical-documents');
    }

    private function assertSafePdf(string $bytes): void
    {
        if (! str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'Fișierul nu este un PDF valid.']);
        }
        // Numele PDF pot fi codificate hexazecimal (#4A = J); le decodăm înainte de căutare.
        $normalized = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $bytes);
        foreach (self::PDF_ACTIVE as $marker) {
            if (preg_match('#'.preg_quote($marker, '#').'(?![A-Za-z])#', $normalized)) {
                throw ValidationException::withMessages(['file' => 'PDF-ul conține elemente active ('.ltrim($marker, '/').'), care nu sunt acceptate. Salvați documentul ca PDF simplu (ex. „Tipărește → PDF”) și reîncercați.']);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function reencodeImage(string $bytes, string $mime): array
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) {
            throw ValidationException::withMessages(['file' => 'Imaginea nu poate fi citită sau este prea mare.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['file' => 'Imaginea nu poate fi citită.']);
        }
        $max = (int) config('medical.image_max_side', 4000);
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) > $max) {
            $ratio = $max / max($w, $h);
            $resized = imagescale($image, (int) round($w * $ratio), (int) round($h * $ratio));
            imagedestroy($image);
            $image = $resized;
        }
        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
            imagewebp($image, null, 90);
        } else {
            imagejpeg($image, null, 90);
            $mime = 'image/jpeg';
        }
        $out = (string) ob_get_clean();
        imagedestroy($image);

        return [$out, $mime];
    }
}
