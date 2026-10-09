<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Forgot password: the reset code is emailed through the user's OWN email account, using an
 * app password they type in (never stored). The server needs internet only while it sends.
 */
class PasswordReset extends BaseConfig
{
    /**
     * Mail server for each email domain. Fixed here, never taken from the request, so nobody
     * can make the server connect anywhere else. "*" covers every other domain: Gmail, and
     * Google Workspace addresses such as @bsu.edu.ph.
     *
     * @var array<string, array{host: string, port: int, crypto: string}>
     */
    public array $smtpServers = [
        'yahoo.com' => ['host' => 'smtp.mail.yahoo.com', 'port' => 465, 'crypto' => 'ssl'],
        '*'         => ['host' => 'smtp.gmail.com', 'port' => 587, 'crypto' => 'tls'],
    ];

    /** Minutes a reset code stays valid */
    public int $codeMinutes = 15;

    /** Wrong guesses allowed per code before a new code is needed */
    public int $maxCodeAttempts = 5;

    /** Codes one email address may receive: at most one per this many seconds … */
    public int $sendCooldownSeconds = 60;

    /** … and this many per hour */
    public int $sendsPerHour = 5;

    /** Rejected app passwords per email address before a 15-minute pause */
    public int $maxSignInFailures = 5;
}
