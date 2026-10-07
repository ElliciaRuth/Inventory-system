<?php

namespace App\Controllers\Api;

use App\Models\SettingsModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class AdminController extends BaseApiController
{
    private SettingsModel $settingsModel;

    public function __construct()
    {
        $this->settingsModel = new SettingsModel();
    }

    /**
     * Get admin management data (Users, pending users, offices, definitions)
     * GET /api/admin/data
     */
    public function data(): ResponseInterface
    {
        $officeId = $this->currentOfficeId();
        $levelId  = max(3, $this->currentLevelId());

        $data = $this->settingsModel->indexData($officeId, $levelId);
        return $this->respondSuccess($data, 'Admin data retrieved');
    }

    /**
     * Activate a pending or deactivated user account
     * POST /api/admin/users/activate/{id}
     */
    public function activateUser($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->respondError('Invalid user ID', [], ResponseInterface::HTTP_BAD_REQUEST);
        }

        try {
            $this->settingsModel->activateUser($id);
            return $this->respondSuccess(null, 'User activated successfully');
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage());
        }
    }

    /**
     * Deactivate an active user account
     * POST /api/admin/users/deactivate/{id}
     */
    public function deactivateUser($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->respondError('Invalid user ID', [], ResponseInterface::HTTP_BAD_REQUEST);
        }

        if ($this->currentUserId() === $id) {
            return $this->respondError('You cannot deactivate the currently logged-in account.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->settingsModel->deactivateUser($id);
            return $this->respondSuccess(null, 'User deactivated successfully');
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage());
        }
    }

    /**
     * Delete a record (user, office, etc.)
     * DELETE /api/admin/records/{type}/{id}
     */
    public function deleteRecord($type = null, $id = null): ResponseInterface
    {
        $type = (string) $type;
        $id   = (int) $id;

        $allowed = ['users', 'user_office_table', 'entity_table', 'unit_table', 'reference_table', 'type_of_product', 'office_table'];
        if (! in_array($type, $allowed, true)) {
            return $this->respondError('Invalid resource type.', [], ResponseInterface::HTTP_BAD_REQUEST);
        }

        if ($type === 'users') {
            if ($this->currentUserId() === $id) {
                return $this->respondError('Cannot delete yourself.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
            $userModel = new UserModel();
            $userModel->delete($id);
            return $this->respondSuccess(null, 'User deleted successfully.');
        }

        try {
            $this->settingsModel->deleteRecord($type, $id, $this->currentOfficeId());
            return $this->respondSuccess(null, 'Record deleted successfully.');
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Save/Create a record (e.g. user_office_table, entity_table)
     * POST /api/admin/save/{type}
     */
    public function saveRecord($type = null): ResponseInterface
    {
        $type  = (string) $type;
        $input = $this->request->getJSON(true) ?? $this->request->getPost();
        $id    = (int) ($input['id'] ?? 0);

        if ($type === 'user_office_table') {
            $officeName = trim((string) ($input['user_office_name'] ?? ''));
            if ($officeName === '') {
                return $this->respondError('Office name is required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($id > 0) {
                db_connect()->table('user_office_table')->where('user_office_id', $id)->update(['user_office_name' => $officeName]);
                return $this->respondSuccess(null, 'Office updated successfully.');
            }

            db_connect()->table('user_office_table')->insert(['user_office_name' => $officeName]);
            return $this->respondSuccess(null, 'Office created successfully.', ResponseInterface::HTTP_CREATED);
        }

        try {
            $this->settingsModel->saveRecord($type, $id, $input, $this->currentOfficeId());
            return $this->respondSuccess(null, 'Record saved successfully.');
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
