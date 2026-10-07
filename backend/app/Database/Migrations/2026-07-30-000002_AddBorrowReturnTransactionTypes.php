<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBorrowReturnTransactionTypes extends Migration
{
    public function up()
    {
        // Insert only if not already present (safe to re-run)
        $db = db_connect();

        $existing = $db->table('transaction_type_table')
            ->whereIn('transaction_type', ['borrow', 'return'])
            ->countAllResults();

        if ($existing === 0) {
            
            $db->table('transaction_type_table')->insertBatch([
                ['transaction_type' => 'borrow'],
                ['transaction_type' => 'return'],
            ]);
        }
    }

    public function down()
    {
        db_connect()->table('transaction_type_table')
            ->whereIn('transaction_type', ['borrow', 'return'])
            ->delete();
    }
}
