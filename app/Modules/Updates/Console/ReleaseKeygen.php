<?php

namespace App\Modules\Updates\Console;

use App\Modules\Updates\Package\SignatureVerifier;
use Illuminate\Console\Command;

class ReleaseKeygen extends Command
{
    protected $signature = 'sevalia:release:keygen {--out= : Directorul pentru cheia privată (în afara repository-ului)} {--force : Permite un director din repository (NU recomandat)}';

    protected $description = 'Generează o pereche de chei Ed25519 pentru semnarea pachetelor (pe mașina de build)';

    public function handle(): int
    {
        $out = rtrim((string) $this->option('out'), '/');
        if ($out === '') {
            $this->error('Indicați --out=<director> în afara repository-ului (ex. ~/.sevalia-release).');

            return self::FAILURE;
        }
        if (! is_dir($out)) {
            mkdir($out, 0700, true);
        }
        $real = realpath($out);
        if (str_starts_with($real.'/', realpath(base_path()).'/') && ! $this->option('force')) {
            $this->error('Directorul este în interiorul repository-ului. Cheia privată nu trebuie să ajungă în cod sau pe hosting.');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $id = SignatureVerifier::keyId($public);

        $secretFile = "{$real}/sevalia-release-{$id}.key";
        $publicFile = "{$real}/{$id}.pub";
        $old = umask(0077);
        file_put_contents($secretFile, base64_encode($secret)."\n");
        umask($old);
        chmod($secretFile, 0600);
        file_put_contents($publicFile, base64_encode($public)."\n");
        sodium_memzero($secret);

        $this->info("Cheie generată, identificator {$id}.");
        $this->line("  privată (NU o copiați pe hosting, faceți o copie offline): {$secretFile}");
        $this->line("  publică: {$publicFile}");
        $this->line('Adăugați cheia publică în config/update-keys/ printr-un commit revizuit; abia apoi pachetele semnate cu ea vor fi acceptate.');

        return self::SUCCESS;
    }
}
