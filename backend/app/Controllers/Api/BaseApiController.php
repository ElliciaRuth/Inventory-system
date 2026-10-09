<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\UserModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

abstract class BaseApiController extends BaseController
{
    use ResponseTrait;

    /**
     * Standard success JSON response.
     */
    protected function respondSuccess(
        mixed $data = null,
        string $message = 'Success',
        int $code = ResponseInterface::HTTP_OK,
        array $extra = []
    ): ResponseInterface {
        $payload = array_merge([
            'status'  => true,
            'message' => $message,
            'data'    => $data,
        ], $extra);

        return $this->respond($payload, $code);
    }

    /**
     * Standard error JSON response.
     */
    protected function respondError(
        string $message = 'Error',
        array $errors = [],
        int $code = ResponseInterface::HTTP_BAD_REQUEST
    ): ResponseInterface {
        return $this->respond([
            'status'  => false,
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * Request body as an array — JSON body if present, otherwise form fields.
     */
    protected function input(): array
    {
        try {
            $json = $this->request->getJSON(true);
        } catch (\Throwable) {
            $json = null;
        }

        if (is_array($json)) {
            return $json;
        }

        $post = $this->request->getPost();
        if (! empty($post)) {
            return $post;
        }

        return $this->request->getRawInput();
    }

    /**
     * Extract authenticated user ID, level ID, or office ID.
     */
    protected function currentOfficeId(): int
    {
        return (int) (session('user')['user_office_id'] ?? 0);
    }

    protected function currentUserId(): int
    {
        return (int) (session('user')['id'] ?? 0);
    }

    protected function currentLevelId(): int
    {
        return (int) (session('user')['level_id'] ?? 0);
    }

    protected function currentUser(): ?array
    {
        return session('user') ?? null;
    }

    /** Hashed passwords are verified; legacy plain-text ones compared in constant time. */
    protected function passwordMatches(string $input, string $stored): bool
    {
        if (password_get_info($stored)['algo']) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }

    /** Re-checks the signed-in user's password before a destructive action. */
    protected function currentPasswordMatches(string $input): bool
    {
        if ($input === '' || $this->currentUserId() <= 0) {
            return false;
        }
        $stored = (string) ((new UserModel())->find($this->currentUserId())['password'] ?? '');

        return $stored !== '' && $this->passwordMatches($input, $stored);
    }

    /** Shortest password accepted anywhere a password is set. */
    protected const PASSWORD_MIN_LENGTH = 8;

    /** Why a new password is too weak, or null when it is acceptable. */
    protected function passwordStrengthError(string $password): ?string
    {
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            return 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters long.';
        }
        if (strlen($password) > 72) {
            // bcrypt ignores everything after 72 bytes
            return 'Password must be at most 72 characters long.';
        }
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

    /** The access level (1–4) a level_of_access row stands for, or 0 when there is no such row. */
    protected function accessLevelOf(int $lvlOfAccessId): int
    {
        $row = db_connect()->table('level_of_access')->select('lvl_of_access')
            ->where('lvl_of_access_id', $lvlOfAccessId)->get(1)->getRowArray();

        return (int) ($row['lvl_of_access'] ?? 0);
    }

    protected function userOfficeExists(int $userOfficeId): bool
    {
        return $userOfficeId > 0
            && db_connect()->table('user_office_table')->where('user_office_id', $userOfficeId)->countAllResults() > 0;
    }

    // ── Person names ─────────────────────────────────────────────────────

    /** Allowed name suffixes, keyed by their lowercase form without dots */
    private const NAME_SUFFIXES = [
        'jr' => 'Jr.', 'sr' => 'Sr.', 'ii' => 'II', 'iii' => 'III', 'iv' => 'IV', 'v' => 'V', 'vi' => 'VI',
    ];

    /**
     * Collapses repeated spaces and capitalises the first letter of each word
     * ("eduardo  dela cruz" → "Eduardo Dela Cruz"); other letters are kept as typed.
     */
    protected function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return preg_replace_callback("/(^|[\\s\\-'])(\\p{Ll})/u", static fn ($m) => $m[1] . mb_strtoupper($m[2]), $name);
    }

    /**
     * Error message when a name contains anything other than letters, spaces,
     * hyphens, apostrophes and periods (and commas, when $allowComma — full
     * names stored as "First Last, Jr."), or null when it is valid.
     */
    protected function nameError(string $name, string $label, bool $allowComma = false): ?string
    {
        if ($name === '') {
            return null;
        }
        $pattern = $allowComma ? "/^\p{L}[\p{L} .,'\-]*$/u" : "/^\p{L}[\p{L} .'\-]*$/u";

        return preg_match($pattern, $name)
            ? null
            : "{$label} can only contain letters, spaces, hyphens (-), apostrophes (') and periods (.).";
    }

    /** Canonical suffix ("jr" → "Jr."), '' for blank, or null when not a recognised suffix */
    protected function normalizeSuffix(string $suffix): ?string
    {
        $key = strtolower(str_replace('.', '', trim($suffix)));
        if ($key === '') {
            return '';
        }

        return self::NAME_SUFFIXES[$key] ?? null;
    }
}
