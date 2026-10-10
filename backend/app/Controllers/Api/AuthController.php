<?php

namespace App\Controllers\Api;

use App\Filters\AuthFilter;
use App\Libraries\AuditLog;
use App\Libraries\Mailer;
use App\Libraries\Privacy;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\PasswordReset;

class AuthController extends BaseApiController
{
    /**
     * Failed logins allowed before a temporary lock: per username from one device (IP), per
     * device for all usernames, and per username from all devices together. The last is set
     * high so that someone who knows a username can't lock its owner out from one device;
     * it only stops guessing spread over many devices.
     */
    private const MAX_LOGIN_FAILURES         = 5;
    private const MAX_IP_LOGIN_FAILURES      = 20;
    private const MAX_ACCOUNT_LOGIN_FAILURES = 50;
    private const LOGIN_LOCK_SECONDS         = 900;

    /** bcrypt hash of a random string, checked against when the username doesn't exist. */
    private const DUMMY_HASH = '$2y$10$OTpRNC5L1qHghgpmJxF5aeoLl9CsmPJpapckTIr0gRIEdl9enhXA6';

    /** Session flags for the forced first-login steps, in the order they happen. */
    private const SETUP_FLAGS = ['must_change_password'];

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

        // Who tried, for the audit trail (no session exists yet)
        $actor = [
            'id'             => (int) ($user['user_id'] ?? 0),
            'username'       => $user['username'] ?? $username,
            'user_office_id' => (int) ($user['user_office_id'] ?? 0),
        ];

        // Failures count per account, whether it was typed as the username or the email
        $lockName = $user ? 'user#' . (int) $user['user_id'] : $username;

        // Locked after too many wrong passwords: refuse before even checking this one
        if ($wait = $this->loginLockedFor($lockName)) {
            AuditLog::record('auth.login_locked', 'user', $user ? (int) $user['user_id'] : null, "Login attempt for \"{$username}\" while locked", [
                'minutes_left' => $wait,
            ], $actor);

            return $this->respondError(
                "Too many failed login attempts. Try again in {$wait} minute" . ($wait === 1 ? '' : 's') . '.',
                ['locked_minutes' => $wait],
                ResponseInterface::HTTP_TOO_MANY_REQUESTS
            );
        }

        if (! $user) {
            // Same bcrypt work as a real check, so the response time doesn't reveal which usernames exist
            password_verify($password, self::DUMMY_HASH);
        }

