<?php

namespace App\Filters;

use App\Libraries\SyncVersion;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * After a successful change through the API, counts it in App\Libraries\SyncVersion so
 * other users' open pages refresh within seconds.
 */
class SyncVersionFilter implements FilterInterface
{
    /**
     * Writes nobody else sees: sign-in and account steps, downloads, previews, a staff
     * member's own draft list and the backup made on login.
     */
    private const IGNORED = [
        '#^api/auth/(?!register$|update-profile$)#',
        '#^api/export/#',
        '#^api/import/[a-z]+/(preview|revise)$#',
        '#^api/stockout/(add-temp|edit-temp/\d+|remove-temp/\d+)$#',
        '#^api/backups/(auto|inspect)$#',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $response->getStatusCode() >= 400) {
            return null;
        }

        $path = trim(preg_replace('#^index\.php/?#', '', trim($request->getPath(), '/')), '/');
        foreach (self::IGNORED as $pattern) {
            if (preg_match($pattern, $path)) {
                return null;
            }
        }

        // A sign-up has no session: it concerns every office's managers and technical staff
        $user = session('user');
        SyncVersion::bump(SyncVersion::changedScopes((int) ($user['user_office_id'] ?? 0)));

        return null;
    }
}
