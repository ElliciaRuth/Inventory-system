<?php

namespace App\Controllers\Api;

use App\Libraries\AuditLog;
use App\Models\SettingsModel;
use App\Models\UserModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reference data (entities, units, references, product types, offices),
 * user accounts and user offices. Which types a user may touch depends on
 * their level — see SettingsModel::definitions().
 */
class SettingsController extends BaseApiController
{
    public function __construct(
        private readonly SettingsModel $settingsModel = new SettingsModel(),
    ) {
    }

    /**
     * Definitions and records visible to the current level, plus pending users.
     * GET /api/settings
     */
    public function index(): ResponseInterface
    {
        return $this->respondSuccess(
            $this->settingsModel->indexData($this->currentOfficeId(), $this->currentLevelId()),
            'Settings retrieved'
        );
    }

    /**
     * GET /api/settings/{type}/{id}
     */
    public function fetch(string $type, int $id): ResponseInterface
    {
        if ($this->definitionFor($type) === null) {
            return $this->unknownType();
        }
        if ($error = $this->recordAccessError($type, $id)) {
            return $error;
        }

        $record = $this->settingsModel->fetchRecord($type, $id);
        if ($record === []) {
            return $this->respondError('Record not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return $this->respondSuccess($record, 'Record retrieved');
    }

    /**
     * Create (id = 0) or update a record.
     * POST /api/settings/{type}
     */
    public function save(string $type): ResponseInterface
    {
        $definition = $this->definitionFor($type);
        if ($definition === null) {
            return $this->unknownType();
        }

        $input = $this->input();
        $id    = (int) ($input['id'] ?? 0);

        $payload = [];
        foreach ($definition['fields'] as $field) {
            $payload[$field] = $input[$field] ?? null;
        }

        if ($id > 0 && ($error = $this->recordAccessError($type, $id))) {
            return $error;
        }

        if ($type === 'users') {
            if ($id <= 0) {
                return $this->respondError('Users must create their own accounts via registration.', [], ResponseInterface::HTTP_FORBIDDEN);
            }
            return $this->audited($type, $id, $payload, $this->saveUser($id, $payload));
        }

        if ($type === 'user_office_table') {
            return $this->audited($type, $id, $payload, $this->saveUserOffice($id, $payload));
        }

        $payload = $this->sanitizePayload($payload);

        if ($this->hasEmptyRequiredText($payload)) {
            return $this->respondError('Please fill in the required fields.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settingsModel->saveRecord($type, $id, $payload, $this->currentOfficeId());

        return $this->audited($type, $id, $payload, $this->respondSuccess(
            null,
            $id > 0 ? 'Record updated successfully.' : 'Record created successfully.',
            $id > 0 ? ResponseInterface::HTTP_OK : ResponseInterface::HTTP_CREATED
        ));
    }

    /**
     * Records a successful settings save in the audit trail (passwords never logged) and passes the response on.
     */
    private function audited(string $type, int $id, array $payload, ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() < 300) {
            unset($payload['password']);
            $label = $this->typeLabel($type);
            AuditLog::record(
                $id > 0 ? 'settings.updated' : 'settings.created',
                $type,
                $id > 0 ? $id : null,
                ($id > 0 ? 'Updated ' : 'Added ') . $label . ': ' . $this->recordName($payload),
                ['values' => $payload]
            );
        }

        return $response;
    }

    private function recordName(array $values): string
    {
        foreach (['username', 'name', 'reference', 'unit', 'entity', 'type', 'office_name', 'user_office_name'] as $key) {
            if (! empty($values[$key])) {
                return (string) $values[$key];
            }
        }

        return 'record';
    }

    /**
     * DELETE /api/settings/{type}/{id}
     */
    public function delete(string $type, int $id): ResponseInterface
    {
        if ($this->definitionFor($type) === null) {
            return $this->unknownType();
        }
        if ($error = $this->recordAccessError($type, $id)) {
            return $error;
        }

        if ($type === 'users' && $this->currentUserId() === $id) {
            return $this->respondError('You cannot delete the currently logged-in user.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($type === 'users' && $this->settingsModel->isAdminAccount($id)) {
            return $this->respondError('The admin account cannot be deleted.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($type === 'users' && $this->currentLevelId() === 3) {
            return $this->respondError('Use deactivate instead of delete for users.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $definition = $this->definitionFor($type);
        $before     = db_connect()->table($definition['table'] ?? $type)->where($definition['pk'] ?? 'id', $id)->get(1)->getRowArray() ?? [];
        unset($before['password'], $before['password_reset_token']);

        try {
            $this->settingsModel->deleteRecord($type, $id);
        } catch (\DomainException $e) {
            return $this->respondError('Delete failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            // Database errors stay in the log; they can describe the schema
            log_message('error', "Settings delete of {$type} #{$id} failed: " . $e->getMessage());

            return $this->respondError('Delete failed: the record is still used elsewhere in the system.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLog::record('settings.deleted', $type, $id,
            'Deleted ' . $this->typeLabel($type) . ': ' . $this->recordName($before),
            ['deleted' => $before]
        );

        return $this->respondSuccess(null, 'Record deleted successfully.');
    }

    /**
     * POST /api/settings/users/{id}/activate
     */
    public function activate(int $id): ResponseInterface
    {
        if ($error = $this->userAccessError($id)) {
            return $error;
        }

        $this->settingsModel->activateUser($id);
        AuditLog::record('user.activated', 'users', $id, 'Activated account of ' . $this->usernameOf($id));
        return $this->respondSuccess(null, 'User activated successfully.');
    }

    /**
     * POST /api/settings/users/{id}/deactivate
     */
    public function deactivate(int $id): ResponseInterface
    {
        if ($error = $this->userAccessError($id)) {
            return $error;
        }

        if ($this->currentUserId() === $id) {
            return $this->respondError('You cannot deactivate the currently logged-in user.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->settingsModel->isAdminAccount($id)) {
            return $this->respondError('The admin account cannot be deactivated.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settingsModel->deactivateUser($id);
        AuditLog::record('user.deactivated', 'users', $id, 'Deactivated account of ' . $this->usernameOf($id));
        return $this->respondSuccess(null, 'User deactivated successfully.');
    }

    /**
     * Global expiry thresholds.
     * GET /api/settings/system
     */
    public function systemSettings(): ResponseInterface
    {
        return $this->respondSuccess([
            'expiry_warning_days' => (int) get_setting('expiry_warning_days', 30),
            'expiry_danger_days'  => (int) get_setting('expiry_danger_days', 7),
        ], 'System settings retrieved');
    }

    /**
     * POST /api/settings/system
     */
    public function saveSystemSettings(): ResponseInterface
    {
        $input       = $this->input();
        $warningDays = (int) ($input['expiry_warning_days'] ?? 30);
        $dangerDays  = (int) ($input['expiry_danger_days'] ?? 7);

        if ($warningDays < 1 || $warningDays > 365) {
            return $this->respondError('Warning days must be between 1 and 365.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($dangerDays < 1 || $dangerDays >= $warningDays) {
            return $this->respondError('Danger days must be between 1 and less than warning days.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $before = ['expiry_warning_days' => (int) get_setting('expiry_warning_days', 30), 'expiry_danger_days' => (int) get_setting('expiry_danger_days', 7)];
        save_setting('expiry_warning_days', $warningDays);
        save_setting('expiry_danger_days', $dangerDays);
        AuditLog::record('settings.expiry_thresholds', 'system_settings', null,
            "Expiry thresholds set to warn at {$warningDays} days, danger at {$dangerDays} days",
            ['before' => $before, 'after' => ['expiry_warning_days' => $warningDays, 'expiry_danger_days' => $dangerDays]]
        );

        return $this->respondSuccess([
            'expiry_warning_days' => $warningDays,
            'expiry_danger_days'  => $dangerDays,
        ], 'Inventory settings saved.');
    }

    /**
     * Definition for a type the current level may manage, or null.
     */
    private function definitionFor(string $type): ?array
    {
        try {
            return $this->settingsModel->definition($type, $this->currentLevelId());
        } catch (PageNotFoundException) {
            return null;
        }
    }

    private function typeLabel(string $type): string
    {
        return [
            'users'             => 'user',
            'entity_table'      => 'entity',
            'unit_table'        => 'unit',
            'reference_table'   => 'reference',
            'type_of_product'   => 'product type',
            'office_table'      => 'office',
            'user_office_table' => 'user office',
        ][$type] ?? $type;
    }

    private function usernameOf(int $userId): string
    {
        $row = db_connect()->table('user_table')->select('username')->where('user_id', $userId)->get(1)->getRowArray();

        return '"' . ($row['username'] ?? "user #{$userId}") . '"';
    }

    private function unknownType(): ResponseInterface
    {
        return $this->respondError('Unknown resource type or access denied.', [], ResponseInterface::HTTP_NOT_FOUND);
    }

    private function saveUser(int $id, array $payload): ResponseInterface
    {
        $payload['name']             = $this->cleanName((string) ($payload['name'] ?? ''));
        $payload['username']         = trim((string) ($payload['username'] ?? ''));
        $payload['email']            = trim((string) ($payload['email'] ?? ''));
        $currentEmail                = (string) ((new UserModel())->find($id)['email'] ?? '');
        // The form only ever holds the masked address. Managers can't change an email at all
        // (the owner does, confirmed with a code); technical staff may type a new one in full.
        if ($this->currentLevelId() < 4 || str_contains($payload['email'], '*')) {
            $payload['email'] = $currentEmail;
        }
        $payload['lvl_of_access_id'] = (int) ($payload['lvl_of_access_id'] ?? 0);
        $payload['user_office_id']   = (int) ($payload['user_office_id'] ?? $this->currentOfficeId());

        if ($payload['username'] === '' || $payload['lvl_of_access_id'] <= 0 || $payload['user_office_id'] <= 0) {
            return $this->respondError('Please fill in the required user fields.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($payload['username']) < 3 || mb_strlen($payload['username']) > 50) {
            return $this->respondError('Username must be 3 to 50 characters long.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($payload['email'] !== '' && ! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->respondError('Please enter a valid email address.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($error = $this->nameError($payload['name'], 'Full name', allowComma: true)) {
            return $this->respondError($error, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The role and office handed out: real rows, and for a manager nothing above their own
        // role and nothing outside their own office (see userAccessError for whom they may edit)
        $newLevel = $this->accessLevelOf($payload['lvl_of_access_id']);
        if ($newLevel < 1 || ! $this->userOfficeExists($payload['user_office_id'])) {
            return $this->respondError('Choose a valid level of access and user office.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->currentLevelId() < 4) {
            if ($newLevel > $this->currentLevelId()) {
                return $this->respondError('You cannot give an account a higher level of access than your own.', [], ResponseInterface::HTTP_FORBIDDEN);
            }
            if ($payload['user_office_id'] !== $this->currentOfficeId()) {
                return $this->respondError('You can only assign accounts to your own office.', [], ResponseInterface::HTTP_FORBIDDEN);
            }
        }

        $userModel = new UserModel();

        if ($userModel->where('username', $payload['username'])->where('user_id !=', $id)->first()) {
            return $this->respondError('That username is already used by another account.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ── Email uniqueness check (skip when email is blank — allowed for null-email accounts) ──
        if ($payload['email'] !== '') {
            $query = $userModel->where('email', $payload['email']);
            if ($id > 0) {
                $query = $query->where('user_id !=', $id);
            }
            if ($query->first()) {
                return $this->respondError('That email address is already used by another account.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // Store NULL instead of empty string so the unique index allows multiple blank emails
        if ($payload['email'] === '') {
            $payload['email'] = null;
        }

        if (($payload['password'] ?? '') !== '') {
            if ($error = $this->passwordStrengthError((string) $payload['password'])) {
                return $this->respondError($error, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
            $payload['password'] = password_hash((string) $payload['password'], PASSWORD_DEFAULT);
            // A password someone else chose is temporary: the owner picks their own at next login
            if ($id !== $this->currentUserId()) {
                $payload['must_change_password'] = 1;
            }
        } else {
            unset($payload['password']);
        }

        $userModel->update($id, $payload);
        return $this->respondSuccess(null, 'User updated successfully.');
    }

    /**
     * Who may manage which account: technical staff (level 4) any account; a manager only the
     * accounts of their own office whose level is not above their own (never Technical Staff).
     * Null when allowed, else a 404 (the account is not shown to them either).
     */
    private function userAccessError(int $userId): ?ResponseInterface
    {
        $level = $this->currentLevelId();
        $row   = db_connect()->table('user_table u')
            ->select('u.user_office_id, COALESCE(loa.lvl_of_access, 0) AS level_id', false)
            ->join('level_of_access loa', 'loa.lvl_of_access_id = u.lvl_of_access_id', 'left')
            ->where('u.user_id', $userId)
            ->get(1)->getRowArray();

        if (! $row) {
            return $this->respondError('User not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }
        if ($level >= 4) {
            return null;
        }
        if ($this->currentOfficeId() <= 0
            || (int) $row['user_office_id'] !== $this->currentOfficeId()
            || (int) $row['level_id'] > $level) {
            return $this->respondError('User not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return null;
    }

    /**
     * Records of another office are off limits below level 4 (users: see userAccessError).
     */
    private function recordAccessError(string $type, int $id): ?ResponseInterface
    {
        if ($type === 'users') {
            return $this->userAccessError($id);
        }
        if ($type === 'user_office_table' || $this->currentLevelId() >= 4) {
            return null; // technical staff only (SettingsModel::definitions)
        }

        $definition = $this->definitionFor($type);
        $exists     = db_connect()->table($definition['table'])
            ->where($definition['pk'], $id)
            ->where('user_office_id', $this->currentOfficeId())
            ->countAllResults() > 0;

        return $exists ? null : $this->respondError('Record not found.', [], ResponseInterface::HTTP_NOT_FOUND);
    }

    private function saveUserOffice(int $id, array $payload): ResponseInterface
    {
        $payload['user_office_name'] = trim((string) ($payload['user_office_name'] ?? ''));

        if ($payload['user_office_name'] === '') {
            return $this->respondError('User Office name is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($id > 0) {
            db_connect()->table('user_office_table')->where('user_office_id', $id)->update($payload);
            return $this->respondSuccess(null, 'User Office updated successfully.');
        }

        db_connect()->table('user_office_table')->insert($payload);
        return $this->respondSuccess(null, 'User Office created successfully.', ResponseInterface::HTTP_CREATED);
    }

    private function sanitizePayload(array $payload): array
    {
        foreach ($payload as $field => $value) {
            $payload[$field] = str_ends_with($field, '_id')
                ? ($value === '' || $value === null ? null : (int) $value)
                : trim((string) $value);
        }
        return $payload;
    }

    private function hasEmptyRequiredText(array $payload): bool
    {
        $requiredTextValues = array_filter(
            $payload,
            static fn ($key) => ! str_ends_with((string) $key, '_id'),
            ARRAY_FILTER_USE_KEY,
        );
        return in_array('', $requiredTextValues, true);
    }
}