        if (! $user || ! $this->passwordMatches($password, $user['password'])) {
            $left = $this->recordLoginFailure($lockName);
            AuditLog::record('auth.login_failed', 'user', $user ? (int) $user['user_id'] : null, "Failed login for \"{$username}\"", [
                'reason'        => $user ? 'wrong password' : 'unknown username',
                'attempts_left' => $left,
            ], $actor);

            if ($left === 0) {
                $minutes = intdiv(self::LOGIN_LOCK_SECONDS, 60);
                AuditLog::record('auth.login_locked', 'user', $user ? (int) $user['user_id'] : null,
                    "Login for \"{$username}\" locked for {$minutes} minutes after too many failed attempts", [], $actor);

                return $this->respondError(
                    "Too many failed login attempts. Login is locked for {$minutes} minutes.",
                    ['locked_minutes' => $minutes],
                    ResponseInterface::HTTP_TOO_MANY_REQUESTS
                );
            }

            return $this->respondError(
                'Invalid username or password.' . ($left <= 2 ? " {$left} attempt" . ($left === 1 ? '' : 's') . ' left before login is locked for ' . intdiv(self::LOGIN_LOCK_SECONDS, 60) . ' minutes.' : ''),
                [],
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        $this->clearLoginFailures($lockName);

        $activityId = (int) ($user['user_activity_id'] ?? 3);
        if ($activityId === 3 || $activityId === 2) {
            AuditLog::record('auth.login_blocked', 'user', (int) $user['user_id'], "Login refused for \"{$user['username']}\"", [
                'reason' => $activityId === 3 ? 'account pending activation' : 'account deactivated',
            ], $actor);
        }
        if ($activityId === 3) {
            return $this->respondError('Your account is pending activation by an administrator.', [], ResponseInterface::HTTP_FORBIDDEN);
        }
        if ($activityId === 2) {
            return $this->respondError('Your account has been deactivated. Please contact an administrator.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        // Rehash legacy plain-text password if needed
        $passwordHash = (string) $user['password'];
        if (! password_get_info($passwordHash)['algo']) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $userModel->update($user['user_id'], ['password' => $passwordHash]);
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
        AuthFilter::rememberPassword($passwordHash);
        session()->set('login_time', time());
        session()->set('last_activity', time());

        AuditLog::record('auth.login', 'user', (int) $user['user_id'], "{$user['username']} logged in", [
            'role'   => $sessionData['role'],
            'office' => $officeName,
        ]);

        // ── First login: user must change the password before anything else ──
        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            session()->set('must_change_password', true);
        }

        return $this->respondSuccess([
            'user'          => $this->publicUser($sessionData),
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

        // Deleted, deactivated or password changed since login: the session ends here too (as in AuthFilter)
        if (! $dbUser || (int) ($dbUser['user_activity_id'] ?? 0) !== 1 || AuthFilter::passwordChanged((string) ($dbUser['password'] ?? ''))) {
            session()->destroy();

            return $this->respondSuccess([
                'authenticated' => false,
                'user'          => null,
                'pending_setup' => null,
            ], 'Not authenticated');
        }

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
            'user'          => $this->publicUser($sessionUser),
            'pending_setup' => $this->pendingSetup(),
        ], 'User profile retrieved');
    }

    /**
     * Log user out and destroy session.
     * POST /api/auth/logout
     */
    public function logout(): ResponseInterface
    {
        if ($user = session('user')) {
            AuditLog::record('auth.logout', 'user', (int) ($user['id'] ?? 0), ($user['username'] ?? 'User') . ' logged out');
        }
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
            // Uniqueness of the email is checked below without telling the visitor
            'email'            => 'required|valid_email|max_length[255]',
            'password'         => 'required|min_length[8]|max_length[255]',
            'confirm_password' => 'required|matches[password]',
            'lvl_of_access_id' => 'required|integer|greater_than[0]',
            'user_office_id'   => 'required|integer|greater_than[0]',
        ];

        if (! $this->validateData($input, $rules)) {
            $errors = $this->validator->getErrors();
            return $this->respondError(implode(' ', $errors), $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Technical Staff (level 4) accounts cannot be self-registered. Checked on the level the
        // row stands for, not its id, so an unknown or re-numbered row can't slip through.
        $level = $this->accessLevelOf((int) $input['lvl_of_access_id']);
        if ($level < 1 || $level >= 4) {
            return $this->respondError('That access level cannot be self-registered.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (! $this->userOfficeExists((int) $input['user_office_id'])) {
            return $this->respondError('Choose a valid user office.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
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
        $email = trim((string) ($input['email'] ?? ''));

        // An email already in use gets the same reply as a new account, so the sign-up form
        // can't be used to find out which addresses have accounts. Nothing is created; the
        // owner can sign in or reset the password with that address as usual.
        if ($existing = $model->findByEmail($email)) {
            AuditLog::record('auth.register_duplicate_email', 'user', (int) $existing['user_id'], 'Sign-up attempted with an email that already has an account', [
                'username_tried' => trim((string) ($input['username'] ?? '')),
            ], ['id' => 0, 'username' => trim((string) ($input['username'] ?? '')), 'user_office_id' => (int) ($existing['user_office_id'] ?? 0)]);

            return $this->respondSuccess(null, self::REGISTERED_MESSAGE);
        }

        $inserted = $model->insert([
            'name'              => $fullName,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'middle_name'       => $middleName !== '' ? $middleName : null,
            'suffix'            => $suffix !== '' ? $suffix : null,
            'username'          => trim((string) ($input['username'] ?? '')),
            'email'             => $email,
            'password'          => password_hash($password, PASSWORD_DEFAULT),
            'user_office_id'    => (int) ($input['user_office_id'] ?? 0),
            'lvl_of_access_id'  => (int) ($input['lvl_of_access_id'] ?? 0),
            'user_activity_id'  => 3, // Pending
        ]);

        if (! $inserted) {
            return $this->respondError('Failed to create account. Please try again.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->respondSuccess(null, self::REGISTERED_MESSAGE);
    }

    private const REGISTERED_MESSAGE = 'Account created successfully. Please wait for an administrator to activate your account. '
        . 'If you already have an account with this email address, sign in or reset your password instead.';

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
            'password'         => 'required|min_length[8]|max_length[255]',
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

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userModel->update($userId, [
            'password'             => $hash,
            'must_change_password' => 0,
        ]);
        // This session stays logged in; every other session of the account ends
        AuthFilter::rememberPassword($hash);

        $isFirstLogin = (bool) session('must_change_password');
        session()->remove('must_change_password');
        AuditLog::record('auth.password_changed', 'user', $userId, ($isFirstLogin ? 'First-login password set' : 'Password changed'));

        // No email setup step any more: users reset their password through their own email
        // account (forgotPassword), so no administrator has to enter a personal mail account

        return $this->respondSuccess(['pending_setup' => $this->pendingSetup()], 'Password changed successfully.');
    }

    /**
     * Update user profile information (name, username, and optional password).
     * The email is changed only through requestEmailChange / confirmEmailChange.
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

        if ($error = $this->nameError($name, 'Full name', allowComma: true)) {
            return $this->respondError($error, ['name' => $error], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rules = [
            'name'     => 'permit_empty|max_length[255]',
            'username' => 'required|min_length[3]|max_length[100]',
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

        $updateData = [
            'name'     => $name !== '' ? $name : $username,
            'username' => $username,
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
        if (isset($updateData['password'])) {
            // This session stays logged in; every other session of the account ends
            AuthFilter::rememberPassword($updateData['password']);
        }

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
            'email'          => (string) ($user['email'] ?? ''),
            'role'           => $role,
            'level_id'       => $levelId,
            'user_office_id' => (int) ($user['user_office_id'] ?? 0),
            'office_name'    => $officeName,
        ];
        session()->set('user', $sessionData);

        $msg = $newPassword !== '' ? 'Profile and password updated successfully.' : 'Profile information updated successfully.';
        return $this->respondSuccess([
            'user' => $this->publicUser($sessionData),
        ], $msg);
    }

    // ════════════════════════════════════════════════════════════════
    //  CHANGE EMAIL (verified with a 6-digit code, like Forgot Password)
    // ════════════════════════════════════════════════════════════════

    /**
     * Step 1: the current password, the new address and that address's app password. The code
     * is sent from the new account to itself (as in forgotPassword), so only its owner can
     * receive it; the app password is used for this one send and dropped. The pending change
     * is kept in this session only.
     * POST /api/auth/email-change/request   { email, app_key, current_password }
     */
    public function requestEmailChange(): ResponseInterface
    {
        $input  = $this->input();
        $appKey = str_replace(' ', '', (string) ($input['app_key'] ?? ''));
        unset($input['app_key']);

        if (! $this->validateData($input, ['email' => 'required|valid_email|max_length[255]'])) {
            return $this->respondError('Please enter a valid email address.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        $email  = trim((string) $input['email']);
        $userId = $this->currentUserId();
        $model  = new UserModel();
        $user   = $model->find($userId);
        $limits = config(PasswordReset::class);

        if (! $user) {
            return $this->respondError('User account not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }
        if (mb_strtolower($email) === mb_strtolower((string) ($user['email'] ?? ''))) {
            return $this->respondError('That is already your email address.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Reset codes go to this address, so changing it needs the current password: otherwise
        // anyone at an unlocked screen could redirect the reset and take the account over
        $pwFailKey = 'email_change_pw_fail_' . $userId;
        if ((int) cache($pwFailKey) >= $limits->maxSignInFailures) {
            return $this->respondError('Too many wrong passwords. Try again in 15 minutes.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }
        $current = (string) ($input['current_password'] ?? '');
        if ($current === '' || ! $this->passwordMatches($current, (string) ($user['password'] ?? ''))) {
            cache()->save($pwFailKey, (int) cache($pwFailKey) + 1, 900);

            return $this->respondError('Your current password is incorrect.', ['current_password' => 'Incorrect.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        cache()->delete($pwFailKey);

        if ($appKey === '') {
            return $this->respondError(
                'Enter the app password of the new email account: the code is sent from that account.',
                ['app_key' => 'Required.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if (! preg_match('/^[\x21-\x7E]{8,64}$/', $appKey)) {
            return $this->respondError(
                'That app password doesn\'t look right. A Gmail app password is 16 letters (the spaces don\'t matter).',
                ['app_key' => 'Invalid format.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $failKey = 'email_change_signin_fail_' . $userId;
        if ((int) cache($failKey) >= $limits->maxSignInFailures) {
            return $this->respondError('Too many rejected app passwords. Try again in 15 minutes.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }
        $sentKey = 'email_change_sent_' . $userId;
        $sent    = array_values(array_filter((array) (cache($sentKey) ?? []), static fn ($t) => (int) $t > time() - 3600));
        if ($sent !== [] && max($sent) > time() - $limits->sendCooldownSeconds) {
            return $this->respondError('A code was just sent. Please wait a minute before asking for another one.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }
        if (count($sent) >= $limits->sendsPerHour) {
            return $this->respondError('Too many codes were requested. Please try again in an hour.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }

        // Only the owner of the new mailbox can make it send, so telling that owner (by mail)
        // that the address already has an account reveals nothing to anyone else
        $taken = $model->where('email', $email)->where('user_id !=', $userId)->first();
        $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $failure = (new Mailer())->sendFromOwnAccount(
            $email,
            $appKey,
            $email,
            $taken ? 'Email change request - BSU Inventory' : 'Email Verification Code - BSU Inventory',
            $taken ? $this->emailTakenHtml() : $this->emailChangeHtml($code, $limits->codeMinutes)
        );
        $appKey = '';

        if ($failure === Mailer::FAILED_SIGN_IN) {
            cache()->save($failKey, (int) cache($failKey) + 1, 900);

            return $this->respondError(
                'Your email provider did not accept this email address and app password. Check both, make sure the app password is for this address, and try again.',
                ['app_key' => 'Rejected by the email provider.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($failure === Mailer::FAILED_NETWORK) {
            return $this->respondError(
                'The server could not reach your email provider; it may have no internet connection right now. Try again later.',
                [],
                ResponseInterface::HTTP_SERVICE_UNAVAILABLE
            );
        }
        if ($failure !== null) {
            return $this->respondError('The email could not be sent. Please try again later.', [], ResponseInterface::HTTP_BAD_GATEWAY);
        }

        cache()->delete($failKey);
        $sent[] = time();
        cache()->save($sentKey, $sent, 3600);

        if ($taken) {
            session()->remove('email_change');
        } else {
            session()->set('email_change', [
                'email'    => $email,
                'code'     => $this->emailChangeCodeHash($code, $userId, $email),
                'expires'  => time() + $limits->codeMinutes * 60,
                'attempts' => 0,
            ]);
        }
        AuditLog::record('auth.email_change_requested', 'user', $userId, 'Email change code sent to ' . self::maskEmail($email), [
            'address_in_use' => (bool) $taken,
        ]);

        return $this->respondSuccess(null, 'A 6-digit code was sent to ' . self::maskEmail($email) . '. Check its inbox (and the Spam folder).');
    }

    /**
     * Step 2: the code from the email. The address changes only when it matches.
     * POST /api/auth/email-change/confirm   { code }
     */
    public function confirmEmailChange(): ResponseInterface
    {
        $input = $this->input();
        if (! $this->validateData($input, ['code' => 'required|exact_length[6]|numeric'])) {
            return $this->respondError('Please enter the 6-digit code from your email.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId  = $this->currentUserId();
        $pending = session('email_change');
        if (! is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
            session()->remove('email_change');

            return $this->respondError('The code is incorrect or has expired. Please request a new code.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = (string) $pending['email'];
        if (! hash_equals((string) $pending['code'], $this->emailChangeCodeHash(trim((string) $input['code']), $userId, $email))) {
            $max                 = config(PasswordReset::class)->maxCodeAttempts;
            $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
            if ($pending['attempts'] >= $max) {
                session()->remove('email_change');

                return $this->respondError('Too many incorrect attempts. Please request a new code.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
            session()->set('email_change', $pending);
            $left = $max - $pending['attempts'];

            return $this->respondError("The code is incorrect. {$left} " . ($left === 1 ? 'attempt' : 'attempts') . ' left.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        session()->remove('email_change');
        $model = new UserModel();
        if ($model->where('email', $email)->where('user_id !=', $userId)->first()) {
            return $this->respondError('That email address is now used by another account.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $old = (string) ($model->find($userId)['email'] ?? '');
        // A reset code sent to the old address is void from now on
        $model->update($userId, [
            'email'                  => $email,
            'password_reset_token'   => null,
            'password_reset_expires' => null,
        ]);

        $sessionUser          = (array) session('user');
        $sessionUser['email'] = $email;
        session()->set('user', $sessionUser);
        AuditLog::record('auth.email_changed', 'user', $userId, 'Email changed from ' . ($old !== '' ? self::maskEmail($old) : '(none)') . ' to ' . self::maskEmail($email));

        return $this->respondSuccess(['user' => $this->publicUser($sessionUser)], 'Your email address has been changed.');
    }

    /** Code hash bound to this user and this new address. */
    private function emailChangeCodeHash(string $code, int $userId, string $email): string
    {
        return $this->resetCodeHash($code . '|email-change|' . mb_strtolower($email), $userId);
    }

    private function emailChangeHtml(string $code, int $minutes): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;background:#f8fffd;border-radius:16px;">' .
            '<h2 style="color:#0f3d3e;margin-bottom:16px;">Confirm Your New Email Address</h2>' .
            '<p style="color:#475569;line-height:1.6;">You asked to use this address for your BSU Inventory account. Enter the code below on your profile page:</p>' .
            '<div style="text-align:center;margin:28px 0;">' .
            '<div style="display:inline-block;padding:18px 40px;background:linear-gradient(135deg,#0f766e,#115e59);color:#fff;border-radius:14px;font-size:32px;font-weight:700;letter-spacing:8px;">' . esc($code) . '</div>' .
            '</div>' .
            '<p style="color:#94a3b8;font-size:13px;">This code will expire in ' . $minutes . ' minutes. If you did not request this, you can ignore this email, and consider changing your email account\'s app password.</p>' .
            '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">' .
            '<p style="color:#cbd5e1;font-size:12px;">BSU Integrated Inventory Monitoring System</p>' .
            '</div>';
    }

    private function emailTakenHtml(): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;background:#f8fffd;border-radius:16px;">' .
            '<h2 style="color:#0f3d3e;margin-bottom:16px;">Email change request</h2>' .
            '<p style="color:#475569;line-height:1.6;">Someone asked to move a BSU Inventory account to this email address, but another account already uses it, so nothing was changed. ' .
            'An address can belong to one account only: use a different address, or ask your office manager.</p>' .
            '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">' .
            '<p style="color:#cbd5e1;font-size:12px;">BSU Integrated Inventory Monitoring System</p>' .
            '</div>';
    }

    /** The user as sent to the browser: the email is masked, so it can't be read off the screen or the network. */
    private function publicUser(array $user): array
    {
        $user['email'] = self::maskEmail((string) ($user['email'] ?? ''));

        return $user;
    }

    private static function maskEmail(string $email): string
    {
        return Privacy::maskEmail($email);
    }

    // ════════════════════════════════════════════════════════════════
    //  FORGOT PASSWORD (public — 6-digit code via email)
    // ════════════════════════════════════════════════════════════════

    /** Shown whether or not an account uses the address, so the reply reveals nothing */
    private const RESET_SENT_MESSAGE = 'If an account uses this email address, a password reset code has been sent to it. Check your inbox (and the Spam folder).';

    /**
     * POST /api/auth/forgot-password   { email, app_key }
     *
     * With app_key: the code is sent from the user's own email account to itself, signing in
     * with that app password (Config\PasswordReset picks the mail server). The key is used for
     * this one send and then dropped: it is not stored, logged, put in the session or echoed.
     * Only the owner of the mailbox can make it send, and the mail goes to the same mailbox,
     * so the answer never reveals whether the address has an account here.
     */
    public function forgotPassword(): ResponseInterface
    {
        $input  = $this->input();
        $appKey = str_replace(' ', '', (string) ($input['app_key'] ?? '')); // Google shows "abcd efgh ijkl mnop"
        unset($input['app_key']);

        if (! $this->validateData($input, ['email' => 'required|valid_email|max_length[255]'])) {
            return $this->respondError('Please enter a valid email address.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        $email  = trim((string) $input['email']);
        $limits = config(PasswordReset::class);

        if ($appKey === '') {
            return $this->respondError(
                'Enter the app password of your email account: the reset code is sent from your own account.',
                ['app_key' => 'Required.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if (! preg_match('/^[\x21-\x7E]{8,64}$/', $appKey)) {
            return $this->respondError(
                'That app password doesn\'t look right. A Gmail app password is 16 letters (the spaces don\'t matter).',
                ['app_key' => 'Invalid format.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Rejected app passwords per address: stops this server being used to try passwords
        $failKey = 'reset_signin_fail_' . md5(strtolower($email));
        if ((int) cache($failKey) >= $limits->maxSignInFailures) {
            return $this->respondError('Too many rejected app passwords for this email. Try again in 15 minutes.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }

        // Each new code resets the wrong-guess counter, so limit how often one address gets a
        // code: otherwise guesses per code × codes on demand would make the 6 digits guessable
        // (and the inbox could be flooded)
        $sentKey = 'reset_sent_' . md5(strtolower($email));
        $sent    = array_values(array_filter((array) (cache($sentKey) ?? []), static fn ($t) => (int) $t > time() - 3600));
        if ($sent !== [] && max($sent) > time() - $limits->sendCooldownSeconds) {
            return $this->respondError('A code was just sent. Please wait a minute before asking for another one.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }
        if (count($sent) >= $limits->sendsPerHour) {
            return $this->respondError('Too many codes were requested for this email. Please try again in an hour.', [], ResponseInterface::HTTP_TOO_MANY_REQUESTS);
        }

        $model = new UserModel();
        $user  = $model->findByEmail($email);
        $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Sent from the user's own account. No account here: the mailbox owner is told so by mail.
        $failure = (new Mailer())->sendFromOwnAccount(
            $email,
            $appKey,
            $email,
            $user ? 'Password Reset Code - BSU Inventory' : 'Password reset request - BSU Inventory',
            $user ? $this->resetEmailHtml($code, $limits->codeMinutes) : $this->noAccountEmailHtml()
        );
        $appKey = '';

        if ($failure === Mailer::FAILED_SIGN_IN) {
            cache()->save($failKey, (int) cache($failKey) + 1, 900);

            return $this->respondError(
                'Your email provider did not accept this email address and app password. Check both, make sure the app password is for this address, and try again.',
                ['app_key' => 'Rejected by the email provider.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($failure === Mailer::FAILED_NETWORK) {
            return $this->respondError(
                'The server could not reach your email provider; it may have no internet connection right now. Try again later, or ask your office manager to set a new password for you.',
                [],
                ResponseInterface::HTTP_SERVICE_UNAVAILABLE
            );
        }
        if ($failure !== null) {
            return $this->respondError('The email could not be sent. Please try again later.', [], ResponseInterface::HTTP_BAD_GATEWAY);
        }

        cache()->delete($failKey);
        $sent[] = time();
        cache()->save($sentKey, $sent, 3600);
        if ($user) {
            $this->storeResetCode($user, $code, $limits->codeMinutes);
            AuditLog::record('auth.reset_code_sent', 'user', (int) $user['user_id'], "Password reset code sent to {$email} from the user's own email account", [], $this->resetActor($user, $email));
        }

        return $this->respondSuccess(null, self::RESET_SENT_MESSAGE);
    }

    /** Saves a new code (hashed) and makes the earlier one and its wrong guesses void. */
    private function storeResetCode(array $user, string $code, int $minutes): void
    {
        (new UserModel())->update($user['user_id'], [
            'password_reset_token'   => $this->resetCodeHash($code, (int) $user['user_id']),
            'password_reset_expires' => date('Y-m-d H:i:s', strtotime("+{$minutes} minutes")),
        ]);
        cache()->delete($this->resetAttemptsKey((string) $user['email']));
    }

    /**
     * Keyed hash of a code for one user. A copy of the database alone isn't enough to work the
     * 6 digits back out (that also needs the encryption key in .env), and a code is only valid
     * for the account it was made for.
     */
    private function resetCodeHash(string $code, int $userId): string
    {
        $secret = (string) (config(\Config\Encryption::class)->key ?? '');

        return $secret !== ''
            ? hash_hmac('sha256', $userId . '|' . $code, $secret)
            : hash('sha256', $userId . '|' . $code);
    }

    private function resetActor(array $user, string $email): array
    {
        return ['id' => (int) $user['user_id'], 'username' => $user['username'] ?? $email, 'user_office_id' => (int) ($user['user_office_id'] ?? 0)];
    }

    private function resetEmailHtml(string $code, int $minutes): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;background:#f8fffd;border-radius:16px;">' .
            '<h2 style="color:#0f3d3e;margin-bottom:16px;">Password Reset Code</h2>' .
            '<p style="color:#475569;line-height:1.6;">You requested a password reset for your BSU Inventory account. Use the verification code below:</p>' .
            '<div style="text-align:center;margin:28px 0;">' .
            '<div style="display:inline-block;padding:18px 40px;background:linear-gradient(135deg,#0f766e,#115e59);color:#fff;border-radius:14px;font-size:32px;font-weight:700;letter-spacing:8px;">' . esc($code) . '</div>' .
            '</div>' .
            '<p style="color:#94a3b8;font-size:13px;">This code will expire in ' . $minutes . ' minutes. If you did not request this, you can safely ignore this email, and consider changing your email account\'s app password.</p>' .
            '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">' .
            '<p style="color:#cbd5e1;font-size:12px;">BSU Integrated Inventory Monitoring System</p>' .
            '</div>';
    }

    private function noAccountEmailHtml(): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;background:#f8fffd;border-radius:16px;">' .
            '<h2 style="color:#0f3d3e;margin-bottom:16px;">Password reset request</h2>' .
            '<p style="color:#475569;line-height:1.6;">A password reset was requested on the BSU Inventory System for this email address, but no account uses it. ' .
            'If you have an account, it may use a different email address: ask your office manager.</p>' .
            '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">' .
            '<p style="color:#cbd5e1;font-size:12px;">BSU Integrated Inventory Monitoring System</p>' .
            '</div>';
    }

    // ════════════════════════════════════════════════════════════════
    //  VERIFY CODE + RESET PASSWORD (public)
    // ════════════════════════════════════════════════════════════════

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
     * The user the reset code belongs to, or an error message. Every wrong guess is counted per
     * email address, whether or not an account or a code exists, and the replies are the same,
     * so they don't reveal which addresses have accounts. After maxCodeAttempts the code is void.
     */
    private function checkResetCode(string $email, string $code): array|string
    {
        $max      = config(PasswordReset::class)->maxCodeAttempts;
        $key      = $this->resetAttemptsKey($email);
        $attempts = (int) cache($key);
        if ($attempts >= $max) {
            return 'Too many incorrect attempts. Please request a new code.';
        }

        $model = new UserModel();
        $user  = $model->findByEmail($email);
        if ($user
            && ! empty($user['password_reset_token'])
            && strtotime((string) $user['password_reset_expires']) > time()
            && hash_equals((string) $user['password_reset_token'], $this->resetCodeHash($code, (int) $user['user_id']))) {
            return $user;
        }

        $attempts++;
        cache()->save($key, $attempts, 900);
        if ($attempts >= $max) {
            if ($user) {
                $model->update($user['user_id'], ['password_reset_token' => null, 'password_reset_expires' => null]);
            }

            return 'Too many incorrect attempts. Please request a new code.';
        }

        $left = $max - $attempts;
        return "The code is incorrect or has expired. {$left} " . ($left === 1 ? 'attempt' : 'attempts') . ' left.';
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
            'password'         => 'required|min_length[8]|max_length[255]',
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
        AuditLog::record('auth.password_reset', 'user', (int) $user['user_id'], "Password reset by email code for \"{$user['username']}\"", [], [
            'id' => (int) $user['user_id'], 'username' => $user['username'], 'user_office_id' => (int) ($user['user_office_id'] ?? 0),
        ]);

        return $this->respondSuccess(null, 'Password has been reset successfully. You can now log in with your new password.');
    }

    // ── Failed-login lock ────────────────────────────────────────────────
    // Counted in the cache. 5 misses on a username from one IP lock that username on that IP,
    // 20 misses from one IP lock the IP, and 50 misses on a username from all IPs together lock
    // the username everywhere; each lock lasts 15 minutes. A username alone can't be locked
    // from a single device, so its owner can still log in from their own.

    private function loginKey(string $kind, string $value): string
    {
        return 'login_' . $kind . '_' . md5(mb_strtolower(trim($value)));
    }

    /** Cache key part for one username on one IP. */
    private function userOnIp(string $username): string
    {
        return mb_strtolower(trim($username)) . '|' . $this->request->getIPAddress();
    }

    /** Minutes until logins are allowed again for this username from this IP, or 0. */
    private function loginLockedFor(string $username): int
    {
        $ip    = (string) $this->request->getIPAddress();
        $until = max(
            (int) cache($this->loginKey('lock_pair', $this->userOnIp($username))),
            (int) cache($this->loginKey('lock_ip', $ip)),
            (int) cache($this->loginKey('lock_user', $username))
        );

        return $until > time() ? (int) ceil(($until - time()) / 60) : 0;
    }

    /** Counts a failed login; returns how many tries remain for the username on this IP (0 = now locked). */
    private function recordLoginFailure(string $username): int
    {
        $ip    = (string) $this->request->getIPAddress();
        $until = time() + self::LOGIN_LOCK_SECONDS;
        $count = function (string $kind, string $value, int $max) use ($until): bool {
            $fails = (int) cache($this->loginKey('fail_' . $kind, $value)) + 1;
            if ($fails < $max) {
                cache()->save($this->loginKey('fail_' . $kind, $value), $fails, self::LOGIN_LOCK_SECONDS);

                return false;
            }
            cache()->save($this->loginKey('lock_' . $kind, $value), $until, self::LOGIN_LOCK_SECONDS);
            cache()->delete($this->loginKey('fail_' . $kind, $value));

            return true;
        };

        $ipLocked      = $count('ip', $ip, self::MAX_IP_LOGIN_FAILURES);
        $accountLocked = $count('user', $username, self::MAX_ACCOUNT_LOGIN_FAILURES);
        if ($count('pair', $this->userOnIp($username), self::MAX_LOGIN_FAILURES) || $ipLocked || $accountLocked) {
            return 0;
        }

        return self::MAX_LOGIN_FAILURES - (int) cache($this->loginKey('fail_pair', $this->userOnIp($username)));
    }

    /**
     * After a successful login, only this device's count for the username is cleared: the
     * all-devices count keeps running so guesses spread over many devices are still caught.
     */
    private function clearLoginFailures(string $username): void
    {
        cache()->delete($this->loginKey('fail_pair', $this->userOnIp($username)));
    }

    /**
     * The first pending forced setup step for this session, or null.
     */
    private function pendingSetup(): ?string
    {
        foreach (self::SETUP_FLAGS as $flag) {
            if (session($flag)) {
                return match ($flag) {
                    'must_change_password' => 'change_password',
                };
            }
        }

        return null;
    }
}
