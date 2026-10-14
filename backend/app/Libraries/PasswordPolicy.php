<?php

namespace App\Libraries;

/**
 * The rules every new password must meet: in the app (sign-up, password changes and
 * resets) and in `php spark user:create-admin`, which the Windows installer runs.
 */
final class PasswordPolicy
{
    /** Shortest password accepted anywhere a password is set. */
    public const MIN_LENGTH = 8;

    /** Why a new password is too weak, or null when it is acceptable. */
    public static function weakness(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }
        if (strlen($password) > 72) {
            // bcrypt ignores everything after 72 bytes
            return 'Password must be at most 72 characters long.';
        }
        if (! preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (! preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (! preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        // No sequential numbers (e.g. 123, 234, 345…)
        if (preg_match('/(?:0(?=1)|1(?=2)|2(?=3)|3(?=4)|4(?=5)|5(?=6)|6(?=7)|7(?=8)|8(?=9)){2}/', $password)) {
            return 'Password must not contain sequential numbers (e.g. 123, 456).';
        }

        return null;
    }
}
