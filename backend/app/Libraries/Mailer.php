<?php

namespace App\Libraries;

use CodeIgniter\Email\Email;
use Config\PasswordReset;

/**
 * Sends email over SMTP. Two modes:
 *
 * • sendFromOwnAccount() – the user provides their own email + app-password at runtime
 *   (used by the "change email" flow). The key is used for one send and dropped.
 *
 * • sendFromSystem() – reads the one system SMTP account stored (encrypted) in the
 *   smtp_settings table and sends from there. Used by the "forgot password" OTP flow.
 *
 * App passwords are only ever kept in memory long enough to call send(); they are never
 * logged, returned, or put into a session.
 */
class Mailer
{
    /** Why a send failed */
    public const FAILED_SIGN_IN    = 'sign_in';    // provider rejected the address / app password
    public const FAILED_NETWORK    = 'network';    // mail server unreachable
    public const FAILED_OTHER      = 'other';
    public const FAILED_NO_ACCOUNT = 'no_account'; // smtp_settings has no row yet

    /**
     * Send via the system SMTP account stored in smtp_settings.
     * Returns null on success, else one of the FAILED_* reasons.
     */
    public function sendFromSystem(
        string $to,
        string $subject,
        string $html
    ): ?string {
        $row = db_connect()->table('smtp_settings')->limit(1)->get()->getRowArray();
        if (! $row) {
            return self::FAILED_NO_ACCOUNT;
        }

        try {
            $password = SmtpCrypto::decrypt((string) $row['smtp_password']);
        } catch (\Throwable) {
            return self::FAILED_OTHER;
        }

        $account = (string) $row['smtp_email'];
        $servers = config(PasswordReset::class)->smtpServers;
        $domain  = strtolower((string) substr(strrchr($account, '@') ?: '', 1));
        $server  = $servers[$domain] ?? $servers['*'];

        $result   = $this->deliver($server, $account, $password, $to, $subject, $html);
        $password = '';

        return $result;
    }

    /**
     * Send from the user's own account ($account signs in with $appPassword). The mail server is
     * chosen from Config\PasswordReset by the account's domain. Returns null on success, else one
     * of the FAILED_* reasons.
     */
    public function sendFromOwnAccount(
        string $account,
        #[\SensitiveParameter] string $appPassword,
        string $to,
        string $subject,
        string $html
    ): ?string {
        $servers = config(PasswordReset::class)->smtpServers;
        $domain  = strtolower((string) substr(strrchr($account, '@') ?: '', 1));
        $server  = $servers[$domain] ?? $servers['*'];

        return $this->deliver($server, $account, $appPassword, $to, $subject, $html);
    }

    /**
     * One SMTP send with these credentials. Null on success, else a FAILED_* reason.
     *
     * @param array{host: string, port: int, crypto: string} $server
     */
    private function deliver(
        array $server,
        string $account,
        #[\SensitiveParameter] string $password,
        string $to,
        string $subject,
        string $html
    ): ?string {
        // Exception traces must not carry argument values: a failure part-way through the
        // sign-in could otherwise put (part of) the encoded password into the error log
        $ignoreArgs = ini_set('zend.exception_ignore_args', '1');

        // A fresh instance, not the shared service, so the password can't linger for later use
        $email = new Email(config(\Config\Email::class));
        $debug = '';

        try {
            $email->initialize([
                'protocol'    => 'smtp',
                'SMTPHost'    => $server['host'],
                'SMTPPort'    => $server['port'],
                'SMTPCrypto'  => $server['crypto'],
                'SMTPUser'    => $account,
                'SMTPPass'    => $password,
                'SMTPTimeout' => 15,
                'mailType'    => 'html',
            ]);
            $email->setFrom($account, 'BSU Inventory System');
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($html);

            // send() returns false (it does not throw) when the provider rejects the login or message
            $sent = $email->send(false);
            if (! $sent) {
                // The SMTP conversation as text: server replies only, no credentials
                $debug = strip_tags($email->printDebugger([]));
            }
        } catch (\Throwable $e) {
            $sent  = false;
            $debug = $e->getMessage();
        } finally {
            $email->SMTPPass = '';
            $email->clear();
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
        $password = '';

        if ($sent) {
            return null;
        }

        $reason = $this->failureReason($debug);
        log_message('error', 'Email via {host} failed ({reason}): {debug}', [
            'host'   => $server['host'],
            'reason' => $reason,
            'debug'  => mb_substr($debug, 0, 1500),
        ]);

        return $reason;
    }

    private function failureReason(string $debug): string
    {
        // 535 / 534: Gmail's "Username and Password not accepted" / "Application-specific password required"
        if (preg_match('/\b53[345]\b|not accepted|authenticat|Application-specific password/i', $debug)) {
            return self::FAILED_SIGN_IN;
        }
        // The mail server never greeted us (SMTP "220"): it couldn't be reached at all
        if (! preg_match('/\b220\b/', $debug)
            || preg_match('/getaddrinfo|php_network|timed out|refused|unable to connect|failed to connect|no route|network is unreachable|could not resolve/i', $debug)) {
            return self::FAILED_NETWORK;
        }

        return self::FAILED_OTHER;
    }
}
