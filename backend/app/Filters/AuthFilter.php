<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthFilter
 *
 * - Rejects unauthenticated API requests with 401 JSON
 * - Enforces a 24-hour session timeout (from login_time)
 * - Rejects sessions whose user was deactivated or deleted
 * - Blocks everything except the matching setup endpoint while a forced
 *   first-login step (password change / SMTP / recovery email) is pending
 * - Sets security response headers on every authenticated response
 */
class AuthFilter implements FilterInterface
{
    /** Session lifetime in seconds (24 hours) */
    private const SESSION_TTL = 86400;

    /**
     * Forced first-login steps: session flag => [error code, endpoints still allowed].
     * auth/me and auth/logout are always allowed so the frontend can route the user.
     */
    private const FORCED_STEPS = [
        'must_change_password'      => ['password_change_required', ['api/auth/change-password']],
        'must_setup_smtp'           => ['smtp_setup_required', ['api/auth/setup-smtp']],
        'must_setup_recovery_email' => ['recovery_email_setup_required', ['api/auth/setup-recovery-email']],
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        // ── 1. Must be logged in ──────────────────────────────────────────
        if (! session()->has('user')) {
            return $this->deny(401, 'Please log in to continue.', 'unauthenticated');
        }

        // ── 2. 24-hour absolute session timeout ───────────────────────────
        $loginTime = (int) (session('login_time') ?? 0);

        if ($loginTime === 0 || (time() - $loginTime) > self::SESSION_TTL) {
            session()->destroy();
            return $this->deny(401, 'Your session has expired. Please log in again.', 'session_expired');
        }

        // ── 3. Verify the session user still exists and is active ─────────
        $userId = (int) (session('user')['id'] ?? 0);
        if ($userId > 0) {
            $row = db_connect()
                ->table('user_table')
                ->select('user_activity_id')
                ->where('user_id', $userId)
                ->get()
                ->getRowArray();

            // Deactivated or deleted → kick out immediately
            if (! $row || (int) $row['user_activity_id'] !== 1) {
                session()->destroy();
                return $this->deny(401, 'Your account has been deactivated. Please contact an administrator.', 'account_inactive');
            }
        }

        // ── 4. Forced first-login steps ──────────────────────────────────
        $currentPath = trim(preg_replace('#^index\.php/?#', '', trim($request->getPath(), '/')), '/');

        foreach (self::FORCED_STEPS as $flag => [$code, $allowed]) {
            if (! session($flag)) {
                continue;
            }
            $allowed = array_merge($allowed, ['api/auth/me', 'api/auth/logout']);
            if (! in_array($currentPath, $allowed, true)) {
                return $this->deny(403, 'Please complete your account setup first.', $code);
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // ── Security headers on every authenticated response ───────────────
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }

    private function deny(int $status, string $message, string $code): ResponseInterface
    {
        return service('response')
            ->setStatusCode($status)
            ->setJSON([
                'status'  => false,
                'message' => $message,
                'code'    => $code,
            ]);
    }
}
