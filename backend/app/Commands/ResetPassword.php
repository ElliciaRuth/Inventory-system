<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sets a random temporary password for an account (for example a locked-out
 * admin_tech). The user must change it at the next login.
 */
class ResetPassword extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'user:reset-password';
    protected $description = 'Give an account a temporary password that must be changed at next login.';
    protected $usage       = 'user:reset-password <username>';
    protected $arguments   = ['username' => 'Account to reset, e.g. admin_tech'];

    public function run(array $params)
    {
        $username = trim((string) ($params[0] ?? ''));
        if ($username === '') {
            CLI::error('Usage: php spark user:reset-password <username>');
            return EXIT_USER_INPUT;
        }

        $model = new UserModel();
        $user  = $model->where('username', $username)->first();
        if (! $user) {
            CLI::error("No account named \"{$username}\".");
            return EXIT_USER_INPUT;
        }

        // Meets the password rules: upper, lower, digits, no 3-digit sequences
        $temporary = 'Tmp-' . bin2hex(random_bytes(3)) . 'X' . random_int(0, 9) . random_int(0, 9);
        while (preg_match('/(?:0(?=1)|1(?=2)|2(?=3)|3(?=4)|4(?=5)|5(?=6)|6(?=7)|7(?=8)|8(?=9)){2}/', $temporary)) {
            $temporary = 'Tmp-' . bin2hex(random_bytes(3)) . 'X' . random_int(0, 9) . random_int(0, 9);
        }

        $model->update($user['user_id'], [
            'password'             => password_hash($temporary, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'user_activity_id'     => 1,
        ]);

        CLI::write("Temporary password for {$username}: " . CLI::color($temporary, 'yellow'));
        CLI::write('It must be changed at the next login.');

        return EXIT_SUCCESS;
    }
}
