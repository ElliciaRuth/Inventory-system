<?php

/**
 * Router for PHP's built-in server, used by `php spark serve` (app/Commands/Serve.php).
 *
 * The built-in server ignores .htaccess, so barcode images are served here with the same
 * headers public/barcodes/.htaccess sets under Apache: even a tampered SVG opened directly
 * can't run scripts with the viewer's session. Anything else in that folder is refused.
 * Every other request goes to CodeIgniter's own rewrite script.
 */

$uri = urldecode(parse_url('http://localhost' . ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '');

if (str_starts_with($uri, '/barcodes/')) {
    $name = substr($uri, strlen('/barcodes/'));
    $file = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'barcodes' . DIRECTORY_SEPARATOR . $name;

    if (preg_match('/^[A-Za-z0-9_\-]+\.svg$/', $name) === 1 && is_file($file)) {
        header('Content-Type: image/svg+xml');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    } else {
        http_response_code(404);
    }

    return true;
}

return require getenv('BSU_CI_REWRITE') ?: __DIR__ . '/vendor/codeigniter4/framework/system/rewrite.php';
