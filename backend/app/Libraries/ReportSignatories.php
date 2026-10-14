<?php

namespace App\Libraries;

use DomainException;

/**
 * The "Prepared by / Verified by / Noted by" block at the bottom of the summary report,
 * kept per office in system_settings (managers change it in Settings → Report Signatories).
 */
class ReportSignatories
{
    private const LABELS = ['Prepared by:', 'Verified by:', 'Noted by:'];

    private const TITLES = ['Project Staff', 'Project Manager', 'Director for Business Operations'];

    /** Names on the Bakery Project's printed inventory form */
    private const BAKERY_NAMES = ['JEAN B. BABSI-ING', 'ERMA D. SANGBAAN', 'JEFTEE BEN B. PINOS-AN'];

    private const MAX_LABEL = 40;

    private const MAX_TEXT = 80;

    /** @return list<array{label: string, name: string, title: string}> always three */
    public static function forOffice(int $officeId): array
    {
        $saved = json_decode((string) get_setting(self::key($officeId), ''), true);
        if (is_array($saved) && count($saved) === 3) {
            return array_map(static fn($row) => [
                'label' => (string) ($row['label'] ?? ''),
                'name'  => (string) ($row['name'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
            ], array_values($saved));
        }

        return self::defaults($officeId);
    }

    /**
     * @param mixed $rows three { label, name, title }
     * @return list<array{label: string, name: string, title: string}>
     */
    public static function save(int $officeId, mixed $rows): array
    {
        if (! is_array($rows) || count($rows) !== 3) {
            throw new DomainException('Send the three signatories: Prepared by, Verified by and Noted by.');
        }

        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            $row   = is_array($row) ? $row : [];
            $entry = [
                'label' => trim((string) ($row['label'] ?? self::LABELS[$i])),
                'name'  => trim((string) ($row['name'] ?? '')),
                'title' => trim((string) ($row['title'] ?? '')),
            ];
            if ($entry['label'] === '') {
                throw new DomainException('Each signatory needs a heading, such as "Prepared by:".');
            }
            if (mb_strlen($entry['label']) > self::MAX_LABEL) {
                throw new DomainException('A heading may be at most ' . self::MAX_LABEL . ' characters.');
            }
            if (mb_strlen($entry['name']) > self::MAX_TEXT || mb_strlen($entry['title']) > self::MAX_TEXT) {
                throw new DomainException('A name or position may be at most ' . self::MAX_TEXT . ' characters.');
            }
            $clean[] = $entry;
        }

        save_setting(self::key($officeId), json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $clean;
    }

    /** @return list<array{label: string, name: string, title: string}> */
    public static function defaults(int $officeId): array
    {
        $names = ['', '', ''];
        if ($officeId > 0) {
            $row = db_connect()->table('user_office_table')->select('user_office_name')->where('user_office_id', $officeId)->get(1)->getRowArray();
            if (strtoupper(trim((string) ($row['user_office_name'] ?? ''))) === 'BAKERY') {
                $names = self::BAKERY_NAMES;
            }
        }

        return array_map(
            static fn($i) => ['label' => self::LABELS[$i], 'name' => $names[$i], 'title' => self::TITLES[$i]],
            [0, 1, 2]
        );
    }

    private static function key(int $officeId): string
    {
        return 'report_signatories_office_' . max(0, $officeId);
    }
}
