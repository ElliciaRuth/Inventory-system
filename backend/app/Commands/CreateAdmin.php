<?php

namespace App\Commands;

use App\Libraries\AuditLog;
use App\Libraries\PasswordPolicy;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Creates the first Technical Staff (level 4) account of a new server, with a password the
 * administrator chose, so no account with a publicly known password ever exists.
 *
 * The Windows installer passes the details in a JSON file (deleted right after reading), so
 * the password never appears on a command line or in a log:
 *   {"first_name": "...", "last_name": "...", "username": "...", "email": "...", "password": "..."}
 * Without --from-file the details are asked for here.
 */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'Inventory';
    protected $name        = 'user:create-admin';
    protected $description = 'Create a Technical Staff (administrator) account with a chosen password.';
    protected $usage       = 'user:create-admin [--from-file <details.json>] [--if-none]';
    protected $options     = [
        '--from-file' => 'Read the details from this JSON file and delete it afterwards.',
        '--if-none'   => 'Do nothing when a Technical Staff account already exists (updates, reinstalls).',
    ];

    public function run(array $params)
    {
        // Read and delete the details file first, whatever happens next: it holds a password
        $file     = CLI::getOption('from-file');
        $fromFile = is_string($file) && $file !== '';
        if ($fromFile) {
            $raw = @file_get_contents($file);
            @unlink($file);
            // Windows tools may start the file with a UTF-8 byte order mark
            $input = is_string($raw) ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw), true) : null;
        }

        $db    = db_connect();
        $level = $db->table('level_of_access')->select('lvl_of_access_id')
            ->where('lvl_of_access', 4)->get(1)->getRowArray();
        if (! $level) {
            CLI::error('The access levels are missing. Run "php spark db:seed ReferenceDataSeeder" first.');

            return EXIT_ERROR;
        }
        $levelId = (int) $level['lvl_of_access_id'];

        if (CLI::getOption('if-none') !== null
            && $db->table('user_table')->where('lvl_of_access_id', $levelId)->countAllResults() > 0
        ) {
            CLI::write('A Technical Staff account already exists; none was created.');

            return EXIT_SUCCESS;
        }

        if ($fromFile) {
            if (! is_array($input)) {
                CLI::error('The details file is missing or is not valid JSON.');

                return EXIT_USER_INPUT;
            }
        } else {
            $input = [
                'first_name' => CLI::prompt('First name', null, 'required'),
                'last_name'  => CLI::prompt('Last name', null, 'required'),
                'username'   => CLI::prompt('Username', null, 'required'),
                'email'      => CLI::prompt('Email', null, 'required'),
                'password'   => CLI::prompt('Password (shown while typing)', null, 'required'),
            ];
        }

        $first    = trim(preg_replace('/\s+/u', ' ', (string) ($input['first_name'] ?? '')));
        $last     = trim(preg_replace('/\s+/u', ' ', (string) ($input['last_name'] ?? '')));
        $username = trim((string) ($input['username'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $error = $this->nameError($first, 'First name')
            ?? $this->nameError($last, 'Last name')
            ?? (preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) ? null
                : 'Username must be 3 to 50 letters, digits, dots, hyphens or underscores.')
            ?? (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255 ? null : 'Enter a valid email address.')
            ?? PasswordPolicy::weakness($password);
        if ($error === null) {
            $model = new UserModel();
            if ($model->where('username', $username)->countAllResults() > 0) {
                $error = "The username \"{$username}\" is already taken.";
            } elseif ($model->where('email', $email)->countAllResults() > 0) {
                $error = 'An account with this email address already exists.';
            }
        }
        if ($error !== null) {
            CLI::error($error);

            return EXIT_USER_INPUT;
        }

        $id = (new UserModel())->insert([
            'first_name'           => $first,
            'last_name'            => $last,
            'name'                 => "{$first} {$last}",
            'username'             => $username,
            'email'                => $email,
            'password'             => password_hash($password, PASSWORD_DEFAULT),
            'user_office_id'       => null, // Technical Staff work across all offices
            'lvl_of_access_id'     => $levelId,
            'user_activity_id'     => 1,    // Active
            'must_change_password' => 0,    // chosen by the administrator, not a public default
        ]);
        AuditLog::record('user.created', 'user', (int) $id, 'Administrator account created during server setup', [
            'username' => $username,
        ], ['username' => 'server setup']);

        CLI::write("Created the Technical Staff account \"{$username}\".", 'green');

        return EXIT_SUCCESS;
    }

    /** Same rule as the sign-up form: letters, spaces, hyphens, apostrophes and periods. */
    private function nameError(string $name, string $label): ?string
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            return "{$label} must be 2 to 100 characters long.";
        }

        return preg_match("/^\\p{L}[\\p{L} .'\\-]*$/u", $name)
            ? null
            : "{$label} can only contain letters, spaces, hyphens (-), apostrophes (') and periods (.).";
    }
}
