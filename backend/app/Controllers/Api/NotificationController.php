<?php

namespace App\Controllers\Api;

use App\Models\DashboardModel;
use App\Models\SettingsModel;
use App\Models\StockoutModel;
use CodeIgniter\HTTP\ResponseInterface;

class NotificationController extends BaseApiController
{
    private DashboardModel $dashboardModel;
    private SettingsModel $settingsModel;

    public function __construct()
    {
        $this->dashboardModel = new DashboardModel();
        $this->settingsModel  = new SettingsModel();
    }

    /**
     * Get aggregate notifications: Out of stock, Low stock, Expiring batches, Borrows, and Pending users.
     * GET /api/notifications
     */
    public function index(): ResponseInterface
    {
        $officeId = $this->currentOfficeId();
        $levelId  = $this->currentLevelId();

        $notifications = [];

        // 1. Critical: Out of Stock
        $outOfStock = $this->dashboardModel->outOfStock($officeId);
        foreach ($outOfStock as $item) {
            $name = $item['item'] ?? 'Unknown Item';
            $notifications[] = [
                'id'           => 'oos-' . md5($name),
                'type'         => 'out_of_stock',
                'severity'     => 'danger',
                'category'     => 'Stock Depletion',
                'title'        => 'Out of Stock: ' . $name,
                'item'         => $name,
                'message'      => 'Inventory is currently at 0 units. Immediate restock order is advised.',
                'badge'        => '0 Units',
                'created_at'   => date('Y-m-d H:i:s'),
                'action_url'   => '/stockcard',
                'action_label' => 'Check Stockcard',
                'details'      => [
                    'stock_left' => 0,
                ],
            ];
        }

        // 2. High Priority: Low Stock (below reorder point)
        $lowStock = $this->dashboardModel->lowStock($officeId);
        foreach ($lowStock as $item) {
            $name     = $item['item'] ?? 'Unknown Item';
            $left     = (int) ($item['stock_left'] ?? 0);
            $reorder  = (int) ($item['re_order_point'] ?? 0);
            $notifications[] = [
                'id'           => 'low-' . md5($name),
                'type'         => 'low_stock',
                'severity'     => 'warning',
                'category'     => 'Low Stock Warning',
                'title'        => 'Low Stock: ' . $name,
                'item'         => $name,
                'message'      => "Only {$left} units left in stock. Reorder threshold is set at {$reorder} units.",
                'badge'        => "{$left} / {$reorder} left",
                'created_at'   => date('Y-m-d H:i:s'),
                'action_url'   => '/stockcard',
                'action_label' => 'View Stockcard',
                'details'      => [
                    'stock_left'      => $left,
                    're_order_point' => $reorder,
                ],
            ];
        }

        // 3. Expiration Warnings: Expiring Soon
        $expiring = $this->dashboardModel->expiringSoon($officeId);
        foreach ($expiring as $item) {
            $name      = $item['item'] ?? 'Unknown Item';
            $batchId   = $item['batch_id'] ?? 0;
            $daysLeft  = (int) ($item['days_left'] ?? 0);
            $danger    = (int) ($item['expiry_danger_days'] ?? 7);
            $expDate   = $item['expiration_date'] ? date('M d, Y', strtotime($item['expiration_date'])) : 'Soon';
            $isDanger  = $daysLeft <= $danger;

            $notifications[] = [
                'id'           => 'exp-' . $batchId . '-' . md5($name),
                'type'         => 'expiring',
                'severity'     => $isDanger ? 'danger' : 'caution',
                'category'     => 'Expiration Alert',
                'title'        => ($isDanger ? 'Critical Expiry: ' : 'Expiring Soon: ') . $name,
                'item'         => $name,
                'message'      => "Batch #{$batchId} expires on {$expDate} ({$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . " remaining).",
                'badge'        => "{$daysLeft}d left",
                'created_at'   => date('Y-m-d H:i:s'),
                'action_url'   => '/products',
                'action_label' => 'View Products',
                'details'      => [
                    'batch_id'        => $batchId,
                    'days_left'       => $daysLeft,
                    'expiration_date' => $item['expiration_date'] ?? null,
                ],
            ];
        }

