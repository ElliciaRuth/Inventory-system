<?php

namespace App\Libraries;

use RuntimeException;

/**
 * AES-256-GCM encryption / decryption for the system SMTP app-password stored in
 * smtp_settings. Uses the same scheme as BackupCrypto (PBKDF2-SHA256 key derivation)
 * but derives the encryption key from Config\Encryption::key so that the DB alone is
 * not enough to recover the password.
 */
class SmtpCrypto
{
    private const MAGIC      = 'SMTPK01';
    private const ITERATIONS = 100_000;

    /**
     * Encrypt a plain-text app password. Returns a base64 string safe to store in TEXT.
     */
    public static function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $salt = random_bytes(16);
        $iv   = random_bytes(12);
        $tag  = '';

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            self::derivedKey($salt),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipher === false) {
            throw new RuntimeException('SMTP password could not be encrypted.');
        }

        return base64_encode(self::MAGIC . $salt . $iv . $tag . $cipher);
    }

    /**
     * Decrypt the base64 ciphertext back to the plain-text app password.
     */
    public static function decrypt(string $ciphertext): string
    {
        $raw  = base64_decode($ciphertext, true);
        $need = strlen(self::MAGIC) + 16 + 12 + 16; // magic + salt + iv + tag

        if ($raw === false || strlen($raw) < $need || ! str_starts_with($raw, self::MAGIC)) {
            throw new RuntimeException('The stored SMTP credential is corrupt or unreadable.');
        }

        $offset = strlen(self::MAGIC);
        $salt   = substr($raw, $offset, 16);
        $iv     = substr($raw, $offset + 16, 12);
        $tag    = substr($raw, $offset + 28, 16);
        $body   = substr($raw, $offset + 44);

        $plain = openssl_decrypt($body, 'aes-256-gcm', self::derivedKey($salt), OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            throw new RuntimeException('SMTP password decryption failed (wrong key or tampered data).');
        }

        return $plain;
    }

    // ── private ──────────────────────────────────────────────────────────────

    private static function derivedKey(string $salt): string
    {
        $master = (string) (config(\Config\Encryption::class)->key ?? '');

        if ($master === '') {
            // Fall back to a fixed pepper so a missing .env does not brick the install;
            // the admin should set encryption.key in production
            $master = 'bsu-inventory-smtp-fallback-key';
        }

        return hash_pbkdf2('sha256', $master, $salt, self::ITERATIONS, 32, true);
    }
}

