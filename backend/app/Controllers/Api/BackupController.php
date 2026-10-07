<?php

namespace App\Controllers\Api;

use App\Models\BackupModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Per-office SQL backups. Running, auto-running and restoring backups is
 * limited to levels 2–3 (custodian, manager); the route filter enforces the
 * minimum level and the methods enforce the maximum.
 */
class BackupController extends BaseApiController
{
    private function canRunBackups(): bool
    {
        $levelId = $this->currentLevelId();
        return $levelId >= 2 && $levelId <= 3;
    }

    private function username(): string
    {
        return (string) ($this->currentUser()['username'] ?? '');
    }

    private function officeName(): string
    {
        $officeId = $this->currentOfficeId();
        if ($officeId <= 0) {
            return 'Global';
        }
        $row = db_connect()
            ->table('user_office_table')
            ->where('user_office_id', $officeId)
            ->get()
            ->getRowArray();
        return $row ? $row['user_office_name'] : 'Office #' . $officeId;
    }

    /**
     * Turn a BackupModel ['ok' => bool, 'message' => string, ...] result into a response.
     */
    private function respondResult(array $result): ResponseInterface
    {
        if ($result['ok'] ?? false) {
            return $this->respondSuccess($result, $result['message'] ?? 'Done');
        }
        return $this->respondError($result['message'] ?? 'Operation failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * GET /api/backups
     */
    public function index(): ResponseInterface
    {
        $model  = new BackupModel();
        $config = $model->getConfig();

        return $this->respondSuccess([
            'backups'               => $model->getBackups($this->currentOfficeId()),
            'backup_dir'            => $config['backup_dir'] ?? '',
            'backup_dir_2'          => $config['backup_dir_2'] ?? '',
            'backup_interval_hours' => (int) ($config['backup_interval_hours'] ?? 24),
            'backup_time'           => (string) ($config['backup_time'] ?? '00:00'),
        ], 'Backups retrieved');
    }

    /**
     * POST /api/backups/run
     */
    public function run(): ResponseInterface
    {
        if (! $this->canRunBackups()) {
            return $this->respondError('Access denied.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        return $this->respondResult((new BackupModel())->createBackup(
            $this->currentOfficeId(),
            $this->currentUserId(),
            $this->officeName(),
            $this->username()
        ));
    }

    /**
     * Run a backup if one is due for this office (called after login).
     * POST /api/backups/auto
     */
    public function autoBackup(): ResponseInterface
    {
        if (! $this->canRunBackups()) {
            return $this->respondSuccess(['skipped' => true], 'Automatic backups are not enabled for this account.');
        }

        $model = new BackupModel();

        if (! $model->needsAutoBackup($this->currentOfficeId())) {
            return $this->respondSuccess(['skipped' => true], 'Backup already done today.');
        }

        return $this->respondResult($model->createBackup(
            $this->currentOfficeId(),
            $this->currentUserId(),
            $this->officeName(),
            $this->username()
        ));
    }

    /**
     * GET /api/backups/{id}/download
     */
    public function download(int $id): ResponseInterface
    {
        $backup = (new BackupModel())->getById($id);

        if (! $backup) {
            return $this->respondError('Backup not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        // Ensure the requesting user's office matches the backup's office
        if ((int) $backup['user_office_id'] !== $this->currentOfficeId() && $this->currentLevelId() < 4) {
            return $this->respondError('Access denied.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $path = $backup['backup_filepath'];
        if (! is_file($path)) {
            return $this->respondError('File not found on server.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return $this->response
            ->setHeader('Content-Type', 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $backup['backup_filename'] . '"')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody(file_get_contents($path));
    }

    /**
     * Restore from an existing backup (backup_id) or an uploaded .sql file (multipart sql_file).
     * POST /api/backups/restore
     */
    public function restore(): ResponseInterface
    {
        if (! $this->canRunBackups()) {
            return $this->respondError('Access denied.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $model    = new BackupModel();
        $backupId = (int) ($this->request->getPost('backup_id') ?? $this->input()['backup_id'] ?? 0);

        // Option A: restore from an existing backup ID
        if ($backupId > 0) {
            $backup = $model->getById($backupId);
            if (! $backup) {
                return $this->respondError('Backup not found.', [], ResponseInterface::HTTP_NOT_FOUND);
            }
            if ((int) $backup['user_office_id'] !== $this->currentOfficeId()) {
                return $this->respondError('Office mismatch – cannot restore another office\'s backup.', [], ResponseInterface::HTTP_FORBIDDEN);
            }
            return $this->respondResult($model->restoreFromFile($backup['backup_filepath']));
        }

        // Option B: restore from an uploaded file
        $file = $this->request->getFile('sql_file');
        if (! $file || ! $file->isValid()) {
            return $this->respondError('No valid SQL file uploaded.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (strtolower($file->getExtension()) !== 'sql') {
            return $this->respondError('Only .sql files are accepted.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondResult($model->restoreFromSqlString(file_get_contents($file->getTempName())));
    }

    /**
     * POST /api/backups/config   { backup_dir, backup_dir_2?, backup_interval_hours, backup_time }
     */
    public function saveConfig(): ResponseInterface
    {
        $input         = $this->input();
        $dir           = trim((string) ($input['backup_dir'] ?? ''));
        $dir2          = trim((string) ($input['backup_dir_2'] ?? ''));
        $intervalHours = (int) ($input['backup_interval_hours'] ?? 24);
        $backupTime    = trim((string) ($input['backup_time'] ?? '00:00'));

        if ($dir === '') {
            return $this->respondError('Backup directory (Drive 1) cannot be empty.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($intervalHours < 0 || $intervalHours > 720) {
            return $this->respondError('Interval must be between 0 and 720 hours.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validate HH:MM format
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $backupTime)) {
            $backupTime = '00:00';
        }

        // ── Resolve Drive 1 ──
        if (! str_contains($dir, ':') && ! str_starts_with($dir, '/')) {
            $dir = ROOTPATH . ltrim($dir, '/\\');
        }
        $dir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
            return $this->respondError('Cannot create directory (Drive 1): ' . $dir, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ── Resolve Drive 2 (optional) ──
        if ($dir2 !== '') {
            if (! str_contains($dir2, ':') && ! str_starts_with($dir2, '/')) {
                $dir2 = ROOTPATH . ltrim($dir2, '/\\');
            }
            $dir2 = rtrim($dir2, '/\\') . DIRECTORY_SEPARATOR;
            if (! is_dir($dir2) && ! @mkdir($dir2, 0775, true)) {
                // Non-fatal: save as empty so backup still works on drive 1
                $dir2 = '';
            }
        }

        $config = [
            'backup_dir'            => $dir,
            'backup_dir_2'          => $dir2,
            'backup_interval_hours' => $intervalHours,
            'backup_time'           => $backupTime,
        ];
        (new BackupModel())->saveConfig($config);

        return $this->respondSuccess($config, 'Backup settings updated.');
    }
}
