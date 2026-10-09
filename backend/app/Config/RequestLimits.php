<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Limits on how much data one request may send and how often requests may come,
 * enforced by App\Filters\RequestGuard on every /api route.
 * Keep the byte limits below PHP's post_max_size / upload_max_filesize (40M on XAMPP, 64M in Docker).
 */
class RequestLimits extends BaseConfig
{
    // ── Size of one request ──────────────────────────────────────────────

    /** Largest ordinary request body (JSON or form), in bytes. */
    public int $maxBodyBytes = 1024 * 1024; // 1 MB

    /** Largest request on the routes that accept file uploads, in bytes. */
    public int $maxUploadBytes = 40 * 1024 * 1024; // 40 MB

    /** Routes (after /api/) that accept file uploads. */
    public array $uploadRoutes = [
        'import/stockcards/preview',
        'backups/restore',
        'backups/inspect',
    ];

    // ── Shape of the data ────────────────────────────────────────────────

    /** Most values (array items + fields, counted through every level) one request may carry. */
    public int $maxItems = 5000;

    /** Deepest nesting of arrays/objects. */
    public int $maxDepth = 10;

    /** Longest single text value, in characters. */
    public int $maxStringLength = 10000;

    // ── How often ────────────────────────────────────────────────────────

    /** Any API request, per IP address: [requests, seconds]. */
    public array $perIp = [600, 60];

    /** Requests that change data (POST/PUT/PATCH/DELETE), per signed-in user (or IP): [requests, seconds]. */
    public array $writesPerUser = [90, 60];

    /** Stricter limits on public endpoints, per IP: route (after /api/) => [requests, seconds]. */
    public array $routeLimits = [
        'auth/login'             => [30, 60],
        'auth/register'          => [5, 3600],
        'auth/forgot-password'   => [10, 900],
        'auth/verify-reset-code' => [20, 900],
        'auth/reset-password'    => [10, 900],
    ];

    // ── Uploaded archives (.zip backups, .xlsx workbooks) ────────────────

    /** Largest single file inside an archive once unpacked, in bytes. */
    public int $maxUnpackedEntryBytes = 64 * 1024 * 1024;

    /** Largest total size of an archive once unpacked, in bytes. */
    public int $maxUnpackedTotalBytes = 160 * 1024 * 1024;

    /** Most files inside one archive. */
    public int $maxArchiveEntries = 20000;
}
