<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * LevelFilter
 *
 * Enforces a minimum access level on protected API routes.
 *
 * Usage in Routes.php:
 *   ['filter' => 'level:2']   → requires level 2 or above
 *   ['filter' => 'level:3']   → requires level 3 or above
 *
 * Responds with 401 JSON when there is no session and 403 JSON when the
 * user's level is below the required minimum.
 */
class LevelFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = session('user');

        if (! $user) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['status' => false, 'message' => 'Please log in to continue.', 'code' => 'unauthenticated']);
        }

        $userLevel = (int) ($user['level_id'] ?? 0);
        $required  = (int) ($arguments[0] ?? 1);

        if ($userLevel < $required) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['status' => false, 'message' => 'Access denied. Insufficient permissions.', 'code' => 'forbidden']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
