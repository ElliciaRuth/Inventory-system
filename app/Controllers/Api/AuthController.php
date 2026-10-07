<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseApiController
{
    /**
     * Authenticate user credentials and start session.
     * POST /api/auth/login
     */
    public function login(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?? $this->request->getPost();
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

        $levelId = (int) ($user['level_id'] ?? 0);
        $officeId = (int) ($user['user_office_id'] ?? 0);

        // Fetch office name
        $officeRow = db_connect()->table('user_office_table')->where('user_office_id', $officeId)->get(1)->getRowArray();
        $officeName = $officeRow['user_office_name'] ?? 'General Office';

        $sessionData = [
            'id'             => (int) $user['user_id'],
            'username'       => $user['username'],
            'email'          => $user['email'] ?? '',
            'role'           => $user['role'] ?? '',
            'level_id'       => $levelId,
            'user_office_id' => $officeId,
            'office_name'    => $officeName,
        ];

        session()->regenerate();
        session()->set('user', $sessionData);
        session()->set('login_time', time());

        return $this->respondSuccess([
            'user' => $sessionData,
        ], 'Login successful');
    }

    /**
     * Get current authenticated user profile.
     * GET /api/auth/me
     */
    public function me(): ResponseInterface
    {
        $user = session('user');
        if (! $user) {
            // Also check if any active user exists in DB for auto-fallback/demo convenience
            return $this->respond([
                'status'        => false,
                'authenticated' => false,
                'user'          => null,
                'message'       => 'Not authenticated',
            ], ResponseInterface::HTTP_OK);
        }

        return $this->respondSuccess([
            'authenticated' => true,
            'user'          => $user,
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
        try {
            $input = $this->request->getJSON(true) ?? $this->request->getPost();
        } catch (\Throwable $e) {
            $raw = (string) $this->request->getBody();
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : $this->request->getPost();
        }
        if (empty($input)) {
            $raw = (string) $this->request->getBody();
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : [];
        }

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

        $password = (string) ($input['password'] ?? '');
        if (! preg_match('/[A-Z]/', $password)) {
            return $this->respondError('Password must contain at least one uppercase letter.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (! preg_match('/[a-z]/', $password)) {
            return $this->respondError('Password must contain at least one lowercase letter.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (! preg_match('/[0-9]/', $password)) {
            return $this->respondError('Password must contain at least one number.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (preg_match('/(?:0(?=1)|1(?=2)|2(?=3)|3(?=4)|4(?=5)|5(?=6)|6(?=7)|7(?=8)|8(?=9)){2}/', $password)) {
            return $this->respondError('Password must not contain sequential numbers (e.g. 123, 456).', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $firstName  = trim((string) ($input['first_name'] ?? ''));
        $lastName   = trim((string) ($input['last_name'] ?? ''));
        $middleName = trim((string) ($input['middle_name'] ?? ''));
        $suffix     = trim((string) ($input['suffix'] ?? ''));

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

    private function passwordMatches(string $input, string $stored): bool
    {
        if (password_get_info($stored)['algo']) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }
}
