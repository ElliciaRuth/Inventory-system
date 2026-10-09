<?php

namespace App\Libraries;

use RuntimeException;
use SimpleXMLElement;

/**
 * Minimal read-only .xlsx reader.
 *
 * Needs only zlib + SimpleXML (no ZipArchive / PhpSpreadsheet), so it works on a
 * stock XAMPP install as well as in Docker. Reads cell values (formula cells give
 * their last calculated value) and flags numeric cells that use a date format.
 */
class XlsxReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Built-in number formats that display dates/times */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /** @var array<string, string> zip entry name => contents */
    private array $files = [];

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<int, bool> cellXfs index => uses a date format */
    private array $dateStyles = [];

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            // Only the XML parts are needed (skip images and other binaries)
            $this->files = ZipFile::read($data, static fn (string $name) => str_ends_with($name, '.xml') || str_ends_with($name, '.rels'));
        } catch (RuntimeException $e) {
            // Size limits keep their own message; anything else means it isn't a workbook
            if (str_contains($e->getMessage(), 'too large') || str_contains($e->getMessage(), 'too many')) {
                throw $e;
            }
            throw new RuntimeException('This is not an Excel .xlsx workbook. Save it as .xlsx and try again.');
        }
        if (! isset($this->files['xl/workbook.xml'])) {
            throw new RuntimeException('This is not an Excel .xlsx workbook.');
        }

        $this->sharedStrings = $this->readSharedStrings();
        $this->dateStyles    = $this->readDateStyles();
    }

    /**
     * Every sheet in workbook order.
     *
     * @return list<array{name: string, cells: array<int, array<int, array{v: string|float, date: bool}>>}>
     *         cells[row][column], both 1-based
     */
    public function sheets(): array
    {
        $workbook = $this->xml('xl/workbook.xml');
        $rels     = $this->relationships('xl/_rels/workbook.xml.rels');
        $sheets   = [];

        foreach ($workbook->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
            $relId  = (string) $sheet->attributes(self::NS_REL)['id'];
            $target = $rels[$relId] ?? '';
            $path   = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;

            if (! isset($this->files[$path])) {
                continue;
            }

            $sheets[] = [
                'name'  => (string) $sheet['name'],
                'cells' => $this->readCells($path),
            ];
        }

        return $sheets;
    }

    /**
     * Excel serial day number → [year, month, day] (1900 date system).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function serialToDate(float $serial): array
    {
        // Day 60 is Excel's fictional 1900-02-29; serials after it are shifted by one
        $days = (int) floor($serial);
        $base = $days > 59 ? 25569 : 25568;
        $ts   = ($days - $base) * 86400;

        return [(int) gmdate('Y', $ts), (int) gmdate('n', $ts), (int) gmdate('j', $ts)];
    }

    // ── Workbook parts ───────────────────────────────────────────────────────

    private function readCells(string $path): array
    {
        $xml   = $this->xml($path);
        $cells = [];

        foreach ($xml->xpath('//m:sheetData/m:row') ?: [] as $row) {
            foreach ($row->children(self::NS_MAIN)->c as $c) {
                // Elements reached through a namespaced children() call only expose attributes via attributes()
                $attrs = $c->attributes();
                if (! preg_match('/^([A-Z]+)(\d+)$/', (string) $attrs['r'], $m)) {
                    continue;
                }

                $value = $this->cellValue($c, (string) ($attrs['t'] ?? 'n'));
                if ($value === null || $value === '') {
                    continue;
                }

                $style = (int) ($attrs['s'] ?? 0);
                $cells[(int) $m[2]][self::columnIndex($m[1])] = [
                    'v'    => $value,
                    'date' => is_float($value) && ($this->dateStyles[$style] ?? false),
                ];
            }
        }

        return $cells;
    }

    private function cellValue(SimpleXMLElement $c, string $type): string|float|null
    {
        $children = $c->children(self::NS_MAIN);

        if ($type === 'inlineStr') {
            return $this->joinText($children->is);
        }

        if (! isset($children->v)) {
            return null;
        }
        $raw = (string) $children->v;

        return match ($type) {
            's'         => $this->sharedStrings[(int) $raw] ?? '',
            'str', 'e'  => $raw,
            'b'         => $raw === '1' ? 'TRUE' : 'FALSE',
            default     => is_numeric($raw) ? (float) $raw : $raw,
        };
    }

    private function readSharedStrings(): array
    {
        if (! isset($this->files['xl/sharedStrings.xml'])) {
            return [];
        }

        $strings = [];
        foreach ($this->xml('xl/sharedStrings.xml')->xpath('//m:si') ?: [] as $si) {
            $strings[] = $this->joinText($si);
        }

        return $strings;
    }

    /**
     * Text of a rich-text or plain string item, ignoring phonetic hints.
     */
    private function joinText(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        $node->registerXPathNamespace('m', self::NS_MAIN);

        $text = '';
        foreach ($node->xpath('.//m:t[not(ancestor::m:rPh)]') ?: [] as $t) {
            $text .= (string) $t;
        }

        return $text;
    }

    private function readDateStyles(): array
    {
        if (! isset($this->files['xl/styles.xml'])) {
            return [];
        }

        $styles  = $this->xml('xl/styles.xml');
        $custom  = [];
        foreach ($styles->xpath('//m:numFmts/m:numFmt') ?: [] as $fmt) {
            $custom[(int) $fmt['numFmtId']] = (string) $fmt['formatCode'];
        }

        $result = [];
        foreach ($styles->xpath('//m:cellXfs/m:xf') ?: [] as $i => $xf) {
            $id         = (int) ($xf['numFmtId'] ?? 0);
            $result[$i] = in_array($id, self::BUILTIN_DATE_FORMATS, true)
                || (isset($custom[$id]) && self::isDateFormatCode($custom[$id]));
        }

        return $result;
    }

    private static function isDateFormatCode(string $code): bool
    {
        // Drop quoted text, escaped characters and [colour]/[locale] sections first
        $code = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $code);

        return (bool) preg_match('/[dmy]/i', $code);
    }

    /**
     * @return array<string, string> relationship id => target
     */
    private function relationships(string $path): array
    {
        if (! isset($this->files[$path])) {
            return [];
        }

        $rels = [];
        $xml  = new SimpleXMLElement($this->files[$path], LIBXML_NONET | LIBXML_COMPACT);
        foreach ($xml->children() as $rel) {
            $rels[(string) $rel['Id']] = (string) $rel['Target'];
        }

        return $rels;
    }

    private function xml(string $path): SimpleXMLElement
    {
        $xml = new SimpleXMLElement($this->files[$path], LIBXML_NONET | LIBXML_COMPACT);
        $xml->registerXPathNamespace('m', self::NS_MAIN);

        return $xml;
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }
}
