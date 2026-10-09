<?php

namespace App\Filters;

use App\Libraries\AuditLog;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\RequestLimits;

/**
 * Stops oversized, over-complex or too-frequent requests before they reach a controller:
 *
 *   413  body larger than allowed (1 MB, or 40 MB on upload routes)
 *   422  JSON/form data with too many values, too deep, or a text value that is too long
 *   429  too many requests from one IP / one user / on a public endpoint (Retry-After header)
 *
 * Limits live in Config\RequestLimits. Each kind of block is written to the audit log
 * at most once per minute per source, so an attack can't flood the log either.
 */
class RequestGuard implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config(RequestLimits::class);
        $method = strtoupper($request->getMethod());

        if ($method === 'OPTIONS') {
            return null; // CORS preflight
        }

        $route = $this->route($request);
        $ip    = (string) $request->getIPAddress();

        // ── Cross-site request forgery ──
        // The API uses the session cookie and skips CodeIgniter's token-based CSRF filter.
        // Every change must carry X-Requested-With (the app's HTTP client always sends it):
        // a page on another site or port can't add that header without a CORS preflight,
        // which Config\Cors only grants to the app's own origins. A plain HTML form can't
        // set it at all.
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && strtolower($request->getHeaderLine('X-Requested-With')) !== 'xmlhttprequest') {
            return $this->reject(
                $route,
                'csrf_blocked',
                'This request did not come from the BSU Inventory app and was refused.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        // ── How often ──
        if ($blocked = $this->throttle('ip', $ip, $config->perIp, $route)) {
            return $blocked;
        }
        foreach ($config->routeLimits as $limitedRoute => $limit) {
            if ($route === $limitedRoute && ($blocked = $this->throttle('route:' . $route, $ip, $limit, $route))) {
                return $blocked;
            }
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $user = session('user');
            $who  = isset($user['id']) ? 'user:' . $user['id'] : 'ip:' . $ip;
            if ($blocked = $this->throttle('writes', $who, $config->writesPerUser, $route)) {
                return $blocked;
            }
        }

        // ── Size ──
        $isUpload = in_array($route, $config->uploadRoutes, true);
        $maxBytes = $isUpload ? $config->maxUploadBytes : $config->maxBodyBytes;
        $declared = (int) $request->getHeaderLine('Content-Length');

        if ($declared > $maxBytes || $this->uploadedBytes() > $maxBytes) {
            return $this->tooLarge($route, $maxBytes, $declared);
        }

        // Bodies sent without a Content-Length (chunked) are measured directly
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (! str_contains($contentType, 'multipart/form-data') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = (string) $request->getBody();
            if (strlen($body) > $maxBytes) {
                return $this->tooLarge($route, $maxBytes, strlen($body));
            }

            // ── Shape ──
            $data = null;
            if (str_contains($contentType, 'json') && $body !== '') {
                $data = json_decode($body, true, $config->maxDepth + 1);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    return $this->reject(
                        $route,
                        'malformed',
                        json_last_error() === JSON_ERROR_DEPTH
                            ? 'The data sent is nested too deeply.'
                            : 'The data sent is not valid JSON.',
                        ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            } else {
                $data = $request->getPost();
            }

            if (is_array($data) && ($problem = $this->shapeProblem($data, $config))) {
                return $this->reject($route, 'too_complex', $problem, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /** Path after /api/, e.g. "stock/add". */
    private function route(RequestInterface $request): string
    {
        $path = trim($request->getUri()->getPath(), '/');
        $path = preg_replace('#^(index\.php/)?#', '', $path);

        return preg_replace('#^api/#', '', (string) $path);
    }

    /** Total size of the files in a multipart upload. */
    private function uploadedBytes(): int
    {
        $total = 0;
        array_walk_recursive($_FILES, static function ($value, $key) use (&$total) {
            if ($key === 'size') {
                $total += (int) $value;
            }
        });

        return $total;
    }

    /**
     * Too many values, too deep, or a text value too long → a message; fine → null.
     */
    private function shapeProblem(array $data, RequestLimits $config): ?string
    {
        $count = 0;
        $stack = [[$data, 1]];

        while ($stack) {
            [$node, $depth] = array_pop($stack);
            if ($depth > $config->maxDepth) {
                return 'The data sent is nested too deeply.';
            }
            foreach ($node as $key => $value) {
                if (++$count > $config->maxItems) {
                    return "Too much data in one request (more than {$config->maxItems} values). Send it in smaller parts.";
                }
                if (is_string($key) && mb_strlen($key) > 200) {
                    return 'A field name in the data is too long.';
                }
                if (is_array($value)) {
                    $stack[] = [$value, $depth + 1];
                } elseif (is_string($value) && mb_strlen($value) > $config->maxStringLength) {
                    return "A text value is too long (more than {$config->maxStringLength} characters).";
                }
            }
        }

        return null;
    }

    /**
     * Token bucket per $bucket + $who (CodeIgniter's Throttler, kept in the cache).
     *
     * @param array{0: int, 1: int} $limit [requests, seconds]
     */
    private function throttle(string $bucket, string $who, array $limit, string $route): ?ResponseInterface
    {
        [$capacity, $seconds] = $limit;
        $throttler = service('throttler');

        if ($throttler->check('guard_' . md5($bucket . '|' . $who), $capacity, $seconds)) {
            return null;
        }

        $wait = max(1, (int) $throttler->getTokenTime());

        return $this->reject(
            $route,
            'rate_limited',
            "Too many requests. Please wait {$wait} second" . ($wait === 1 ? '' : 's') . ' and try again.',
            ResponseInterface::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $wait],
            $bucket
        );
    }

    private function tooLarge(string $route, int $maxBytes, int $sent): ResponseInterface
    {
        $limit = $maxBytes >= 1048576 ? round($maxBytes / 1048576) . ' MB' : round($maxBytes / 1024) . ' KB';

        return $this->reject(
            $route,
            'payload_too_large',
            "The data sent is too large (limit {$limit}).",
            ResponseInterface::HTTP_PAYLOAD_TOO_LARGE,
            [],
            'size',
            ['bytes_sent' => $sent, 'limit_bytes' => $maxBytes]
        );
    }

    private function reject(string $route, string $kind, string $message, int $status, array $headers = [], string $bucket = '', array $extra = []): ResponseInterface
    {
        $this->auditOnce($kind, $route, $message, ['bucket' => $bucket] + $extra);

        $response = service('response')
            ->setStatusCode($status)
            ->setJSON([
                'status'  => false,
                'message' => $message,
                'code'    => $kind,
            ]);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /** One audit entry per kind of block, route and source per minute. */
    private function auditOnce(string $kind, string $route, string $message, array $details): void
    {
        $request = service('request');
        $user    = session('user');
        $who     = isset($user['id']) ? 'user:' . $user['id'] : 'ip:' . $request->getIPAddress();
        $key     = 'guard_logged_' . md5($kind . '|' . $route . '|' . $who);

        if (cache($key)) {
            return;
        }
        cache()->save($key, 1, 60);

        AuditLog::record('security.' . $kind, 'request', null, "Blocked request to /api/{$route}: {$message}", $details + [
            'method' => strtoupper($request->getMethod()),
        ]);
    }
}
