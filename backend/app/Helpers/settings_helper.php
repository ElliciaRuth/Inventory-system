<?php

/**
 * get_setting(string $key, mixed $default)
 * Read a value from system_settings. Falls back to $default if not found.
 * Caches results for the duration of the request.
 */
if (! function_exists('get_setting')) {
    function get_setting(string $key, $default = null)
    {
        static $cache = [];

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $row = db_connect()
                ->table('system_settings')
                ->where('setting_key', $key)
                ->get(1)
                ->getRowArray();

            $cache[$key] = $row ? $row['setting_value'] : $default;
        } catch (\Throwable $e) {
            // Table may not exist yet (e.g. before migration)
            $cache[$key] = $default;
        }

        return $cache[$key];
    }
}

/**
 * save_setting(string $key, mixed $value)
 * Upsert a value into system_settings.
 */
if (! function_exists('save_setting')) {
    function save_setting(string $key, $value): void
    {
        $db = db_connect();
        $exists = $db->table('system_settings')
            ->where('setting_key', $key)
            ->countAllResults() > 0;

        if ($exists) {
            $db->table('system_settings')
               ->where('setting_key', $key)
               ->update(['setting_value' => (string) $value]);
        } else {
            $db->table('system_settings')
               ->insert(['setting_key' => $key, 'setting_value' => (string) $value]);
        }
    }
}

