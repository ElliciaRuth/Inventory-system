<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Minimal zip reader/writer using only zlib (no ZipArchive), so it works on a stock
 * XAMPP install as well as in Docker. Enough for .xlsx files and backup packages.
 */
class ZipFile
{
    /** @var array<string, string> entry name => contents, in the order added */
    private array $entries = [];

    public function add(string $name, string $contents): void
    {
        $this->entries[$name] = $contents;
    }

    /**
     * The zip archive as a string (deflate-compressed, UTF-8 names).
     */
    public function build(): string
    {
        $out     = '';
        $central = '';
        [$time, $date] = self::dosDateTime(time());

        foreach ($this->entries as $name => $contents) {
            $compressed = gzdeflate($contents, 6);
            $crc        = crc32($contents);
            $offset     = strlen($out);
            $nameLen    = strlen($name);

            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $time, $date, $crc, strlen($compressed), strlen($contents), $nameLen, 0)
                . $name . $compressed;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $time, $date, $crc, strlen($compressed), strlen($contents), $nameLen, 0, 0, 0, 0, 0, $offset)
                . $name;
        }

        $count = count($this->entries);

        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
    }

    /**
     * Every entry of a zip archive (or only those $keep accepts).
     * Unpacked sizes are capped (Config\RequestLimits), so a small "zip bomb" can't
     * expand into gigabytes and exhaust the server's memory.
     *
     * @param callable(string): bool|null $keep
     * @return array<string, string> entry name => contents
     */
    public static function read(string $data, ?callable $keep = null): array
    {
        $limits     = config(\Config\RequestLimits::class);
        $maxEntry   = $limits->maxUnpackedEntryBytes;
        $maxTotal   = $limits->maxUnpackedTotalBytes;
        $unpacked   = 0;

        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('This is not a zip file.');
        }

        $end   = strlen($data) >= $eocd + 20 ? unpack('vdisk/vcdDisk/ventriesHere/ventries/VcdSize/VcdOffset', substr($data, $eocd + 4, 16)) : false;
        if ($end === false) {
            throw new RuntimeException('The zip file is damaged.');
        }
        $pos   = $end['cdOffset'];
        $files = [];

        if ($end['entries'] > $limits->maxArchiveEntries) {
            throw new RuntimeException('The file contains too many entries (more than ' . $limits->maxArchiveEntries . ').');
        }

        for ($i = 0; $i < $end['entries']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02" || strlen($data) < $pos + 46) {
                throw new RuntimeException('The zip file is damaged.');
            }

            $h = unpack(
                'vmadeBy/vversion/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset',
                substr($data, $pos + 4, 42)
            );
            $name = substr($data, $pos + 46, $h['nameLen']);
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];

            if (str_ends_with($name, '/') || ($keep !== null && ! $keep($name))) {
                continue;
            }

            if (strlen($data) < $h['offset'] + 30 || substr($data, $h['offset'], 4) !== "PK\x03\x04") {
                throw new RuntimeException('The zip file is damaged.');
            }
            $local    = unpack('vnameLen/vextraLen', substr($data, $h['offset'] + 26, 4));
            $start    = $h['offset'] + 30 + $local['nameLen'] + $local['extraLen'];
            $compData = substr($data, $start, $h['compSize']);

            // The size the archive claims, checked before unpacking anything
            if ($h['size'] > $maxEntry || $unpacked + $h['size'] > $maxTotal) {
                throw new RuntimeException('The file is too large once unpacked; it may be damaged or crafted to overload the server.');
            }

            $contents = match ($h['method']) {
                0       => $compData,
                // Never inflate more than the remaining allowance, whatever the header says
                8       => @gzinflate($compData, min($maxEntry, $maxTotal - $unpacked) + 1),
                default => false,
            };
            if ($contents === false) {
                throw new RuntimeException("The zip file is damaged, uses unsupported compression or is too large once unpacked ({$name}).");
            }
            if (strlen($contents) > $maxEntry || $unpacked + strlen($contents) > $maxTotal) {
                throw new RuntimeException('The file is too large once unpacked; it may be damaged or crafted to overload the server.');
            }

            $unpacked     += strlen($contents);
            $files[$name] = $contents;
        }

        return $files;
    }

    /**
     * @return array{0: int, 1: int} MS-DOS time and date
     */
    private static function dosDateTime(int $ts): array
    {
        $t = getdate($ts);

        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
