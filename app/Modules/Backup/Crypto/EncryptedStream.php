<?php

namespace App\Modules\Backup\Crypto;

use RuntimeException;

/**
 * Format de fișier criptat, scris și citit în mesaje succesive (XChaCha20-Poly1305 secretstream):
 *
 *   "SEVBK1" | versiune (1 octet) | id cheie (8 octeți) | antet secretstream (24 octeți)
 *   apoi cadre: lungime (4 octeți, big-endian) | mesaj criptat
 *
 * Ultimul mesaj poartă eticheta TAG_FINAL; un fișier fără el este trunchiat și se respinge.
 * Starea de scriere/citire poate fi salvată (toState) și reluată (resume), pentru pași care rulează în mai
 * multe cereri succesive fără a depăși max_execution_time.
 */
final class EncryptedStream
{
    public const MAGIC = 'SEVBK1';

    public const VERSION = 1;

    public const HEADER_BYTES = 6 + 1 + 8 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    public const MAX_FRAME = 16 * 1024 * 1024;

    /** @var resource */
    private $handle;

    private function __construct(
        private readonly string $path,
        private string $state,
        private int $offset,
        private int $messages,
        private readonly string $mode,
        private bool $final = false,
    ) {}

    public static function create(string $path, BackupKey $key): self
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key->bytes);
        $old = umask(0077);
        $fh = fopen($path, 'xb');
        umask($old);
        if ($fh === false) {
            throw new RuntimeException('Nu pot crea fișierul de backup '.$path);
        }
        fwrite($fh, self::MAGIC.chr(self::VERSION).$key->id().$header);
        fflush($fh);
        $stream = new self($path, $state, self::HEADER_BYTES, 0, 'w');
        $stream->handle = $fh;

        return $stream;
    }

    /** Reia scrierea dintr-o stare salvată; tot ce s-a scris după acea stare (cadru incomplet) este eliminat. */
    public static function resumeWriter(string $path, array $saved): self
    {
        $fh = fopen($path, 'r+b');
        if ($fh === false) {
            throw new RuntimeException('Nu pot redeschide fișierul de backup.');
        }
        ftruncate($fh, $saved['offset']);
        fseek($fh, $saved['offset']);
        $stream = new self($path, base64_decode($saved['state']), $saved['offset'], $saved['messages'], 'w');
        $stream->handle = $fh;

        return $stream;
    }

    public static function openReader(string $path, BackupKey $key): self
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Nu pot deschide fișierul de backup.');
        }
        $head = fread($fh, self::HEADER_BYTES);
        if ($head === false || strlen($head) !== self::HEADER_BYTES || ! str_starts_with($head, self::MAGIC)) {
            throw new RuntimeException('Fișierul nu este un backup SEVALIA.');
        }
        if (ord($head[6]) !== self::VERSION) {
            throw new RuntimeException('Versiune de backup necunoscută.');
        }
        if (! hash_equals(substr($head, 7, 8), $key->id())) {
            throw new RuntimeException('Backupul a fost criptat cu altă cheie decât cheia de backup curentă.');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, 15), $key->bytes);
        $stream = new self($path, $state, self::HEADER_BYTES, 0, 'r');
        $stream->handle = $fh;

        return $stream;
    }

    public static function resumeReader(string $path, array $saved): self
    {
        $fh = fopen($path, 'rb');
        fseek($fh, $saved['offset']);
        $stream = new self($path, base64_decode($saved['state']), $saved['offset'], $saved['messages'], 'r');
        $stream->handle = $fh;

        return $stream;
    }

    public function write(string $plain, bool $final = false): void
    {
        if ($this->mode !== 'w' || $this->final) {
            throw new RuntimeException('Fluxul nu acceptă scriere.');
        }
        $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        $frame = pack('N', strlen($cipher)).$cipher;
        if (fwrite($this->handle, $frame) !== strlen($frame)) {
            throw new RuntimeException('Scriere incompletă în backup (spațiu insuficient?).');
        }
        $this->offset += strlen($frame);
        $this->messages++;
        $this->final = $final;
    }

    /** @return string|null mesajul următor sau null după mesajul final */
    public function read(): ?string
    {
        if ($this->final) {
            return null;
        }
        $len = fread($this->handle, 4);
        if ($len === '' || $len === false) {
            throw new RuntimeException('Backup trunchiat: lipsește marcajul de final.');
        }
        if (strlen($len) !== 4) {
            throw new RuntimeException('Backup trunchiat (cadru incomplet).');
        }
        $n = unpack('N', $len)[1];
        if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > self::MAX_FRAME) {
            throw new RuntimeException('Backup corupt (lungime de cadru invalidă).');
        }
        $cipher = '';
        while (strlen($cipher) < $n && ! feof($this->handle)) {
            $cipher .= fread($this->handle, $n - strlen($cipher));
        }
        if (strlen($cipher) !== $n) {
            throw new RuntimeException('Backup trunchiat (cadru incomplet).');
        }
        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $cipher);
        if ($result === false) {
            throw new RuntimeException('Backup corupt sau modificat: autentificarea unui bloc a eșuat.');
        }
        [$plain, $tag] = $result;
        $this->offset += 4 + $n;
        $this->messages++;
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->final = true;
        }

        return $plain;
    }

    public function isFinal(): bool
    {
        return $this->final;
    }

    public function messages(): int
    {
        return $this->messages;
    }

    /** @return array{offset: int, state: string, messages: int} */
    public function toState(): array
    {
        fflush($this->handle);

        return ['offset' => $this->offset, 'state' => base64_encode($this->state), 'messages' => $this->messages];
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fflush($this->handle);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
