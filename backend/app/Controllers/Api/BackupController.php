<?php

namespace App\Controllers\Api;

use App\Models\BackupModel;
use App\Services\BackupPackageService;
use App\Libraries\AuditLog;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;

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

        // Server file paths stay on the server
        $backups = array_map(
            static fn (array $b) => array_diff_key($b, array_flip(['backup_filepath', 'backup_filepath_2'])),
            $model->getBackups($this->currentOfficeId())
        );

        // What a package can contain, for the "Create Backup" choices
        $sections = [];
        foreach (BackupPackageService::SECTIONS as $key => $section) {
            $sections[] = ['key' => $key] + $section;
        }

        return $this->respondSuccess([
            'backups'               => $backups,
            'sections'              => $sections,
            'backup_dir'            => $config['backup_dir'] ?? '',
            'backup_dir_2'          => $config['backup_dir_2'] ?? '',
            'backup_interval_hours' => (int) ($config['backup_interval_hours'] ?? 24),
            'backup_time'           => (string) ($config['backup_time'] ?? '00:00'),
        ], 'Backups retrieved');
    }

    /**
     * Create a backup package.
     * POST /api/backups/run   { sections?: string[], password?: string }
     */
    public function run(): ResponseInterface
    {
        if (! $this->canRunBackups()) {
            return $this->respondError('Access denied.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $input    = $this->input();
        $sections = is_array($input['sections'] ?? null) ? array_map('strval', $input['sections']) : [];
        $password = (string) ($input['password'] ?? '');

        if ($password !== '' && mb_strlen($password) < 8) {
            return $this->respondError('Use a password of at least 8 characters.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($sections !== [] && (new BackupPackageService())->withRequired($sections) === []) {
            return $this->respondError('Choose at least one section to back up.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = (new BackupModel())->createBackup(
            $this->currentOfficeId(),
            $this->currentUserId(),
            $this->officeName(),
            $this->username(),
            $sections,
            $password !== '' ? $password : null
        );
        if ($result['ok'] ?? false) {
            AuditLog::record('backup.created', 'backup', null, 'Backup created: ' . ($result['filename'] ?? ''), [
                'sections'  => $sections,
                'protected' => $password !== '',
            ]);
        }

        return $this->respondResult($result);
    }

    /**
     * Check a backup before restoring: what it contains, whose it is, and that it is intact.
     * POST /api/backups/inspect   multipart file | backup_id, password?
     */
    public function inspect(): ResponseInterface
    {
        if (! $this->canRunBackups()) {
            return $this->respondError('Access denied.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        [$bytes, $name, $error] = $this->backupBytes();
        if ($error) {
            return $error;
        }

        if (str_ends_with(strtolower($name), '.sql')) {
            return $this->respondSuccess(['legacy' => true, 'file_name' => $name], 'Older .sql backup');
        }

        try {
            $info = (new BackupPackageService())->inspect($bytes, $this->password(), $this->currentOfficeId());
        } catch (DomainException $e) {
            return $this->respondError($e->getMessage(), ['password_required' => $e->getCode() === 401], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondSuccess(['legacy' => false, 'file_name' => $name] + $info, 'Backup checked');
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
        if (! is_file($path) && ! empty($backup['backup_filepath_2']) && is_file($backup['backup_filepath_2'])) {
            $path = $backup['backup_filepath_2']; // Drive 1 copy missing: serve the mirror
        }
        if (! is_file($path)) {
            return $this->respondError('File not found on server.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return $this->response
            ->setHeader('Content-Type', str_ends_with($path, '.zip') ? 'application/zip' : 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $backup['backup_filename'] . '"')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody(file_get_contents($path));
    }

    /**
     * Restore a saved backup (backup_id) or an uploaded file (multipart file): a package
     * (.zip / .bsubackup, with the chosen sections) or an older .sql backup.
     * A safety backup of the current data is always made first.
     * POST /api/backups/restore   file | backup_id, password?, sections[]?, confirm = "yes"
     */
    public function restore(): ResponseInterface
    {
        if (! $this->canRunBackups()) {
            return $this->respondError('Access denied.', [], ResponseInterface::HTTP_FORBIDDEN);
        }
        if (($this->field('confirm') ?? '') !== 'yes') {
            return $this->respondError('Confirm the restore first.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        [$bytes, $name, $error] = $this->backupBytes();
        if ($error) {
            return $error;
        }

        $model   = new BackupModel();
        $service = new BackupPackageService();
        $legacy  = str_ends_with(strtolower($name), '.sql');

        // Check the package (password, integrity, office) before touching anything
        if (! $legacy) {
            try {
                $service->inspect($bytes, $this->password(), $this->currentOfficeId());
            } catch (DomainException $e) {
                return $this->respondError($e->getMessage(), ['password_required' => $e->getCode() === 401], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $safety = $model->createBackup($this->currentOfficeId(), $this->currentUserId(), $this->officeName(), $this->username() . ' (before restore)');
        if (! ($safety['ok'] ?? false)) {
            return $this->respondError('The safety backup failed, so nothing was restored: ' . ($safety['message'] ?? ''), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($legacy) {
            $result = $model->restoreFromSqlString($bytes);
            $result['message'] = ($result['message'] ?? '') . ' A safety backup was saved first (' . $safety['filename'] . ').';
            if ($result['ok'] ?? false) {
                AuditLog::record('backup.restored', 'backup', null, "Restored older .sql backup {$name}", ['safety_backup' => $safety['filename']]);
            }

            return $this->respondResult($result);
        }

        $sections = $this->field('sections');
        $sections = is_array($sections) ? array_map('strval', $sections) : array_filter(explode(',', (string) $sections));

        try {
            $counts = $service->restore($bytes, $this->password(), $sections, $this->currentOfficeId(), $this->currentUserId(), $this->currentLevelId());
        } catch (DomainException $e) {
            return $this->respondError($e->getMessage(), ['password_required' => $e->getCode() === 401], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            log_message('error', 'Backup restore failed: ' . $e->getMessage());

            return $this->respondError('Restore failed; nothing was changed. The details were written to the server log.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        AuditLog::record('backup.restored', 'backup', null, "Restored backup {$name}", [
            'sections'      => $sections,
            'counts'        => $counts,
            'safety_backup' => $safety['filename'],
        ]);

        return $this->respondSuccess([
            'counts' => $counts,
            'safety' => $safety['filename'],
        ], 'Restore completed. A safety backup of the data before the restore was saved as ' . $safety['filename'] . '.');
    }

    /**
     * The backup to inspect/restore: an uploaded file or a saved backup of this office.
     *
     * @return array{0: string, 1: string, 2: ResponseInterface|null} bytes, file name, error response
     */
    private function backupBytes(): array
    {
        $backupId = (int) ($this->field('backup_id') ?? 0);

        if ($backupId > 0) {
            $backup = (new BackupModel())->getById($backupId);
            if (! $backup) {
                return ['', '', $this->respondError('Backup not found.', [], ResponseInterface::HTTP_NOT_FOUND)];
            }
            if ((int) $backup['user_office_id'] !== $this->currentOfficeId()) {
                return ['', '', $this->respondError('Office mismatch – cannot restore another office\'s backup.', [], ResponseInterface::HTTP_FORBIDDEN)];
            }
            $path = is_file($backup['backup_filepath']) ? $backup['backup_filepath'] : (string) ($backup['backup_filepath_2'] ?? '');
            if ($path === '' || ! is_file($path)) {
                return ['', '', $this->respondError('The backup file is missing from the backup folder.', [], ResponseInterface::HTTP_NOT_FOUND)];
            }

            return [(string) file_get_contents($path), $backup['backup_filename'], null];
        }

        $file = $this->request->getFile('file') ?? $this->request->getFile('sql_file');
        if (! $file || ! $file->isValid()) {
            return ['', '', $this->respondError('Choose a backup file.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)];
        }
        $ext = strtolower($file->getClientExtension());
        if ($ext === 'sql') {
            // An uploaded .sql file would be run against the database as it is: anyone able to
            // restore could run any SQL (create admin accounts, read other offices, write files).
            // Older .sql backups saved on this server can still be restored from the list.
            return ['', '', $this->respondError(
                'Uploaded .sql files cannot be restored. Restore an older .sql backup from the backup list, or ask the technical staff to import it.',
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            )];
        }
        if (! in_array($ext, ['zip', 'bsubackup'], true)) {
            return ['', '', $this->respondError('Choose a backup file (.zip or .bsubackup).', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)];
        }

        return [(string) file_get_contents($file->getTempName()), $file->getClientName(), null];
    }

    /** A field from a multipart form or a JSON body */
    private function field(string $name): mixed
    {
        return $this->request->getPost($name) ?? ($this->input()[$name] ?? null);
    }

    private function password(): ?string
    {
        $password = (string) ($this->field('password') ?? '');

        return $password !== '' ? $password : null;
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

        // Relative folders are stored as entered (BackupModel resolves them against
        // the backend root), so the config keeps working if the app moves to another PC.
        $dir = rtrim($dir, '/\\') . '/';
        // Location rules first, so a refused folder isn't even created
        if ($error = $this->unsafeBackupDirectory($dir)) {
            return $this->respondError($error . ' (Drive 1)', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (! $this->ensureDirectory($dir)) {
            return $this->respondError('Cannot create directory (Drive 1): ' . $dir, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Drive 2 is optional: if it can't be created, save it empty so backups still go to drive 1
        if ($dir2 !== '') {
            $dir2 = rtrim($dir2, '/\\') . '/';
            if ($error = $this->unsafeBackupDirectory($dir2)) {
                return $this->respondError($error . ' (Drive 2)', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (! $this->ensureDirectory($dir2)) {
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

    /**
     * Create the folder if needed. Relative paths are relative to the backend root.
     */
    private function ensureDirectory(string $dir): bool
    {
        return is_dir($this->absolutePath($dir)) || @mkdir($this->absolutePath($dir), 0775, true);
    }

    private function absolutePath(string $dir): string
    {
        return (str_contains($dir, ':') || str_starts_with($dir, '/') || str_starts_with($dir, '\\')) ? $dir : ROOTPATH . ltrim($dir, '/\\');
    }

    private function unsafeBackupDirectory(string $dir): ?string
    {
        return BackupModel::unsafeDirectory($this->absolutePath($dir));
    }
}
