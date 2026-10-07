<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'DashboardController::index', ['filter' => 'auth']);

$routes->get('login', 'AuthController::index');
$routes->post('login', 'AuthController::attempt');
$routes->get('register', 'AuthController::createAccount');
$routes->post('register', 'AuthController::register');
$routes->get('logout', 'AuthController::logout', ['filter' => 'auth']);
$routes->get('change-password', 'AuthController::changePasswordView', ['filter' => 'auth']);
$routes->post('change-password', 'AuthController::changePassword', ['filter' => 'auth']);

// ── Forgot / Reset password (public – no auth required) ──
$routes->get('forgot-password', 'AuthController::forgotPasswordView');
$routes->post('forgot-password', 'AuthController::forgotPassword');
$routes->get('verify-code', 'AuthController::verifyCodeView');
$routes->post('verify-code', 'AuthController::verifyCodeAndReset');

$routes->group('', ['filter' => 'auth'], static function ($routes): void {

    // ── SMTP setup (level 4 only, enforced in controller) ────────────────
    $routes->get('setup-smtp', 'AuthController::setupSmtpView');
    $routes->post('setup-smtp', 'AuthController::setupSmtp');

    // ── Recovery email setup (level 4 only, first-login step 3) ─────────
    $routes->get('setup-recovery-email', 'AuthController::setupRecoveryEmailView');
    $routes->post('setup-recovery-email', 'AuthController::setupRecoveryEmail');

    // ── Dashboard ────────────────────────────────────────────────────────────
    // (already covered by the root '/' route above)
    $routes->get('transactions', 'DashboardController::transactionLog', ['filter' => 'level:1']);

    // ── Products — level 1 can view list; level 2+ can mutate ─────────────
    $routes->get('products', 'ProductsController::index', ['filter' => 'level:1']);
    $routes->match(['get', 'post'], 'products/create', 'ProductsController::create', ['filter' => 'level:2']);
    $routes->match(['get', 'post'], 'products/edit/(:num)', 'ProductsController::edit/$1', ['filter' => 'level:2']);
    $routes->post('products/delete/(:num)', 'ProductsController::delete/$1', ['filter' => 'level:2']);

    // ── Batch inventory list + barcode — level 2+ only ────────────────────
    $routes->get('batches', 'ReportsController::batches', ['filter' => 'level:2']);
    $routes->get('barcode/product/(:num)', 'BarcodeController::product/$1', ['filter' => 'level:2']);
    $routes->get('barcode/batch/(:num)', 'BarcodeController::batch/$1', ['filter' => 'level:2']);
    $routes->get('barcode/lookup', 'BarcodeController::lookupByValue', ['filter' => 'level:2']);

    // ── Finished Product Barcodes page ────────────────────────────────────────
    $routes->get('products/barcodes', 'BarcodeController::finishedProducts', ['filter' => 'level:2']);
    $routes->post('products/barcodes/generate', 'BarcodeController::generateFinishedProductBarcode', ['filter' => 'level:2']);


    // ── Stockcard — level 2+ only ────────────────────────────────────────────
    $routes->get('stockcard', 'InventoryController::stockcard', ['filter' => 'level:2']);

    // ── Stock mutations — level 2+ ───────────────────────────────────────────
    $routes->match(['get', 'post'], 'stock/add', 'InventoryController::addStock', ['filter' => 'level:2']);
    $routes->post('stock/edit-transaction', 'InventoryController::editTransaction', ['filter' => 'level:2']);
    $routes->post('stock/delete-transaction', 'InventoryController::deleteTransaction', ['filter' => 'level:2']);
    $routes->post('stock/edit-report-cost', 'InventoryController::editReportCost', ['filter' => 'level:2']);
    $routes->get('stock/copies/(:num)', 'InventoryController::getCopies/$1', ['filter' => 'level:1']);
    $routes->get('batchlist', 'ReportsController::batchlist', ['filter' => 'level:2']);

    // ── Exports — level 2+ ───────────────────────────────────────────────────
    $routes->get('export/stockcard',  'ExportController::stockcardForm', ['filter' => 'level:2']);
    $routes->post('export/stockcard', 'ExportController::stockcardDownload', ['filter' => 'level:2']);
    $routes->get('export/summary',    'ExportController::summaryForm', ['filter' => 'level:2']);
    $routes->post('export/summary',   'ExportController::summaryDownload', ['filter' => 'level:2']);

    // ── Settings — level 2+ ──────────────────────────────────────────────────
    $routes->get('settings', 'SettingsController::index', ['filter' => 'level:2']);
    $routes->get('settings/fetch/(:segment)/(:num)', 'SettingsController::fetch/$1/$2', ['filter' => 'level:2']);
    $routes->post('settings/save/(:segment)', 'SettingsController::save/$1', ['filter' => 'level:2']);
    $routes->post('settings/delete/(:segment)/(:num)', 'SettingsController::delete/$1/$2', ['filter' => 'level:2']);
    $routes->post('settings/activate/(:num)', 'SettingsController::activate/$1', ['filter' => 'level:3']);
    $routes->post('settings/deactivate/(:num)', 'SettingsController::deactivate/$1', ['filter' => 'level:3']);

    // ── Backup system — level 2+ ─────────────────────────────────────────────
    $routes->get('settings/backup/list',            'BackupController::index',       ['filter' => 'level:2']);
    $routes->post('settings/backup/run',            'BackupController::run',         ['filter' => 'level:2']);
    $routes->post('settings/backup/auto',           'BackupController::autoBackup',  ['filter' => 'level:2']);
    $routes->get('settings/backup/download/(:num)', 'BackupController::download/$1', ['filter' => 'level:2']);
    $routes->post('settings/backup/restore',        'BackupController::restore',     ['filter' => 'level:2']);
    $routes->post('settings/backup/config',         'BackupController::saveConfig',  ['filter' => 'level:3']);

    // ── Stock-out — staff (level 1) can submit; level 2+ can approve ────────
    $routes->get('stockout', 'StockoutController::index', ['filter' => 'level:1']);
    $routes->get('stockout/temp', 'StockoutController::tempList', ['filter' => 'level:1']);
    $routes->post('stockout/add-temp', 'StockoutController::addToTemp', ['filter' => 'level:1']);
    $routes->post('stockout/edit-temp/(:num)', 'StockoutController::editTemp/$1', ['filter' => 'level:1']);
    $routes->post('stockout/remove-temp/(:num)', 'StockoutController::removeFromTemp/$1', ['filter' => 'level:1']);
    $routes->post('stockout/submit', 'StockoutController::submitForApproval', ['filter' => 'level:1']);
    // Approval & management — level 2+ only
    $routes->get('stockout/pending', 'StockoutController::pendingRequests', ['filter' => 'level:2']);
    $routes->post('stockout/approve-item/(:num)', 'StockoutController::approveItem/$1', ['filter' => 'level:2']);
    $routes->post('stockout/approve-all/(:num)', 'StockoutController::approveAll/$1', ['filter' => 'level:2']);
    $routes->post('stockout/reject-item/(:num)', 'StockoutController::rejectItem/$1', ['filter' => 'level:2']);
    $routes->post('stockout/edit-pending/(:num)', 'StockoutController::editPendingItem/$1', ['filter' => 'level:2']);
});

