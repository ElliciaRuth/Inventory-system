<?php

namespace App\Controllers\Api;

use App\Libraries\Mailer;
use App\Models\SettingsModel;
use App\Models\SmtpSettingsModel;
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

        if ($type === 'users') {
            if ($id === 0) {
                return $this->respondError('Users must create their own accounts via registration.', [], ResponseInterface::HTTP_FORBIDDEN);
            }
            return $this->saveUser($id, $payload);
        }

        if ($type === 'user_office_table') {
            return $this->saveUserOffice($id, $payload);
        }

        $payload = $this->sanitizePayload($payload);

        if ($this->hasEmptyRequiredText($payload)) {
            return $this->respondError('Please fill in the required fields.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settingsModel->saveRecord($type, $id, $payload, $this->currentOfficeId());

        return $this->respondSuccess(
            null,
            $id > 0 ? 'Record updated successfully.' : 'Record created successfully.',
            $id > 0 ? ResponseInterface::HTTP_OK : ResponseInterface::HTTP_CREATED
        );
    }

    /**
     * DELETE /api/settings/{type}/{id}
     */
    public function delete(string $type, int $id): ResponseInterface
    {
        if ($this->definitionFor($type) === null) {
            return $this->unknownType();
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

        try {
            $this->settingsModel->deleteRecord($type, $id);
        } catch (\Throwable $e) {
            return $this->respondError('Delete failed: ' . $e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondSuccess(null, 'Record deleted successfully.');
    }

    /**
     * POST /api/settings/users/{id}/activate
     */
    public function activate(int $id): ResponseInterface
    {
        $this->settingsModel->activateUser($id);
        return $this->respondSuccess(null, 'User activated successfully.');
    }

    /**
     * POST /api/settings/users/{id}/deactivate
     */
    public function deactivate(int $id): ResponseInterface
    {
        if ($this->currentUserId() === $id) {
            return $this->respondError('You cannot deactivate the currently logged-in user.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->settingsModel->isAdminAccount($id)) {
            return $this->respondError('The admin account cannot be deactivated.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settingsModel->deactivateUser($id);
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

        save_setting('expiry_warning_days', $warningDays);
        save_setting('expiry_danger_days', $dangerDays);

        return $this->respondSuccess([
            'expiry_warning_days' => $warningDays,
            'expiry_danger_days'  => $dangerDays,
        ], 'Inventory settings saved.');
    }

    // ── Email (SMTP) settings — Technical Staff ───────────────────────────
    // The Gmail account used to send password-reset codes. The App Password is
    // stored encrypted and never sent back to the browser.

    /**
     * GET /api/settings/email
     */
    public function emailSettings(): ResponseInterface
    {
        $config = (new SmtpSettingsModel())->getActive();
        if (! $config) {
            return $this->respondSuccess(['configured' => false], 'Email is not configured');
        }

        $configuredBy = $config['configured_by']
            ? (new UserModel())->select('name, username')->find((int) $config['configured_by'])
            : null;

        return $this->respondSuccess([
            'configured'    => true,
            'smtp_email'    => $config['smtp_email'],
            'configured_by' => $configuredBy ? ($configuredBy['name'] ?: $configuredBy['username']) : null,
            'updated_at'    => $config['updated_at'] ?? $config['created_at'] ?? null,
        ], 'Email settings retrieved');
    }

    /**
     * POST /api/settings/email   { smtp_email, smtp_password? }
     * The password may be left blank to keep the saved one (only when the account already exists).
     */
    public function saveEmailSettings(): ResponseInterface
    {
        $input    = $this->input();
        $email    = trim((string) ($input['smtp_email'] ?? ''));
        // Google shows App Passwords as "abcd efgh ijkl mnop"
        $password = str_replace(' ', '', (string) ($input['smtp_password'] ?? ''));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respondError('Please enter a valid Gmail address.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $model    = new SmtpSettingsModel();
        $existing = $model->getActive();

        if ($password === '' && ! $existing) {
            return $this->respondError('Please enter the Gmail App Password.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($password !== '' && strlen($password) < 8) {
            return $this->respondError('The App Password looks too short. Gmail App Passwords are 16 characters.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = [
            'smtp_email'    => $email,
            'configured_by' => $this->currentUserId(),
        ];
        if ($password !== '') {
            $data['smtp_password'] = base64_encode(service('encrypter')->encrypt($password));
        }

        if ($existing) {
            $model->update($existing['id'], $data);
        } else {
            $model->insert($data);
        }

        return $this->respondSuccess(null, 'Email settings saved. Send a test email to make sure they work.');
    }

    /**
     * POST /api/settings/email/test   { to? }  — defaults to the current user's email
     */
    public function testEmailSettings(): ResponseInterface
    {
        $to = trim((string) ($this->input()['to'] ?? ''));
        if ($to === '') {
            $to = (string) ((new UserModel())->find($this->currentUserId())['email'] ?? '');
        }
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->respondError('Enter an email address to send the test to.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mailer = new Mailer();
        if (! $mailer->isConfigured()) {
            return $this->respondError('Save the email settings first.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $error = $mailer->send(
            $to,
            'Test Email - BSU Inventory',
            '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;">' .
            '<h2 style="color:#1a5209;">Email is working</h2>' .
            '<p style="color:#475569;line-height:1.6;">This test confirms the BSU Inventory System can send email. ' .
            'Password-reset codes will be sent from this account.</p>' .
            '</div>'
        );

        if ($error !== null) {
            return $this->respondError($error, [], ResponseInterface::HTTP_BAD_GATEWAY);
        }

        return $this->respondSuccess(['to' => $to], "Test email sent to {$to}. Check the inbox (and Spam folder).");
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

    private function unknownType(): ResponseInterface
    {
        return $this->respondError('Unknown resource type or access denied.', [], ResponseInterface::HTTP_NOT_FOUND);
    }

    private function saveUser(int $id, array $payload): ResponseInterface
    {
        $payload['name']             = $this->cleanName((string) ($payload['name'] ?? ''));
        $payload['username']         = trim((string) ($payload['username'] ?? ''));
        $payload['email']            = trim((string) ($payload['email'] ?? ''));
        $payload['lvl_of_access_id'] = (int) ($payload['lvl_of_access_id'] ?? 0);
        $payload['user_office_id']   = (int) ($payload['user_office_id'] ?? $this->currentOfficeId());

        if ($payload['username'] === '' || $payload['lvl_of_access_id'] <= 0 || $payload['user_office_id'] <= 0) {
            return $this->respondError('Please fill in the required user fields.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($error = $this->nameError($payload['name'], 'Full name', allowComma: true)) {
            return $this->respondError($error, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userModel = new UserModel();

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
            $payload['password'] = password_hash((string) $payload['password'], PASSWORD_DEFAULT);
        } else {
            unset($payload['password']);
        }

        $userModel->update($id, $payload);
        return $this->respondSuccess(null, 'User updated successfully.');
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
