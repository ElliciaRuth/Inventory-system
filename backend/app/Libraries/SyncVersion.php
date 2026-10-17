<?php

namespace App\Libraries;

use Throwable;

/**
 * Change counters the app polls (GET api/sync) to refresh open pages without a reload.
 *
 * Scopes:
 *   office:N  changes made by a user of office N
 *   shared    changes that concern every office: made by technical staff, or a sign-up
 *   global    every change (technical staff watch this one)
 *
 * The counters only say "something changed"; the pages then fetch their data as usual.
 */
class SyncVersion
{
    public const TABLE = 'sync_version';

    /**
     * Scopes an office user watches; technical staff (no office) watch everything.
     *
     * @return list<string>
     */
    public static function watchedScopes(int $officeId): array
    {
        return $officeId > 0 ? ['office:' . $officeId, 'shared'] : ['global'];
    }

    /**
     * Scopes a change touches.
     *
     * @return list<string>
     */
    public static function changedScopes(int $officeId, int $extraOfficeId = 0): array
    {
        $scopes = ['global', $officeId > 0 ? 'office:' . $officeId : 'shared'];
        if ($extraOfficeId > 0 && $extraOfficeId !== $officeId) {
            $scopes[] = 'office:' . $extraOfficeId;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Count one change in each scope. Never throws: a missed bump only delays a refresh.
     *
     * @param list<string> $scopes
     */
    public static function bump(array $scopes): void
    {
        try {
            $db  = db_connect();
            $now = $db->escape(date('Y-m-d H:i:s'));
            foreach ($scopes as $scope) {
                $db->query(
                    'INSERT INTO ' . self::TABLE . ' (scope, version, updated_at) VALUES (?, 1, ' . $now . ')'
                    . ' ON DUPLICATE KEY UPDATE version = version + 1, updated_at = VALUES(updated_at)',
                    [$scope]
                );
            }
        } catch (Throwable $e) {
            log_message('warning', 'Sync version bump failed: ' . $e->getMessage());
        }
    }

    /**
     * The current counters of the given scopes as one string, e.g. "office:2=41;shared=3".
     *
     * @param list<string> $scopes
     */
    public static function read(array $scopes): string
    {
        $versions = array_fill_keys($scopes, 0);
        try {
            $rows = db_connect()->table(self::TABLE)->select('scope, version')->whereIn('scope', $scopes)->get()->getResultArray();
            foreach ($rows as $row) {
                $versions[$row['scope']] = (int) $row['version'];
            }
        } catch (Throwable) {
            // Table missing (migration not run yet): report no changes
        }

        $parts = [];
        foreach ($versions as $scope => $version) {
            $parts[] = $scope . '=' . $version;
        }

        return implode(';', $parts);
    }
}
