<?php

namespace App\Modules\Updates\Package;

/**
 * Verificarea semnăturii Ed25519 a manifestului cu cheile publice de încredere livrate în cod.
 */
class SignatureVerifier
{
    /** @param  list<string>  $keyDirs */
    public function __construct(private readonly array $keyDirs) {}

    public static function fromConfig(): self
    {
        return new self(config('updates.trusted_keys_paths', []));
    }

    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /** @return array<string, string> key_id => cheie publică binară */
    public function trustedKeys(): array
    {
        $keys = [];
        foreach ($this->keyDirs as $dir) {
            foreach (glob(rtrim($dir, '/').'/*.pub') ?: [] as $file) {
                $raw = base64_decode(trim((string) file_get_contents($file)), true);
                if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $keys[self::keyId($raw)] = $raw;
                }
            }
        }

        return $keys;
    }

    /** Verifică semnătura și întoarce identificatorul cheii care a semnat. */
    public function verify(string $manifestBytes, string $signatureFile): string
    {
        $sig = json_decode($signatureFile, true);
        if (! is_array($sig) || ($sig['alg'] ?? null) !== 'ed25519' || ! is_string($sig['key_id'] ?? null) || ! is_string($sig['signature'] ?? null)) {
            throw new PackageException('Fișierul de semnătură (manifest.sig) lipsește sau este invalid.');
        }
        $keys = $this->trustedKeys();
        if ($keys === []) {
            throw new PackageException('Nu există nicio cheie publică de încredere configurată. Pachetul nu poate fi verificat.');
        }
        $public = $keys[$sig['key_id']] ?? null;
        if ($public === null) {
            throw new PackageException('Pachetul este semnat cu o cheie care nu este de încredere ('.$sig['key_id'].').');
        }
        $signature = base64_decode($sig['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $manifestBytes, $public)) {
            throw new PackageException('Semnătura pachetului nu este validă. Pachetul a fost modificat sau nu provine de la sursa de încredere.');
        }

        return $sig['key_id'];
    }

    /** Folosit de unealta de release (mașina de build): semnează manifestul cu cheia privată. */
    public static function sign(string $manifestBytes, string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new PackageException('Cheia privată de semnare nu are formatul așteptat.');
        }
        $public = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        return json_encode([
            'format' => 1,
            'alg' => 'ed25519',
            'key_id' => self::keyId($public),
            'signature' => base64_encode(sodium_crypto_sign_detached($manifestBytes, $secretKey)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
