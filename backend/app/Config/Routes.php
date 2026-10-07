<?php

use CodeIgniter\Router\RouteCollection;

/**
 * JSON API consumed by the Vue frontend (../frontend).
 *
 * Every route lives under /api. In production nginx serves the built
 * frontend and forwards /api/* here; in development the Vite dev server
 * proxies /api to `php spark serve`.
 *
 * Access levels: 1 Staff, 2 Custodian, 3 Manager, 4 Technical Staff.
 *
 * @var RouteCollection $routes
 */
$routes->set404Override(static function (): string {
    service('response')->setContentType('application/json');

    return json_encode(['status' => false, 'message' => 'Endpoint not found.']);
});

$routes->group('api', ['namespace' => 'App\Controllers\Api'], static function (RouteCollection $routes): void {
    // ── Public auth endpoints ────────────────────────────────────────────────
    $routes->post('auth/login', 'AuthController::login');
    $routes->get('auth/me', 'AuthController::me');
    $routes->post('auth/logout', 'AuthController::logout');
    $routes->get('auth/register-options', 'AuthController::registerOptions');
    $routes->post('auth/register', 'AuthController::register');
    $routes->post('auth/forgot-password', 'AuthController::forgotPassword');
    $routes->post('auth/reset-password', 'AuthController::resetPassword');

    $routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        // ── Account setup (first-login steps) ────────────────────────────────
        $routes->post('auth/change-password', 'AuthController::changePassword');
        $routes->post('auth/setup-smtp', 'AuthController::setupSmtp', ['filter' => 'level:4']);
        $routes->post('auth/setup-recovery-email', 'AuthController::setupRecoveryEmail', ['filter' => 'level:4']);

        // ── Dashboard, transaction log, notifications ────────────────────────
        $routes->get('dashboard', 'DashboardController::index', ['filter' => 'level:1']);
        $routes->get('transactions', 'DashboardController::transactions', ['filter' => 'level:1']);
        $routes->get('notifications', 'NotificationController::index', ['filter' => 'level:1']);

        // ── Products — level 1 can view; level 2+ can mutate ─────────────────
        $routes->get('products', 'ProductController::index', ['filter' => 'level:1']);
        $routes->get('products/meta', 'ProductController::meta', ['filter' => 'level:1']);
        $routes->get('products/barcodes', 'BarcodeController::finishedProducts', ['filter' => 'level:2']);
        $routes->post('products/barcodes/generate', 'BarcodeController::generateFinishedProductBarcode', ['filter' => 'level:2']);
        $routes->get('products/(:num)', 'ProductController::show/$1', ['filter' => 'level:1']);
        $routes->post('products', 'ProductController::create', ['filter' => 'level:2']);
        $routes->match(['put', 'patch'], 'products/(:num)', 'ProductController::update/$1', ['filter' => 'level:2']);
        $routes->delete('products/(:num)', 'ProductController::delete/$1', ['filter' => 'level:2']);

        // ── Stockcard & stock mutations — level 2+ ───────────────────────────
        $routes->get('stockcard', 'StockController::stockcard', ['filter' => 'level:2']);
        $routes->get('stock/options', 'StockController::options', ['filter' => 'level:2']);
        $routes->post('stock/add', 'StockController::add', ['filter' => 'level:2']);
        $routes->post('stock/edit-transaction', 'StockController::editTransaction', ['filter' => 'level:2']);
        $routes->post('stock/delete-transaction', 'StockController::deleteTransaction', ['filter' => 'level:2']);
        $routes->post('stock/edit-report-cost', 'StockController::editReportCost', ['filter' => 'level:2']);
        $routes->get('stock/copies/(:num)', 'StockController::copies/$1', ['filter' => 'level:1']);

        // ── Batch reports & barcodes — level 2+ ──────────────────────────────
        $routes->get('reports/batches', 'ReportsController::batches', ['filter' => 'level:2']);
        $routes->get('reports/batchlist', 'ReportsController::batchlist', ['filter' => 'level:2']);
        $routes->get('barcode/product/(:num)', 'BarcodeController::product/$1', ['filter' => 'level:2']);
        $routes->get('barcode/batch/(:num)', 'BarcodeController::batch/$1', ['filter' => 'level:2']);
        $routes->get('barcode/lookup', 'BarcodeController::lookupByValue', ['filter' => 'level:2']);

        // ── Exports — level 2+ ───────────────────────────────────────────────
        $routes->get('export/stockcard/options', 'ExportController::stockcardOptions', ['filter' => 'level:2']);
        $routes->match(['get', 'post'], 'export/stockcard', 'ExportController::stockcardDownload', ['filter' => 'level:2']);
        $routes->get('export/summary/options', 'ExportController::summaryOptions', ['filter' => 'level:2']);
        $routes->post('export/summary', 'ExportController::summaryDownload', ['filter' => 'level:2']);

        // ── Settings: reference data, users, user offices — level 2+ ─────────
        // Which record types a level may manage is decided by SettingsModel::definitions().
        $routes->get('settings', 'SettingsController::index', ['filter' => 'level:2']);
        $routes->get('settings/system', 'SettingsController::systemSettings', ['filter' => 'level:2']);
        $routes->post('settings/system', 'SettingsController::saveSystemSettings', ['filter' => 'level:3']);
        $routes->post('settings/users/(:num)/activate', 'SettingsController::activate/$1', ['filter' => 'level:3']);
        $routes->post('settings/users/(:num)/deactivate', 'SettingsController::deactivate/$1', ['filter' => 'level:3']);
        $routes->get('settings/(:segment)/(:num)', 'SettingsController::fetch/$1/$2', ['filter' => 'level:2']);
        $routes->post('settings/(:segment)', 'SettingsController::save/$1', ['filter' => 'level:2']);
        $routes->delete('settings/(:segment)/(:num)', 'SettingsController::delete/$1/$2', ['filter' => 'level:2']);

        // ── Backups — level 2+ (config level 3) ──────────────────────────────
        $routes->get('backups', 'BackupController::index', ['filter' => 'level:2']);
        $routes->post('backups/run', 'BackupController::run', ['filter' => 'level:2']);
        $routes->post('backups/auto', 'BackupController::autoBackup', ['filter' => 'level:2']);
        $routes->get('backups/(:num)/download', 'BackupController::download/$1', ['filter' => 'level:2']);
        $routes->post('backups/restore', 'BackupController::restore', ['filter' => 'level:2']);
        $routes->post('backups/config', 'BackupController::saveConfig', ['filter' => 'level:3']);

        // ── Stock-out — staff (level 1) submit; level 2+ approve ─────────────
        $routes->get('stockout', 'StockoutController::index', ['filter' => 'level:1']);
        $routes->get('stockout/temp', 'StockoutController::tempList', ['filter' => 'level:1']);
        $routes->post('stockout/add-temp', 'StockoutController::addToTemp', ['filter' => 'level:1']);
        $routes->post('stockout/edit-temp/(:num)', 'StockoutController::editTemp/$1', ['filter' => 'level:1']);
        $routes->post('stockout/remove-temp/(:num)', 'StockoutController::removeFromTemp/$1', ['filter' => 'level:1']);
        $routes->post('stockout/submit', 'StockoutController::submitForApproval', ['filter' => 'level:1']);
        $routes->get('stockout/pending', 'StockoutController::pendingRequests', ['filter' => 'level:2']);
        $routes->post('stockout/approve-item/(:num)', 'StockoutController::approveItem/$1', ['filter' => 'level:2']);
        $routes->post('stockout/approve-all/(:num)', 'StockoutController::approveAll/$1', ['filter' => 'level:2']);
        $routes->post('stockout/reject-item/(:num)', 'StockoutController::rejectItem/$1', ['filter' => 'level:2']);
        $routes->post('stockout/edit-pending/(:num)', 'StockoutController::editPendingItem/$1', ['filter' => 'level:2']);
    });
});
