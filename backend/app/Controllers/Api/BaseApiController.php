<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
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