/*
 * --------------------------------------------------------------------
 * RESTful API Routes (Decoupled Vue 3 Frontend Integration)
 * --------------------------------------------------------------------
 */
$routes->group('api', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    // Auth endpoints
    $routes->post('auth/login', 'AuthController::login');
    $routes->get('auth/me', 'AuthController::me');
    $routes->post('auth/logout', 'AuthController::logout');
    $routes->get('auth/register-options', 'AuthController::registerOptions');
    $routes->post('auth/register', 'AuthController::register');

    // Dashboard endpoints
    $routes->get('dashboard', 'DashboardController::index');
    $routes->get('dashboard/admin', 'DashboardController::admin');
    $routes->get('transactions', 'DashboardController::transactions');

    // Admin & User Management endpoints
    $routes->get('admin/data', 'AdminController::data');
    $routes->post('admin/users/activate/(:num)', 'AdminController::activateUser/$1');
    $routes->post('admin/users/deactivate/(:num)', 'AdminController::deactivateUser/$1');
    $routes->delete('admin/records/(:segment)/(:num)', 'AdminController::deleteRecord/$1/$2');
    $routes->post('admin/save/(:segment)', 'AdminController::saveRecord/$1');

    // Stock & Inventory endpoints
    $routes->get('stockcard', 'StockController::stockcard');
    $routes->get('stock/options', 'StockController::options');
    $routes->post('stock/add', 'StockController::add');
    $routes->post('stock/edit-transaction', 'StockController::editTransaction');
    $routes->post('stock/delete-transaction', 'StockController::deleteTransaction');

    // Product Resource and Meta
    $routes->get('products/meta', 'ProductController::meta');
    $routes->resource('products', ['controller' => 'ProductController']);

    // Export endpoints
    $routes->get('export/stockcard/options', '\App\Controllers\ExportController::stockcardOptions');
    $routes->match(['get', 'post'], 'export/stockcard', '\App\Controllers\ExportController::stockcardDownload');

    // Notification endpoints
    $routes->get('notifications', 'NotificationController::index');
});

