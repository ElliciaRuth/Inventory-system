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
                'action_url'   => $this->productLink($item, $name, $levelId),
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
                'action_url'   => $this->productLink($item, $name, $levelId),
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
            $batchName = ($item['batch_no'] ?? '') !== '' ? $item['batch_no'] : "#{$batchId}";
            $qtyLeft   = rtrim(rtrim(number_format((float) ($item['remaining_qty'] ?? 0), 2, '.', ''), '0'), '.');
            $made      = ! empty($item['manufacturing_date']) ? ' Manufactured ' . date('M d, Y', strtotime($item['manufacturing_date'])) . '.' : '';

            // Last few days: a reminder with its own id per day, so it shows as new again each day
            if ($daysLeft <= DashboardModel::EXPIRY_REMINDER_DAYS) {
                $when = match ($daysLeft) {
                    0       => 'expires today',
                    1       => 'expires tomorrow',
                    default => "expires in {$daysLeft} days",
                };

                $notifications[] = [
                    'id'           => 'exp-reminder-' . $batchId . '-' . $daysLeft,
                    'type'         => 'expiring',
                    'severity'     => 'danger',
                    'category'     => 'Expiry Reminder',
                    'title'        => "Reminder: {$name} {$when}",
                    'item'         => $name,
                    'message'      => "Batch {$batchName} ({$qtyLeft} left) {$when}, on {$expDate}. Use or issue it first.{$made}",
                    'badge'        => $daysLeft === 0 ? 'Today' : "{$daysLeft}d left",
                    'created_at'   => date('Y-m-d H:i:s'),
                    'action_url'   => $this->productLink($item, $name, $levelId),
                    'action_label' => 'View Item',
                    'details'      => [
                        'batch_id'           => $batchId,
                        'days_left'          => $daysLeft,
                        'expiration_date'    => $item['expiration_date'] ?? null,
                        'manufacturing_date' => $item['manufacturing_date'] ?? null,
                        'reminder'           => true,
                    ],
                ];
                continue;
            }

            $notifications[] = [
                'id'           => 'exp-' . $batchId . '-' . md5($name),
                'type'         => 'expiring',
                'severity'     => $isDanger ? 'danger' : 'caution',
                'category'     => 'Expiration Alert',
                'title'        => ($isDanger ? 'Critical Expiry: ' : 'Expiring Soon: ') . $name,
                'item'         => $name,
                'message'      => "Batch {$batchName} expires on {$expDate} ({$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . " remaining).{$made}",
                'badge'        => "{$daysLeft}d left",
                'created_at'   => date('Y-m-d H:i:s'),
                'action_url'   => $this->productLink($item, $name, $levelId),
                'action_label' => 'View Item',
                'details'      => [
                    'batch_id'           => $batchId,
                    'days_left'          => $daysLeft,
                    'expiration_date'    => $item['expiration_date'] ?? null,
                    'manufacturing_date' => $item['manufacturing_date'] ?? null,
                ],
            ];
        }

        // 3b. Already expired but still counted as stock: it has to be taken out
        $expired = $this->dashboardModel->expiredInStock($officeId);
        foreach ($expired as $item) {
            $name      = $item['item'] ?? 'Unknown Item';
            $batchId   = (int) ($item['batch_id'] ?? 0);
            $daysAgo   = (int) ($item['days_ago'] ?? 0);
            $batchName = ($item['batch_no'] ?? '') !== '' ? $item['batch_no'] : "#{$batchId}";
            $qtyLeft   = rtrim(rtrim(number_format((float) ($item['remaining_qty'] ?? 0), 2, '.', ''), '0'), '.');
            $expDate   = date('M d, Y', strtotime($item['expiration_date']));
            $ago       = $daysAgo === 1 ? 'yesterday' : "{$daysAgo} days ago";
            $todo      = $levelId >= 2
                ? 'Take it out of stock with an Adjust Out, reason "Expired".'
                : 'Do not use it; tell your custodian so it can be taken out of stock.';

            $notifications[] = [
                'id'           => 'expired-' . $batchId,
                'type'         => 'expiring',
                'severity'     => 'danger',
                'category'     => 'Expired Stock',
                'title'        => "Expired: {$name}",
                'item'         => $name,
                'message'      => "Batch {$batchName} expired {$ago} ({$expDate}) and {$qtyLeft} is still in stock. {$todo}",
                'badge'        => 'Expired',
                'created_at'   => date('Y-m-d H:i:s'),
                'action_url'   => $this->productLink($item, $name, $levelId),
                'action_label' => 'View Item',
                'details'      => [
                    'batch_id'           => $batchId,
                    'days_ago'           => $daysAgo,
                    'remaining_qty'      => (float) ($item['remaining_qty'] ?? 0),
                    'expiration_date'    => $item['expiration_date'] ?? null,
                    'manufacturing_date' => $item['manufacturing_date'] ?? null,
                    'expired'            => true,
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
                    'action_url'   => $this->productLink($item, $name, $levelId),
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
                    // Managers activate applicants in Others Management, technical staff in User Management
                    'action_url'   => $levelId >= 4 ? '/admin' : '/settings',
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
                $waiting   = (int) ($request['pending_items'] ?? 0);

                $notifications[] = [
                    'id'           => 'stockout-pending-' . $requestId,
                    'type'         => 'stockout_request',
                    'severity'     => 'warning',
                    'category'     => 'Stock-Out Approval',
                    'title'        => "New Stock-Out Request #{$requestId} from {$requester}",
                    'item'         => $requester,
                    'message'      => "{$requester} requested {$waiting} item" . ($waiting === 1 ? '' : 's') . ' that ' . ($waiting === 1 ? 'needs' : 'need') . ' your approval.',
                    'badge'        => 'Awaiting Approval',
                    'created_at'   => $request['submitted_at'] ?? $request['created_at'] ?? date('Y-m-d H:i:s'),
                    'action_url'   => '/stockout/pending',
                    'action_label' => 'Review Request',
                    'details'      => [
                        'temp_stockout_id' => $requestId,
                        'requester'        => $requester,
                    ],
                ];
            }
        }

        // 7. Outcome of the user's own stock-out requests (last 14 days): accepted or rejected, with the reason
        $decisions = [];
        if ($levelId <= 3) {
            $decisions = (new StockoutModel())->recentDecisionsFor($this->currentUserId());
            foreach ($decisions as $item) {
                $approved = $item['status'] === 'approved';
                $name     = $item['item_name'] ?: 'Item';
                $qty      = rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.') . ' ' . ($item['unit'] ?: '');
                $by       = $item['decided_by_name'] !== '' ? " by {$item['decided_by_name']}" : '';

                $notifications[] = [
                    'id'           => 'stockout-decision-' . $item['temp_stockout_item_id'],
                    'type'         => 'stockout_decision',
                    'severity'     => $approved ? 'success' : 'danger',
                    'category'     => 'My Stock-Out Request',
                    'title'        => ($approved ? 'Request accepted: ' : 'Request rejected: ') . $name,
                    'item'         => $name,
                    'message'      => $approved
                        ? 'Your request for ' . trim($qty) . " (request #{$item['temp_stockout_id']}) was accepted{$by}."
                        : 'Your request for ' . trim($qty) . " (request #{$item['temp_stockout_id']}) was rejected{$by}. Reason: " . ($item['decision_reason'] ?: 'none given') . '.',
                    'badge'        => $approved ? 'Accepted' : 'Rejected',
                    'created_at'   => $item['decided_at'],
                    'action_url'   => '/stockout/history',
                    'action_label' => 'View Request History',
                    'details'      => [
                        'temp_stockout_id' => (int) $item['temp_stockout_id'],
                        'status'           => $item['status'],
                        'reason'           => $item['decision_reason'],
                    ],
                ];
            }
        }

        // Summary counts
        $counts = [
            'total'        => count($notifications),
            'outOfStock'   => count($outOfStock),
            'lowStock'     => count($lowStock),
            'expiring'     => count($expiring) + count($expired),
            'borrows'      => count($activeBorrows),
            'pendingUsers' => ($levelId >= 3) ? count($this->settingsModel->pendingUsers($officeId, $levelId)) : 0,
            'stockoutRequests' => count($pendingRequests),
            'stockoutDecisions' => count($decisions),
        ];

        // Newest first, so a fresh decision or request shows at the top of the bell
        usort($notifications, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $this->respondSuccess([
            'notifications' => $notifications,
            'counts'        => $counts,
        ], 'Notifications retrieved');
    }

    /**
     * Where a stock alert opens: the product's own stockcard for custodians and managers;
     * staff can't open stockcards, so they get the product catalog searched for it.
     */
    private function productLink(array $item, string $name, int $levelId): string
    {
        $productId = (int) ($item['product_id'] ?? 0);

        if ($levelId >= 2 && $productId > 0) {
            return '/stockcard?item_id=' . $productId;
        }

        return '/products?search=' . rawurlencode($name);
    }
}
