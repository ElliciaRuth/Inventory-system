<?php

namespace App\Controllers;

use App\Models\DashboardModel;
use App\Models\SettingsModel;

class DashboardController extends BaseController
{
    public function __construct(
        private readonly DashboardModel $dashboardModel = new DashboardModel(),
    ) {
    }

    public function index()
    {
        $levelId = (int) (session('user')['level_id'] ?? 0);
        $userOfficeId = (int) (session('user')['user_office_id'] ?? 0);

        // Level 4 (Technical Staff): User management dashboard
        if ($levelId >= 4) {
            $settingsModel = new SettingsModel();
            return view('dashboard/admin', $settingsModel->indexData($userOfficeId, $levelId));
        }

        return view('dashboard/index', $this->dashboardModel->overview($userOfficeId));
    }

    public function transactionLog()
    {
        $userOfficeId = (int) (session('user')['user_office_id'] ?? 0);
        $limit        = max(5, min(200, (int) ($this->request->getGet('limit') ?? 25)));
        $page         = max(1, (int) ($this->request->getGet('page') ?? 1));
        $search       = trim((string) ($this->request->getGet('search') ?? ''));
        $type         = trim((string) ($this->request->getGet('type') ?? ''));
        $dateFrom     = trim((string) ($this->request->getGet('date_from') ?? ''));
        $dateTo       = trim((string) ($this->request->getGet('date_to') ?? ''));

        $result = $this->dashboardModel->transactionLog(
            $userOfficeId, $search, $type, $dateFrom, $dateTo, $page, $limit
        );

        $totalPages = $result['total'] > 0 ? (int) ceil($result['total'] / $limit) : 1;

        return view('dashboard/transaction_log', [
            'transactions' => $result['rows'],
            'total'        => $result['total'],
            'page'         => $page,
            'totalPages'   => $totalPages,
            'limit'        => $limit,
            'search'       => $search,
            'type'         => $type,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
        ]);
    }
}
