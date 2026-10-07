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
}
