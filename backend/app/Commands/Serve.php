<?php

namespace App\Commands;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Commands\Server\Serve as FrameworkServe;

/**
 * `php spark serve` with this app's router (backend/dev-router.php) in front of CodeIgniter's.
 * PHP's built-in server ignores .htaccess, so the router sets the headers that
 * public/barcodes/.htaccess, nginx and the Apache template give the barcode images.
 * Commands in App\ are found before the framework's, so this one replaces the built-in serve.
 */
class Serve extends FrameworkServe
{
    public function run(array $params)
    {
        $php  = CLI::getOption('php') ?? PHP_BINARY;
        $host = CLI::getOption('host') ?? 'localhost';
        $port = (int) (CLI::getOption('port') ?? 8080) + $this->portOffset;

        CLI::write('CodeIgniter development server started on http://' . $host . ':' . $port, 'green');
        CLI::write('Press Control-C to stop.');

        // The router hands everything except barcode images to CodeIgniter's own rewrite script
        putenv('BSU_CI_REWRITE=' . (is_file(SYSTEMPATH . 'rewrite.php') ? SYSTEMPATH . 'rewrite.php' : SYSTEMPATH . 'router.php'));

        passthru($this->buildServeCommand($php, $host, $port, FCPATH, ROOTPATH . 'dev-router.php'), $status);

        if ($status !== EXIT_SUCCESS && $this->portOffset < $this->tries) {
            $this->portOffset++;

            $this->run($params);
        }
    }
}
