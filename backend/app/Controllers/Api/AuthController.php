<?php

namespace App\Controllers\Api;

use App\Filters\AuthFilter;
use App\Libraries\Mailer;
use App\Models\SmtpSettingsModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseApiController
{
    /** Session flags for the forced first-login steps, in the order they happen. */
    private const SETUP_FLAGS = ['must_change_password', 'must_setup_smtp', 'must_setup_recovery_email'];

    /**
     * Authenticate user credentials and start session.
     * POST /api/auth/login
     */
    public function login(): ResponseInterface
    {
        $input    = $this->input();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->respondError('Please provide both username and password.', [
                'username' => $username === '' ? 'Username is required.' : null,
                'password' => $password === '' ? 'Password is required.' : null,
            ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userModel = new UserModel();
        $user = $userModel->findWithLevel($username);

        if (! $user || ! $this->passwordMatches($password, $user['password'])) {
            return $this->respondError('Invalid username or password.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $activityId = (int) ($user['user_activity_id'] ?? 3);
        if ($activityId === 3) {
            return $this->respondError('Your account is pending activation by an administrator.', [], ResponseInterface::HTTP_FORBIDDEN);
        }
        if ($activityId === 2) {
            return $this->respondError('Your account has been deactivated. Please contact an administrator.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        // Rehash legacy plain-text password if needed
        if (! password_get_info($user['password'])['algo']) {
            $userModel->update($user['user_id'], [
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        $levelId  = (int) ($user['level_id'] ?? 0);
        $officeId = (int) ($user['user_office_id'] ?? 0);

        // Fetch office name
        $officeRow  = db_connect()->table('user_office_table')->where('user_office_id', $officeId)->get(1)->getRowArray();
        $officeName = $officeRow['user_office_name'] ?? 'General Office';

        $sessionData = [
            'id'             => (int) $user['user_id'],
            'username'       => $user['username'],
            'name'           => $user['name'] ?? $user['username'],
            'email'          => $user['email'] ?? '',
            'role'           => $user['role'] ?? '',
            'level_id'       => $levelId,
            'user_office_id' => $officeId,
            'office_name'    => $officeName,
        ];

        session()->regenerate();
        session()->set('user', $sessionData);
        session()->set('login_time', time());
        session()->set('last_activity', time());

        // ── First login: user must change the password before anything else ──
        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            session()->set('must_change_password', true);
        }

        return $this->respondSuccess([
            'user'          => $sessionData,
            'pending_setup' => $this->pendingSetup(),
        ], 'Login successful');
    }

    /**
     * Get current authenticated user profile.
     * GET /api/auth/me
     */
    public function me(): ResponseInterface
    {
        $sessionUser = session('user');

        // An idle session counts as logged out (auth/me sits outside the auth filter)
        if ($sessionUser && AuthFilter::isIdleExpired()) {
            session()->destroy();
            $sessionUser = null;
        }

        if (! $sessionUser) {
            return $this->respondSuccess([
                'authenticated' => false,
                'user'          => null,
                'pending_setup' => null,
            ], 'Not authenticated');
        }

        // Also serves as the frontend's keep-alive while the user is active
        session()->set('last_activity', time());

        $userId    = (int) ($sessionUser['id'] ?? 0);
        $userModel = new UserModel();
        $dbUser    = $userModel->find($userId);
        if ($dbUser) {
            $officeRow  = db_connect()->table('user_office_table')->where('user_office_id', (int) ($dbUser['user_office_id'] ?? 0))->get(1)->getRowArray();
            $officeName = $officeRow['user_office_name'] ?? 'General Office';
            $levelRow   = db_connect()->table('level_of_access')->where('lvl_of_access_id', (int) ($dbUser['lvl_of_access_id'] ?? 0))->get(1)->getRowArray();
            $role       = $levelRow['role'] ?? ($sessionUser['role'] ?? 'Staff');
            $levelId    = (int) ($levelRow['lvl_of_access'] ?? ($sessionUser['level_id'] ?? 1));

            $sessionUser = [
                'id'             => $userId,
                'username'       => $dbUser['username'],
                'name'           => $dbUser['name'] ?? $dbUser['username'],
                'email'          => $dbUser['email'] ?? '',
                'role'           => $role,
                'level_id'       => $levelId,
                'user_office_id' => (int) ($dbUser['user_office_id'] ?? 0),
                'office_name'    => $officeName,
            ];
            session()->set('user', $sessionUser);
        }

        return $this->respondSuccess([
            'authenticated' => true,
            'user'          => $sessionUser,
            'pending_setup' => $this->pendingSetup(),
        ], 'User profile retrieved');
    }

    /**
     * Log user out and destroy session.
     * POST /api/auth/logout
     */
    public function logout(): ResponseInterface
    {
        session()->destroy();
        return $this->respondSuccess(null, 'Logged out successfully');
    }

    /**
     * Get registration options (levels excluding tech staff, and user offices).
     * GET /api/auth/register-options
     */
    public function registerOptions(): ResponseInterface
    {
        $db = db_connect();
        $levels = $db->table('level_of_access')
            ->where('lvl_of_access !=', 4)
            ->orderBy('lvl_of_access', 'ASC')
            ->get()
            ->getResultArray();

        $offices = $db->table('user_office_table')
            ->orderBy('user_office_name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->respondSuccess([
            'levels'      => $levels,
            'userOffices' => $offices,
        ], 'Registration options retrieved');
    }

    /**
     * Process self-registration.
     * POST /api/auth/register
     */
    public function register(): ResponseInterface
    {
        $input = $this->input();

        $rules = [
            'first_name'       => 'required|min_length[2]|max_length[100]',
            'last_name'        => 'required|min_length[2]|max_length[100]',
            'middle_name'      => 'permit_empty|max_length[100]',
            'suffix'           => 'permit_empty|max_length[20]',
            'username'         => 'required|min_length[3]|max_length[50]|is_unique[user_table.username]',
            'email'            => 'required|valid_email|max_length[255]|is_unique[user_table.email]',
            'password'         => 'required|min_length[6]|max_length[255]',
            'confirm_password' => 'required|matches[password]',
            'lvl_of_access_id' => 'required|integer|greater_than[0]',
            'user_office_id'   => 'required|integer|greater_than[0]',
        ];

        if (! $this->validateData($input, $rules)) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Technical Staff (level 4) accounts cannot be self-registered
        if ((int) $input['lvl_of_access_id'] >= 4) {
            return $this->respondError('That access level cannot be self-registered.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $password = (string) ($input['password'] ?? '');
        if ($error = $this->passwordStrengthError($password)) {
            return $this->respondError($error, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $firstName  = $this->cleanName((string) ($input['first_name'] ?? ''));
        $lastName   = $this->cleanName((string) ($input['last_name'] ?? ''));
        $middleName = $this->cleanName((string) ($input['middle_name'] ?? ''));
        $suffix     = $this->normalizeSuffix((string) ($input['suffix'] ?? ''));

        $nameError = $this->nameError($firstName, 'First name')
            ?? $this->nameError($lastName, 'Last name')
            ?? $this->nameError($middleName, 'Middle name')
            ?? ($suffix === null ? 'Suffix must be one of: Jr., Sr., II, III, IV, V, VI.' : null);
        if ($nameError) {
            return $this->respondError($nameError, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Compose the legacy 'name' column: "First [Middle] Last[, Suffix]"
        $nameParts = array_filter([$firstName, $middleName, $lastName]);
        $fullName  = implode(' ', $nameParts);
        if ($suffix !== '') {
            $fullName .= ', ' . $suffix;
        }

        $model = new UserModel();
        $inserted = $model->insert([
            'name'              => $fullName,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'middle_name'       => $middleName !== '' ? $middleName : null,
            'suffix'            => $suffix !== '' ? $suffix : null,
            'username'          => trim((string) ($input['username'] ?? '')),
            'email'             => trim((string) ($input['email'] ?? '')),
            'password'          => password_hash($password, PASSWORD_DEFAULT),
            'user_office_id'    => (int) ($input['user_office_id'] ?? 0),
            'lvl_of_access_id'  => (int) ($input['lvl_of_access_id'] ?? 0),
            'user_activity_id'  => 3, // Pending
        ]);

        if (! $inserted) {
            return $this->respondError('Failed to create account. Please try again.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->respondSuccess(null, 'Account created successfully. Please wait for an administrator to activate your account.');
    }

    // ════════════════════════════════════════════════════════════════
    //  CHANGE PASSWORD (first-login forced + general)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/auth/change-password   { current_password (unless first login), password, confirm_password }
     */
    public function changePassword(): ResponseInterface
    {
        $input = $this->input();
        $rules = [
            'password'         => 'required|min_length[6]|max_length[255]',
            'confirm_password' => 'required|matches[password]',
        ];

        if (! $this->validateData($input, $rules)) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId    = $this->currentUserId();
        $userModel = new UserModel();

        // A voluntary change must prove the current password; the forced
        // first-login change happens right after logging in with it.
        if (! session('must_change_password')) {
            $current = (string) ($input['current_password'] ?? '');
            $stored  = (string) ($userModel->find($userId)['password'] ?? '');
            if ($current === '' || ! $this->passwordMatches($current, $stored)) {
                return $this->respondError('Your current password is incorrect.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $password = (string) $input['password'];
        if ($error = $this->passwordStrengthError($password)) {
            return $this->respondError($error, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userModel->update($userId, [
            'password'             => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ]);

        $isFirstLogin = (bool) session('must_change_password');
        session()->remove('must_change_password');

        // First-login password change for level 4 continues with SMTP setup
        if ($isFirstLogin && $this->currentLevelId() >= 4 && ! (new SmtpSettingsModel())->getActive()) {
            session()->set('must_setup_smtp', true);
            return $this->respondSuccess(
                ['pending_setup' => $this->pendingSetup()],
                'Password changed successfully. Now please configure the email settings for password recovery.'
            );
        }

        return $this->respondSuccess(['pending_setup' => $this->pendingSetup()], 'Password changed successfully.');
    }

    /**
     * Update user profile information (name, username, email, and optional password).
     * POST /api/auth/update-profile
     */
    public function updateProfile(): ResponseInterface
    {
        $userId    = $this->currentUserId();
        $userModel = new UserModel();
        $user      = $userModel->find($userId);

        if (! $user) {
            return $this->respondError('User account not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $input    = $this->input();
        $name     = $this->cleanName((string) ($input['name'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));

        if ($error = $this->nameError($name, 'Full name', allowComma: true)) {
            return $this->respondError($error, ['name' => $error], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rules = [
            'name'     => 'permit_empty|max_length[255]',
            'username' => 'required|min_length[3]|max_length[100]',
            'email'    => 'permit_empty|valid_email|max_length[255]',
        ];

        if (! $this->validateData($input, $rules)) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check username uniqueness if changed
        if ($username !== $user['username']) {
            $exists = $userModel->where('username', $username)->where('user_id !=', $userId)->first();
            if ($exists) {
                return $this->respondError('The username is already taken by another account.', [
                    'username' => 'Username is already taken.'
                ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // Check email uniqueness if changed and provided
        if ($email !== '' && $email !== ($user['email'] ?? '')) {
            $exists = $userModel->where('email', $email)->where('user_id !=', $userId)->first();
            if ($exists) {
                return $this->respondError('The email is already registered to another account.', [
                    'email' => 'Email is already registered.'
                ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $updateData = [
            'name'     => $name !== '' ? $name : $username,
            'username' => $username,
            'email'    => $email,
        ];

        // Optional password change within profile
        $newPassword     = (string) ($input['password'] ?? '');
        $currentPassword = (string) ($input['current_password'] ?? '');
        $confirmPassword = (string) ($input['confirm_password'] ?? '');

        if ($newPassword !== '') {
            $storedPassword = (string) ($user['password'] ?? '');
            if ($currentPassword === '' || ! $this->passwordMatches($currentPassword, $storedPassword)) {
                return $this->respondError('Your current password is incorrect.', [
                    'current_password' => 'Incorrect current password.'
                ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($newPassword !== $confirmPassword) {
                return $this->respondError('New password and confirm password do not match.', [
                    'confirm_password' => 'Passwords do not match.'
                ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($error = $this->passwordStrengthError($newPassword)) {
                return $this->respondError($error, [
                    'password' => $error
                ], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            $updateData['password']             = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateData['must_change_password'] = 0;
            session()->remove('must_change_password');
        }

        $userModel->update($userId, $updateData);

        // Fetch refreshed office & role
        $officeRow  = db_connect()->table('user_office_table')->where('user_office_id', (int) ($user['user_office_id'] ?? 0))->get(1)->getRowArray();
        $officeName = $officeRow['user_office_name'] ?? 'General Office';
        $levelRow   = db_connect()->table('level_of_access')->where('lvl_of_access_id', (int) ($user['lvl_of_access_id'] ?? 0))->get(1)->getRowArray();
        $role       = $levelRow['role'] ?? 'Staff';
        $levelId    = (int) ($levelRow['lvl_of_access'] ?? 1);

        $sessionData = [
            'id'             => $userId,
            'username'       => $username,
            'name'           => $updateData['name'],
            'email'          => $email,
            'role'           => $role,
            'level_id'       => $levelId,
            'user_office_id' => (int) ($user['user_office_id'] ?? 0),
            'office_name'    => $officeName,
        ];
        session()->set('user', $sessionData);

        $msg = $newPassword !== '' ? 'Profile and password updated successfully.' : 'Profile information updated successfully.';
        return $this->respondSuccess([
            'user' => $sessionData,
        ], $msg);
    }

    // ════════════════════════════════════════════════════════════════
    //  SMTP SETUP (level 4, first-login step 2)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/auth/setup-smtp
     */
    public function setupSmtp(): ResponseInterface
    {
        if ($this->currentLevelId() < 4) {
            return $this->respondError('You do not have permission to perform this action.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $input = $this->input();
        $rules = [
            'smtp_email'    => 'required|valid_email|max_length[255]',
            'smtp_password' => 'required|min_length[8]|max_length[255]',
        ];

        if (! $this->validateData($input, $rules)) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $encrypter = service('encrypter');
        $smtpModel = new SmtpSettingsModel();
        $existing  = $smtpModel->getActive();
        $data = [
            'smtp_email'    => trim((string) $input['smtp_email']),
            'smtp_password' => base64_encode($encrypter->encrypt((string) $input['smtp_password'])),
            'configured_by' => $this->currentUserId(),
        ];

        if ($existing) {
            $smtpModel->update($existing['id'], $data);
        } else {
            $smtpModel->insert($data);
        }

        session()->remove('must_setup_smtp');

        // ── Step 3: ensure the admin has a personal recovery email set ──
        $adminUser = (new UserModel())->find($this->currentUserId());
        if (empty(trim((string) ($adminUser['email'] ?? '')))) {
            session()->set('must_setup_recovery_email', true);
            return $this->respondSuccess(
                ['pending_setup' => $this->pendingSetup()],
                'Email settings configured. Now set your personal recovery email.'
            );
        }

        return $this->respondSuccess(
            ['pending_setup' => $this->pendingSetup()],
            'Email settings configured successfully. Password recovery is now available.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    //  RECOVERY EMAIL SETUP (level 4, first-login step 3)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/auth/setup-recovery-email
     */
    public function setupRecoveryEmail(): ResponseInterface
    {
        if ($this->currentLevelId() < 4) {
            return $this->respondError('You do not have permission to perform this action.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $input = $this->input();
        if (! $this->validateData($input, ['recovery_email' => 'required|valid_email|max_length[255]'])) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $recoveryEmail = trim((string) $input['recovery_email']);
        $userId        = $this->currentUserId();
        $userModel     = new UserModel();

        // Ensure this email is not already taken by another user
        if ($userModel->where('email', $recoveryEmail)->where('user_id !=', $userId)->first()) {
            return $this->respondError(
                'That email address is already used by another account. Please use a different email.',
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $userModel->update($userId, ['email' => $recoveryEmail]);

        // Update email in active session
        $sessionUser          = session('user');
        $sessionUser['email'] = $recoveryEmail;
        session()->set('user', $sessionUser);
        session()->remove('must_setup_recovery_email');

        return $this->respondSuccess(
            ['user' => $sessionUser, 'pending_setup' => $this->pendingSetup()],
            'Recovery email saved. Your account setup is complete!'
        );
    }

    // ════════════════════════════════════════════════════════════════
    //  FORGOT PASSWORD (public — 6-digit code via email)
    // ════════════════════════════════════════════════════════════════

    /**
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(): ResponseInterface
    {
        $input = $this->input();
        if (! $this->validateData($input, ['email' => 'required|valid_email'])) {
            return $this->respondError('Please enter a valid email address.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = trim((string) $input['email']);
        $model = new UserModel();
        $user  = $model->findByEmail($email);

        // Tell the user straight away when no account uses this email, so they
        // aren't left waiting for a code that will never arrive.
        if (! $user) {
            return $this->respondError(
                'No account is registered with that email address. Check the spelling or contact the administrator.',
                [],
                ResponseInterface::HTTP_NOT_FOUND
            );
        }
        $successMsg = 'A 6-digit verification code has been sent to your email.';

        $mailer = new Mailer();
        if (! $mailer->isConfigured()) {
            return $this->respondError(
                'Password recovery is not available yet. Please contact the system administrator.',
                [],
                ResponseInterface::HTTP_SERVICE_UNAVAILABLE
            );
        }

        // Generate 6-digit code
        $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $model->update($user['user_id'], [
            'password_reset_token'   => hash('sha256', $code),
            'password_reset_expires' => $expires,
        ]);
        cache()->delete($this->resetAttemptsKey($email));

        $error = $mailer->send(
            $email,
            'Password Reset Code - BSU Inventory',
            '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;background:#f8fffd;border-radius:16px;">' .
            '<h2 style="color:#0f3d3e;margin-bottom:16px;">Password Reset Code</h2>' .
            '<p style="color:#475569;line-height:1.6;">You requested a password reset for your BSU Inventory account. Use the verification code below:</p>' .
            '<div style="text-align:center;margin:28px 0;">' .
            '<div style="display:inline-block;padding:18px 40px;background:linear-gradient(135deg,#0f766e,#115e59);color:#fff;border-radius:14px;font-size:32px;font-weight:700;letter-spacing:8px;">' . esc($code) . '</div>' .
            '</div>' .
            '<p style="color:#94a3b8;font-size:13px;">This code will expire in 15 minutes. If you did not request this, you can safely ignore this email.</p>' .
            '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">' .
            '<p style="color:#cbd5e1;font-size:12px;">BSU Integrated Inventory Monitoring System</p>' .
            '</div>'
        );
        if ($error !== null) {
            return $this->respondError(
                'Failed to send the reset email. Please try again later or contact the administrator.',
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->respondSuccess(null, $successMsg);
    }

    // ════════════════════════════════════════════════════════════════
    //  VERIFY CODE + RESET PASSWORD (public)
    // ════════════════════════════════════════════════════════════════

    /** Wrong code guesses allowed per emailed code before a new code is required */
    private const MAX_RESET_ATTEMPTS = 5;

    /**
     * Checks the emailed code without changing anything, so the page can ask
     * for the new password only after a valid code.
     * POST /api/auth/verify-reset-code   { email, code }
     */
    public function verifyResetCode(): ResponseInterface
    {
        $input = $this->input();
        if (! $this->validateData($input, ['email' => 'required|valid_email', 'code' => 'required|exact_length[6]|numeric'])) {
            return $this->respondError('Please enter the 6-digit code from your email.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->checkResetCode(trim((string) $input['email']), trim((string) $input['code']));
        if (is_string($result)) {
            return $this->respondError($result, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondSuccess(null, 'Code verified. Choose your new password.');
    }

    /**
     * The user the reset code belongs to, or an error message. Wrong guesses
     * are counted; after MAX_RESET_ATTEMPTS the code is cancelled.
     */
    private function checkResetCode(string $email, string $code): array|string
    {
        $model = new UserModel();
        $user  = $model->findByEmail($email);
        if (! $user || empty($user['password_reset_token'])) {
            return 'No active code for this email. Please request a new code.';
        }
        if (strtotime((string) $user['password_reset_expires']) <= time()) {
            return 'This code has expired. Please request a new code.';
        }

        if (hash_equals((string) $user['password_reset_token'], hash('sha256', $code))) {
            return $user;
        }

        $key      = $this->resetAttemptsKey($email);
        $attempts = (int) cache($key) + 1;
        if ($attempts >= self::MAX_RESET_ATTEMPTS) {
            cache()->delete($key);
            $model->update($user['user_id'], ['password_reset_token' => null, 'password_reset_expires' => null]);
            return 'Too many incorrect attempts. Please request a new code.';
        }
        cache()->save($key, $attempts, 900);

        $left = self::MAX_RESET_ATTEMPTS - $attempts;
        return "Incorrect code. {$left} " . ($left === 1 ? 'attempt' : 'attempts') . ' left.';
    }

    private function resetAttemptsKey(string $email): string
    {
        return 'reset_attempts_' . md5(strtolower(trim($email)));
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(): ResponseInterface
    {
        $input = $this->input();
        $rules = [
            'email'            => 'required|valid_email',
            'code'             => 'required|exact_length[6]|numeric',
            'password'         => 'required|min_length[6]|max_length[255]',
            'confirm_password' => 'required|matches[password]',
        ];

        if (! $this->validateData($input, $rules)) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $model = new UserModel();
        $user  = $this->checkResetCode(trim((string) $input['email']), trim((string) $input['code']));
        if (is_string($user)) {
            return $this->respondError($user, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $password = (string) $input['password'];
        if ($error = $this->passwordStrengthError($password)) {
            return $this->respondError($error, [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $model->update($user['user_id'], [
            'password'               => password_hash($password, PASSWORD_DEFAULT),
            'password_reset_token'   => null,
            'password_reset_expires' => null,
        ]);
        cache()->delete($this->resetAttemptsKey((string) $input['email']));

        return $this->respondSuccess(null, 'Password has been reset successfully. You can now log in with your new password.');
    }

    /**
     * The first pending forced setup step for this session, or null.
     */
    private function pendingSetup(): ?string
    {
        foreach (self::SETUP_FLAGS as $flag) {
            if (session($flag)) {
                return match ($flag) {
                    'must_change_password'      => 'change_password',
                    'must_setup_smtp'           => 'setup_smtp',
                    'must_setup_recovery_email' => 'setup_recovery_email',
                };
            }
        }

        return null;
    }

    private function passwordStrengthError(string $password): ?string
    {
        if (! preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (! preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (! preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        // No sequential numbers (e.g. 123, 234, 345…)
        if (preg_match('/(?:0(?=1)|1(?=2)|2(?=3)|3(?=4)|4(?=5)|5(?=6)|6(?=7)|7(?=8)|8(?=9)){2}/', $password)) {
            return 'Password must not contain sequential numbers (e.g. 123, 456).';
        }

        return null;
    }

    private function passwordMatches(string $input, string $stored): bool
    {
        if (password_get_info($stored)['algo']) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }
}
