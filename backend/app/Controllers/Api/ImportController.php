<?php

namespace App\Controllers\Api;

use App\Libraries\AuditLog;
use App\Services\StockcardImportService;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * Excel import of Appendix 58 stock cards (custodians and managers).
 * Custodians import into their own office only; deleting the existing inventory first
 * ("replace") needs a manager's password: the manager's own, or a manager of the office
 * typing theirs for a custodian.
 * The upload is previewed first; nothing is written until the preview is committed.
 */
class ImportController extends BaseApiController
{
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    /** Wrong approval passwords allowed per user in 15 minutes */
    private const MAX_PASSWORD_FAILURES = 5;

    /**
     * POST /api/import/stockcards/preview   multipart: file, fallback_office_id?
     */
    public function preview(): ResponseInterface
    {
        if ($error = $this->custodianOrManager()) {
            return $error;
        }

        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return $this->respondError('Choose an Excel file to import.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (strtolower($file->getClientExtension()) !== 'xlsx') {
            return $this->respondError('Only Excel .xlsx files can be imported. In Excel use File → Save As → Excel Workbook (.xlsx).', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->respondError('The file is larger than 20 MB.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = (new StockcardImportService())->preview(
                $file->getTempName(),
                $file->getClientName(),
                $this->currentUserId(),
                (int) ($this->request->getPost('fallback_office_id') ?? 0)
            );
        } catch (Throwable $e) {
            log_message('error', 'Stock card import preview failed: ' . $e->getMessage());

            return $this->respondError('The file could not be read: ' . $this->safeMessage($e), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondSuccess([
            'token' => $result['token'],
            'plan'  => $this->withoutMovements($result['plan']),
        ], 'Preview ready');
    }

    /**
     * One card's header and rows for the correction editor.
     * GET /api/import/stockcards/card?token=…&card=…
     */
    public function card(): ResponseInterface
    {
        if ($error = $this->custodianOrManager()) {
            return $error;
        }

        $card = (new StockcardImportService())->cardRows(
            (string) $this->request->getGet('token'),
            $this->currentUserId(),
            (string) $this->request->getGet('card')
        );

        return $card === null
            ? $this->respondError('This preview has expired or the card was not found. Choose the file again.', [], ResponseInterface::HTTP_GONE)
            : $this->respondSuccess($card, 'Card loaded');
    }

    /**
     * Apply corrections made in the preview and check the file again.
     * POST /api/import/stockcards/revise   { token, edits: [{ card, row|null, field, value }], fallback_office_id? }
     */
    public function revise(): ResponseInterface
    {
        if ($error = $this->custodianOrManager()) {
            return $error;
        }

        $input = $this->input();
        $edits = is_array($input['edits'] ?? null) ? array_values(array_filter($input['edits'], 'is_array')) : [];

        try {
            $plan = (new StockcardImportService())->revise(
                (string) ($input['token'] ?? ''),
                $this->currentUserId(),
                $edits,
                isset($input['fallback_office_id']) ? (int) $input['fallback_office_id'] : null
            );
        } catch (Throwable $e) {
            log_message('error', 'Stock card import revise failed: ' . $e->getMessage());

            return $this->respondError('The corrections could not be applied: ' . $this->safeMessage($e), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $plan === null
            ? $this->respondError('This preview has expired. Choose the file again.', [], ResponseInterface::HTTP_GONE)
            : $this->respondSuccess(['plan' => $this->withoutMovements($plan)], 'Preview updated');
    }

    /**
     * POST /api/import/stockcards/commit   { token, mode: "replace"|"append", password, types: { productKey: type } }
     * Deleting the existing inventory first ("replace") needs the manager's own password.
     */
    public function commit(): ResponseInterface
    {
        if ($error = $this->custodianOrManager()) {
            return $error;
        }

        $input = $this->input();
        $token = (string) ($input['token'] ?? '');
        $mode  = (string) ($input['mode'] ?? '');
        $types = is_array($input['types'] ?? null) ? $input['types'] : [];

        if (! in_array($mode, ['replace', 'append'], true)) {
            return $this->respondError('Choose whether to delete the existing inventory first or keep it.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        $approvedBy = null;
        if ($mode === 'replace') {
            $password = (string) ($input['password'] ?? '');
            $who      = $this->currentLevelId() >= 3 ? 'your' : "a manager's";
            if ($password === '') {
                return $this->respondError("Enter {$who} password to delete the existing inventory.", ['password' => 'Required.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
            // The password typed may be another person's (a manager's): limit the guesses
            $failKey = 'import_pw_fail_' . $this->currentUserId();
            if ((int) cache($failKey) >= self::MAX_PASSWORD_FAILURES) {
                return $this->respondError('Too many wrong passwords. Try again in 15 minutes.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
            }

            $approvedBy = $this->managerApproval($password);
            if ($approvedBy === null) {
                cache()->save($failKey, (int) cache($failKey) + 1, 900);
                log_message('warning', 'Stock card import: wrong password for user #' . $this->currentUserId() . ' on delete-and-import.');
                AuditLog::record('import.password_failed', 'import', null, 'Wrong manager password on delete-and-import; nothing was deleted');

                return $this->respondError(
                    $this->currentLevelId() >= 3 ? 'Incorrect password. Nothing was deleted.' : "That is not the password of an active manager of your office. Nothing was deleted.",
                    ['password' => 'Incorrect password.'],
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $service = new StockcardImportService();
        $plan    = $service->loadPlan($token, $this->currentUserId());
        if ($plan === null) {
            return $this->respondError('This preview has expired. Choose the file again.', [], ResponseInterface::HTTP_GONE);
        }
        if ($error = $this->officeScopeError($plan)) {
            return $error;
        }

        try {
            $result = $service->commit($plan, $mode === 'replace', $types, $this->currentUser() ?? []);
        } catch (DomainException $e) {
            return $this->respondError($e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            log_message('error', 'Stock card import failed: ' . $e->getMessage());

            return $this->respondError('Import failed; nothing was changed. The details were written to the server log.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $service->forgetPlan($token);

        AuditLog::record($mode === 'replace' ? 'import.replace' : 'import.append', 'import', null,
            ($mode === 'replace' ? 'Deleted existing inventory and imported ' : 'Imported ') . 'stock cards from ' . ($plan['file_name'] ?? 'Excel')
            . ': ' . ($result['products_created'] ?? 0) . ' products created, ' . ($result['products_reused'] ?? 0) . ' reused',
            [
                'offices'          => $result['offices'] ?? [],
                'deleted'          => $result['deleted'] ?? null,
                'backups'          => $result['backups'] ?? [],
                'receipts'         => $result['receipts'] ?? 0,
                'issues'           => $result['issues'] ?? 0,
                'adjustments'      => $result['adjustments'] ?? 0,
                'type_overrides'   => $types,
                'approved_by'      => $approvedBy,
            ]
        );

        return $this->respondSuccess($result, 'Import complete');
    }

    /**
     * The reader's own explanations ("This is not an Excel .xlsx workbook", size limits …) are
     * shown; anything else (database errors that describe the schema) stays in the log.
     */
    private function safeMessage(Throwable $e): string
    {
        $fromReader = ($e instanceof DomainException || $e instanceof \RuntimeException)
            && ! $e instanceof \mysqli_sql_exception
            && ! $e instanceof \CodeIgniter\Database\Exceptions\DatabaseException;

        return $fromReader ? $e->getMessage() : 'an unexpected error occurred; the details were written to the server log.';
    }

    private function custodianOrManager(): ?ResponseInterface
    {
        return in_array($this->currentLevelId(), [2, 3], true)
            ? null
            : $this->respondError('Only custodians and managers can import stock cards.', [], ResponseInterface::HTTP_FORBIDDEN);
    }

    /**
     * Who authorised a delete-and-import: the signed-in manager (own password), or for a custodian
     * an active manager of the same office whose password was typed. Null when it matches no one.
     */
    private function managerApproval(string $password): ?string
    {
        if ($this->currentLevelId() >= 3) {
            return $this->currentPasswordMatches($password) ? (string) (session('user')['username'] ?? '') : null;
        }

        $managers = db_connect()->table('user_table u')
            ->select('u.username, u.password')
            ->join('level_of_access l', 'l.lvl_of_access_id = u.lvl_of_access_id')
            ->where('l.lvl_of_access', 3)
            ->where('u.user_activity_id', 1)
            ->where('u.user_office_id', $this->currentOfficeId())
            ->get()->getResultArray();

        foreach ($managers as $manager) {
            if ($this->passwordMatches($password, (string) $manager['password'])) {
                return $manager['username'];
            }
        }

        return null;
    }

    /**
     * Custodians and managers import only into their own office: a file with stock cards for
     * another office is refused (a "replace" import would otherwise delete that office's
     * inventory, which nobody outside it may even see).
     */
    private function officeScopeError(array $plan): ?ResponseInterface
    {
        $own    = $this->currentOfficeId();
        $others = array_values(array_filter(array_map('intval', $plan['summary']['office_ids'] ?? []), static fn ($id) => $id !== $own));
        if ($others === []) {
            return null;
        }

        return $this->respondError(
            'This file has stock cards for another office (' . implode(', ', $plan['summary']['offices'] ?? []) . '). You can only import into your own office; remove those cards or ask that office to import them.',
            ['other_offices' => $others],
            ResponseInterface::HTTP_FORBIDDEN
        );
    }

    /**
     * The preview lists products with counts; the individual movements stay on the server.
     */
    private function withoutMovements(array $plan): array
    {
        if (isset($plan['offices'])) {
            $own             = $this->currentOfficeId();
            $plan['offices'] = array_values(array_filter($plan['offices'], static fn ($o) => (int) $o['id'] === $own));
        }

        $plan['products'] = array_map(static function (array $p) {
            unset($p['movements']);

            return $p;
        }, $plan['products']);

        // Cell positions are only needed on the server
        $plan['cards'] = array_map(static function (array $c) {
            unset($c['layout']);

            return $c;
        }, $plan['cards']);

        return $plan;
    }
}
