<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeService
{
    // ── Code 39 lookup (kept for inline product barcodes) ──────────────────────

    private const CODE39 = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    // ── Normalise a string so it only contains Code 39-legal characters ────────

    public function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9 .\-\/+$%]/', '-', $value) ?? '';

        return $value !== '' ? $value : 'EMPTY';
    }

    // ── Render an inline Code 39 SVG (used for product barcodes) ──────────────

    public function svg(string $value): string
    {
        $value  = '*' . $this->normalize($value) . '*';
        $narrow = 2;
        $wide   = 5;
        $height = 86;
        $quiet  = 14;
        $x      = $quiet;
        $bars   = '';

        foreach (str_split($value) as $char) {
            $pattern = self::CODE39[$char] ?? self::CODE39['-'];
            foreach (str_split($pattern) as $index => $widthKey) {
                $width = $widthKey === 'w' ? $wide : $narrow;
                if ($index % 2 === 0) {
                    $bars .= '<rect x="' . $x . '" y="10" width="' . $width . '" height="' . $height . '" fill="#111"/>';
                }
                $x += $width;
            }
            $x += $narrow;
        }

        $width = $x + $quiet;
        $label = htmlspecialchars(trim($value, '*'), ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="124" viewBox="0 0 ' . $width . ' 124" role="img" aria-label="Barcode ' . $label . '">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . $bars
            . '<text x="' . ($width / 2) . '" y="116" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" fill="#111">' . $label . '</text>'
            . '</svg>';
    }

    // ── Generate a unique, Code-128-safe barcode value for a batch ────────────
    //    Format: BC-{batchId padded to 6}-{Ymd}-{random 4-char hex}
    //    All characters are alphanumeric + dash — valid for Code 128.

    public function generateBatchValue(int $batchId): string
    {
        $id     = str_pad((string) $batchId, 6, '0', STR_PAD_LEFT);
        $date   = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

        return 'BC-' . $id . '-' . $date . '-' . $random;
    }

    // ── Generate a Code 128 SVG via picqer and save it to /public/barcodes/ ───
    //    Returns the web-accessible path (e.g. "/barcodes/BC-000001-....svg").
    //    If the file already exists it is served directly (cached).

    public function saveBatchBarcode(string $value): string
    {
        $filename  = preg_replace('/[^A-Za-z0-9\-_]/', '_', $value) . '.svg';
        $savePath  = FCPATH . 'barcodes' . DIRECTORY_SEPARATOR . $filename;
        $webPath   = base_url('barcodes/' . $filename);

        if (! file_exists($savePath)) {
            $generator = new BarcodeGeneratorSVG();
            $svg       = $generator->getBarcode($value, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 60);
            file_put_contents($savePath, $svg);
        }

        return $webPath;
    }

    // ── Return the web path for an existing batch barcode (or regenerate) ─────

    public function batchBarcodePath(string $value): string
    {
        return $this->saveBatchBarcode($value);
    }
}
