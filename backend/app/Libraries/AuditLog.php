<?php

namespace App\Libraries;

use Throwable;

/**
 * Writes one entry to the append-only audit_log table.
 *
 *   AuditLog::record('stock.receipt', 'product', $productId, 'Received 10 kg Flour', ['batch_no' => ...]);
 *
 * The acting user and IP come from the session/request unless given in $actor.
 * Logging never breaks the action being logged: a failure only goes to the error log.
 */
class AuditLog
{
    /**
     * @param array<string, mixed>      $details  Extra data, e.g. ['before' => [...], 'after' => [...]]
     * @param array<string, mixed>|null $actor    ['id', 'username', 'user_office_id'] when there is no session yet (failed logins)
     */
    public static function record(string $action, string $entity = '', ?int $entityId = null, string $summary = '', array $details = [], ?array $actor = null): void
    {
        try {
            $user = $actor ?? (session('user') ?? []);

            $request = service('request');
            $ip      = method_exists($request, 'getIPAddress') ? (string) $request->getIPAddress() : '';

            db_connect()->table('audit_log')->insert([
                'created_at'     => date('Y-m-d H:i:s'),
                'user_id'        => isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null,
                'username'       => mb_substr((string) ($user['username'] ?? ''), 0, 100),
                'user_office_id' => isset($user['user_office_id']) && (int) $user['user_office_id'] > 0 ? (int) $user['user_office_id'] : null,
                'action'         => mb_substr($action, 0, 60),
                'entity'         => mb_substr($entity, 0, 60),
                'entity_id'      => $entityId,
                'summary'        => mb_substr($summary, 0, 500),
                'details'        => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address'     => mb_substr($ip, 0, 45),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Audit log write failed (' . $action . '): ' . $e->getMessage());
        }
    }
}
