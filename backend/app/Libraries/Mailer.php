<?php

namespace App\Libraries;

use App\Models\SmtpSettingsModel;

/**
 * Sends system email through the Gmail account saved in smtp_settings
 * (configured by Technical Staff under User Management → Email Settings).
 */
class Mailer
{
    private ?array $config;

    public function __construct()
    {
        $this->config = (new SmtpSettingsModel())->getActive();
    }

    public function isConfigured(): bool
    {
        return $this->config !== null;
    }

    /**
     * Sends an HTML email. Returns null on success, or a short error message.
     * The full SMTP transcript is written to the log on failure.
     */
    public function send(string $to, string $subject, string $html): ?string
    {
        if (! $this->config) {
            return 'Email is not configured.';
        }

        try {
            $password = service('encrypter')->decrypt(base64_decode($this->config['smtp_password']));
        } catch (\Throwable $e) {
            log_message('error', 'SMTP password could not be decrypted: ' . $e->getMessage());
            return 'The saved email password could not be read. Please save the email settings again.';
        }

        $email = \Config\Services::email();
        $email->initialize([
            'protocol'    => 'smtp',
            'SMTPHost'    => 'smtp.gmail.com',
            'SMTPUser'    => $this->config['smtp_email'],
            'SMTPPass'    => $password,
            'SMTPPort'    => 587,
            'SMTPCrypto'  => 'tls',
            'SMTPTimeout' => 15,
            'mailType'    => 'html',
        ]);
        $email->setFrom($this->config['smtp_email'], 'BSU Inventory System');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($html);

        try {
            // send() returns false (it does not throw) when Gmail rejects the login or message
            if ($email->send(false)) {
                return null;
            }
            $debug = $email->printDebugger(['headers']);
        } catch (\Throwable $e) {
            $debug = $e->getMessage();
        }

        log_message('error', 'Email to {to} failed: {debug}', ['to' => $to, 'debug' => strip_tags($debug)]);

        if (stripos($debug, '535') !== false || stripos($debug, 'Username and Password not accepted') !== false) {
            return 'Gmail rejected the login. Check the Gmail address and use a 16-character App Password, not your normal Gmail password.';
        }

        return 'The email could not be sent. Check the Gmail address, App Password and internet connection.';
    }
}
