<?php

/*
 * Păstrarea cheii private de semnare a actualizărilor în repository, CRIPTATĂ cu o frază de acces.
 *
 * Cheia privată nu stă niciodată în clar în repository sau pe hosting. Fraza de acces o deține clinica și se
 * comunică doar la construirea unei actualizări. Criptare: Argon2id (sodium_crypto_pwhash) + XSalsa20-Poly1305.
 *
 *   php tools/release/cheie-protejata.php cripteaza <cheie.key> <iesire.enc>     (fraza: variabila SEVALIA_FRAZA)
 *   php tools/release/cheie-protejata.php decripteaza <intrare.enc> <cheie.key>   (fișier temporar, 0600; ștergeți-l după build)
 */

[$_, $mode, $in, $out] = $argv + [null, null, null, null];
$phrase = (string) getenv('SEVALIA_FRAZA');
if (! in_array($mode, ['cripteaza', 'decripteaza'], true) || ! $in || ! $out || strlen($phrase) < 16) {
    fwrite(STDERR, "Utilizare: SEVALIA_FRAZA='…' php tools/release/cheie-protejata.php cripteaza|decripteaza <intrare> <iesire>\n");
    exit(1);
}

if ($mode === 'cripteaza') {
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $phrase, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $box = sodium_crypto_secretbox((string) file_get_contents($in), $nonce, $key);
    file_put_contents($out, json_encode([
        'format' => 'sevalia-cheie-1', 'kdf' => 'argon2id13-moderate',
        'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'date' => base64_encode($box),
    ], JSON_PRETTY_PRINT)."\n");
    echo "Cheie criptată: $out\n";
    exit(0);
}

$data = json_decode((string) file_get_contents($in), true);
$key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $phrase, base64_decode($data['salt']), SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
$plain = sodium_crypto_secretbox_open(base64_decode($data['date']), base64_decode($data['nonce']), $key);
if ($plain === false) {
    fwrite(STDERR, "Fraza de acces nu este corectă.\n");
    exit(2);
}
$old = umask(0077);
file_put_contents($out, $plain);
umask($old);
chmod($out, 0600);
echo "Cheie decriptată (temporar): $out — ștergeți fișierul după construirea pachetului.\n";
