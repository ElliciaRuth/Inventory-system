<?php

/**
 * Database helper for deploy/setup-server.ps1 (offline setup). Reads the connection from
 * backend/.env, so the setup and the app always use the same database.
 *
 *   php db-tool.php ping     exit 0 when the server answers
 *   php db-tool.php create   create the database if it doesn't exist
 *   php db-tool.php users    print how many accounts exist (0 when the table is missing)
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$settings = [];
foreach (@file(__DIR__ . '/../backend/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*database\.default\.(hostname|username|password|database|port)\s*=\s*(.*)$/', $line, $m)) {
        $settings[$m[1]] = trim(trim($m[2]), '\'"');
    }
}

$host = $settings['hostname'] ?? '127.0.0.1';
$host = $host === 'localhost' ? '127.0.0.1' : $host;
$name = $settings['database'] ?? 'inventory_system';

if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "Unsupported database name in backend/.env: {$name}\n");
    exit(3);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($host, $settings['username'] ?? 'root', $settings['password'] ?? '', '', (int) ($settings['port'] ?? 3306));
if ($db->connect_errno) {
    fwrite(STDERR, $db->connect_error . "\n");
    exit(2);
}

switch ($argv[1] ?? '') {
    case 'ping':
        exit(0);

    case 'create':
        if (! $db->query("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            fwrite(STDERR, $db->error . "\n");
            exit(4);
        }
        exit(0);

    case 'users':
        $db->select_db($name);
        $result = @$db->query('SELECT COUNT(*) FROM user_table');
        echo $result ? (int) $result->fetch_row()[0] : 0;
        exit(0);
}

fwrite(STDERR, "Usage: php db-tool.php ping|create|users\n");
exit(1);
