<?php

namespace App\Models;

use CodeIgniter\Model;

class SmtpSettingsModel extends Model
{
    protected $table         = 'smtp_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'smtp_email', 'smtp_password', 'configured_by',
    ];

    /**
     * Get the most recently configured SMTP settings.
     */
    public function getActive(): ?array
    {
        return $this->orderBy('id', 'DESC')->first();
    }
}
