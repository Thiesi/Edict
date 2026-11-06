<?php

declare(strict_types=1);

namespace Edict\Auth;

/**
 * Authenticated encryption (AES-256-GCM) for secrets stored in the database,
 * so that DB dumps and backups don't contain usable TOTP secrets. The key is
 * derived from APP_SECRET.
 */
final readonly class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private string $key;

    public function __construct(#[\SensitiveParameter] string $appSecret)
    {
        $this->key = hash_hkdf('sha256', $appSecret, 32, 'edict-secretbox-v1');
    }

    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function open(#[\SensitiveParameter] string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Malformed sealed secret');
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Sealed secret cannot be decrypted (wrong APP_SECRET or tampered data)');
        }

        return $plaintext;
    }
}