        // 4. Active Borrows / Lending
        $activeBorrows = $this->dashboardModel->activeBorrows($officeId);
        foreach ($activeBorrows as $item) {
            $name     = $item['item'] ?? 'Unknown Item';
            $borrowed = (int) ($item['net_borrowed'] ?? $item['total_borrowed'] ?? 0);
            $lastDate = ! empty($item['last_borrowed']) ? date('M d, Y', strtotime($item['last_borrowed'])) : 'Recent';

            if ($borrowed > 0) {
                $notifications[] = [
                    'id'           => 'borrow-' . md5($name),
                    'type'         => 'borrow',
                    'severity'     => 'info',
                    'category'     => 'Active Borrow Ledger',
                    'title'        => 'Unreturned Items: ' . $name,
                    'item'         => $name,
                    'message'      => "{$borrowed} unit" . ($borrowed === 1 ? '' : 's') . " currently borrowed and awaiting return. Last borrowed: {$lastDate}.",
                    'badge'        => "{$borrowed} unreturned",
                    'created_at'   => date('Y-m-d H:i:s'),
                    'action_url'   => '/stockcard',
                    'action_label' => 'Record Return',
                    'details'      => [
                        'net_borrowed'  => $borrowed,
                        'last_borrowed' => $item['last_borrowed'] ?? null,
                    ],
                ];
            }
        }

        // 5. Admin Notifications: Pending User Registrations (for Level 3 / Level 4)
        if ($levelId >= 3) {
            $pendingUsers = $this->settingsModel->pendingUsers($officeId, $levelId);
            foreach ($pendingUsers as $user) {
                $userName = $user['name'] ?: $user['username'];
                $userId   = (int) $user['user_id'];
                $office   = $user['user_office_name'] ?? 'Assigned Office';

                $notifications[] = [
                    'id'           => 'user-pending-' . $userId,
                    'type'         => 'user_registration',
                    'severity'     => 'info',
                    'category'     => 'Account Approval',
                    'title'        => 'New Applicant: ' . $userName,
                    'item'         => $userName,
                    'message'      => "Registered account for {$userName} ({$office}) requires administrative activation.",
                    'badge'        => 'Pending Approval',
                    'created_at'   => $user['created_at'] ?? date('Y-m-d H:i:s'),
                    'action_url'   => '/admin',
                    'action_label' => 'Manage Users',
                    'details'      => [
                        'user_id'  => $userId,
                        'username' => $user['username'] ?? '',
                        'office'   => $office,
                    ],
                ];
            }
        }

        // 6. Stock-out requests waiting for approval (custodians / managers)
        $pendingRequests = [];
        if ($levelId >= 2 && $levelId <= 3) {
            $pendingRequests = (new StockoutModel())->pendingRequests($officeId, $levelId);
            foreach ($pendingRequests as $request) {
                $requestId = (int) $request['temp_stockout_id'];
                $requester = $request['requester_name'] ?? 'Staff';

                $notifications[] = [
                    'id'           => 'stockout-pending-' . $requestId,
                    'type'         => 'stockout_request',
                    'severity'     => 'warning',
                    'category'     => 'Stock-Out Approval',
                    'title'        => "Stock-Out Request #{$requestId}",
                    'item'         => $requester,
                    'message'      => "{$requester} submitted a stock-out request that needs approval.",
                    'badge'        => 'Awaiting Approval',
                    'created_at'   => $request['created_at'] ?? date('Y-m-d H:i:s'),
                    'action_url'   => '/stockout/pending',
                    'action_label' => 'Review Request',
                    'details'      => [
                        'temp_stockout_id' => $requestId,
                        'requester'        => $requester,
                    ],
                ];
            }
        }

        // Summary counts
        $counts = [
            'total'        => count($notifications),
            'outOfStock'   => count($outOfStock),
            'lowStock'     => count($lowStock),
            'expiring'     => count($expiring),
            'borrows'      => count($activeBorrows),
            'pendingUsers' => ($levelId >= 3) ? count($this->settingsModel->pendingUsers($officeId, $levelId)) : 0,
            'stockoutRequests' => count($pendingRequests),
        ];

        return $this->respondSuccess([
            'notifications' => $notifications,
            'counts'        => $counts,
        ], 'Notifications retrieved');
    }
}
