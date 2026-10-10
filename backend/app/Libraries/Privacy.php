<?php

namespace App\Libraries;

/**
 * Email addresses are only ever shown partly hidden: they are where password-reset codes go,
 * so no one else (a manager, the audit log, a screen over someone's shoulder) gets them whole.
 */
class Privacy
{
    /** An email address anywhere in a text. Masked addresses (with *) don't match again. */
    public const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /** The same pattern for MySQL/MariaDB REGEXP_REPLACE */
    public const EMAIL_SQL_PATTERN = '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}';

    /** manager_fpc@bsu.edu.ph → ma********c@bsu.edu.ph (same as the frontend's maskEmail) */
    public static function maskEmail(?string $email): string
    {
        $email = (string) $email;
        $at    = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return $email;
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);
        $length = mb_strlen($local);

        return $length <= 3
            ? mb_substr($local, 0, 1) . str_repeat('*', $length - 1) . $domain
            : mb_substr($local, 0, 2) . str_repeat('*', $length - 3) . mb_substr($local, -1) . $domain;
    }

    /** Masks every email address inside a text. */
    public static function maskEmailsIn(string $text): string
    {
        return (string) preg_replace_callback(self::EMAIL_PATTERN, static fn ($m) => self::maskEmail($m[0]), $text);
    }

    /** Masks every email address in the strings of a value (arrays are walked). */
    public static function maskEmailsDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::maskEmailsIn($value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'maskEmailsDeep'], $value);
        }

        return $value;
    }
}
