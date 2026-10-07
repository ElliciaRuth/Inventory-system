<?php

namespace App\Controllers\Api;

use App\Models\DashboardModel;
use App\Models\SettingsModel;
use CodeIgniter\HTTP\ResponseInterface;

class DashboardController extends BaseApiController
{
    private DashboardModel $dashboardModel;
    private SettingsModel $settingsModel;

    public function __construct()
    {
        $this->dashboardModel = new DashboardModel();
        $this->settingsModel  = new SettingsModel();
    }

    /**
     * Dashboard KPI summary, alerts, and recent transactions.
     * GET /api/dashboard
     */
    public function index(): ResponseInterface
    {
        $officeId = $this->currentOfficeId();
        $levelId  = $this->currentLevelId();

        // If level 4 technical staff, default to admin dashboard data
        if ($levelId >= 4 && $this->request->getGet('view') !== 'inventory') {
            return $this->admin();
        }

        $overview = $this->dashboardModel->overview($officeId);
        $overview['is_admin'] = ($levelId >= 3);
        $overview['level_id'] = $levelId;

        return $this->respondSuccess($overview, 'Dashboard data retrieved');
    }

    /**
     * Admin dashboard data (user management, pending applicants, offices).
     * Served to level 4 accounts in place of the inventory dashboard.
     */
    private function admin(): ResponseInterface
    {
        $data = $this->settingsModel->indexData($this->currentOfficeId(), $this->currentLevelId());
        $data['is_admin_dashboard'] = true;

        return $this->respondSuccess($data, 'Admin dashboard data retrieved');
    }

    /**
     * Paginated transaction log with search and filters.
     * GET /api/transactions
     */
    public function transactions(): ResponseInterface
    {
        $officeId = $this->currentOfficeId();
        $limit    = max(5, min(200, (int) ($this->request->getGet('limit') ?? 25)));
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $search   = trim((string) ($this->request->getGet('search') ?? ''));
        $type     = trim((string) ($this->request->getGet('type') ?? ''));
        $dateFrom = trim((string) ($this->request->getGet('date_from') ?? ''));
        $dateTo   = trim((string) ($this->request->getGet('date_to') ?? ''));

        $result = $this->dashboardModel->transactionLog(
            $officeId, $search, $type, $dateFrom, $dateTo, $page, $limit
        );

        $totalPages = $result['total'] > 0 ? (int) ceil($result['total'] / $limit) : 1;

        return $this->respondSuccess([
            'transactions' => $result['rows'],
            'total'        => $result['total'],
            'page'         => $page,
            'totalPages'   => $totalPages,
            'limit'        => $limit,
            'search'       => $search,
            'type'         => $type,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
        ], 'Transactions retrieved');
    }
}
