<?php

namespace App\Controllers\Api;

use App\Libraries\SyncVersion;
use CodeIgniter\HTTP\ResponseInterface;

class SyncController extends BaseApiController
{
    /**
     * GET /api/sync — the change counters this user watches. The app polls this every few
     * seconds and refreshes the open page and notifications when the value changes.
     */
    public function index(): ResponseInterface
    {
        return $this->respondSuccess([
            'version' => SyncVersion::read(SyncVersion::watchedScopes($this->currentOfficeId())),
        ], 'Sync version');
    }
}
