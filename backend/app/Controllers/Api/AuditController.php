<?php

namespace App\Controllers\Api;

use App\Libraries\Privacy;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Read-only view of the append-only audit trail.
 * Managers see their own office; technical staff (level 4) see every office.
 */
class AuditController extends BaseApiController
{
    /**
     * GET /api/audit-logs?search=&action=&date_from=&date_to=&page=&limit=
     */
    public function index(): ResponseInterface
    {
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit    = max(5, min(100, (int) ($this->request->getGet('limit') ?? 25)));
        $search   = trim((string) ($this->request->getGet('search') ?? ''));
        $action   = trim((string) ($this->request->getGet('action') ?? ''));
        $dateFrom = trim((string) ($this->request->getGet('date_from') ?? ''));
        $dateTo   = trim((string) ($this->request->getGet('date_to') ?? ''));

        $db      = db_connect();
        $builder = $db->table('audit_log a')
            ->select('a.*, uot.user_office_name AS office_name')
            ->join('user_office_table uot', 'uot.user_office_id = a.user_office_id', 'left');

        // Managers: their office only (plus failed logins on their office's usernames)
        if ($this->currentLevelId() < 4 && $this->currentOfficeId() > 0) {
            $builder->where('a.user_office_id', $this->currentOfficeId());
        }

        if ($action !== '') {
            // "stock" matches every stock.* action; "stock.receipt" just that one
            str_contains($action, '.') ? $builder->where('a.action', $action) : $builder->like('a.action', $action . '.', 'after');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $builder->where('a.created_at >=', $dateFrom . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $builder->where('a.created_at <=', $dateTo . ' 23:59:59');
        }
        if ($search !== '') {
            // Older entries may hold whole email addresses; the search skips them, so it can't be
            // used to work an address out letter by letter
            $like     = $db->escape('%' . $db->escapeLikeString($search) . '%');
            $pattern  = $db->escape(Privacy::EMAIL_SQL_PATTERN);
            $noEmails = static fn (string $column) => "REGEXP_REPLACE({$column}, {$pattern}, '') LIKE {$like} ESCAPE '!'";
            $builder->groupStart()
                ->where($noEmails('a.summary'), null, false)
                ->orWhere($noEmails('a.username'), null, false)
                ->orLike('a.action', $search)
                ->orLike('a.ip_address', $search)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $rows  = $builder->orderBy('a.audit_id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['details']  = $row['details'] !== null ? Privacy::maskEmailsDeep(json_decode((string) $row['details'], true)) : null;
            $row['summary']  = Privacy::maskEmailsIn((string) $row['summary']);
            $row['username'] = Privacy::maskEmailsIn((string) $row['username']);
        }
        unset($row);

        return $this->respondSuccess([
            'logs'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => max(1, (int) ceil($total / $limit)),
        ], 'Audit log retrieved');
    }
}
