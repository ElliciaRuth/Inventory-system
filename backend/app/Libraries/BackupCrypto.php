<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Password protection for backup packages: AES-256-GCM with a key derived from the
 * password (PBKDF2-SHA256). Runs entirely on this computer; nothing goes online.
 *
 * File layout: "BSUBAK01" | salt (16) | iv (12) | tag (16) | ciphertext
 */
class BackupCrypto
{
    public const MAGIC = 'BSUBAK01';

    private const ITERATIONS = 200000;

    public static function isEncrypted(string $data): bool
    {
        return str_starts_with($data, self::MAGIC);
    }

    public static function encrypt(string $plain, string $password): string
    {
        $salt = random_bytes(16);
        $iv   = random_bytes(12);
        $tag  = '';

        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($password, $salt), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        return self::MAGIC . $salt . $iv . $tag . $cipher;
    }

    public static function decrypt(string $data, string $password): string
    {
        if (! self::isEncrypted($data) || strlen($data) < 52) {
            throw new RuntimeException('This is not a password-protected backup.');
        }

        $salt   = substr($data, 8, 16);
        $iv     = substr($data, 24, 12);
        $tag    = substr($data, 36, 16);
        $plain  = openssl_decrypt(substr($data, 52), 'aes-256-gcm', self::key($password, $salt), OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            // GCM also fails when the file was altered, not only for a wrong password
            throw new RuntimeException('Wrong password, or the backup file is damaged.');
        }

        return $plain;
    }

    private static function key(string $password, string $salt): string
    {
        return hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);
    }
}
