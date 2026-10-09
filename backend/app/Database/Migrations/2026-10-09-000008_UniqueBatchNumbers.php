<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Batch numbers become unique. Batches received the same day for the same product
 * used to share one number; the oldest keeps it and the others get -02, -03… appended.
 * A unique index then keeps it that way.
 */
class UniqueBatchNumbers extends Migration
{
    public function up()
    {
        $db = $this->db;

        // Batches without a number get one from their id
        $db->query("UPDATE batch_table SET batch_no = CONCAT('B-', batch_id) WHERE batch_no IS NULL OR batch_no = ''");

        $taken = array_flip(array_column($db->table('batch_table')->select('batch_no')->get()->getResultArray(), 'batch_no'));

        $duplicates = $db->query(
            'SELECT batch_no FROM batch_table GROUP BY batch_no HAVING COUNT(*) > 1'
        )->getResultArray();

        foreach ($duplicates as $dup) {
            $rows = $db->table('batch_table')->select('batch_id')
                ->where('batch_no', $dup['batch_no'])
                ->orderBy('batch_id', 'ASC')
                ->get()->getResultArray();

            $seq = 1;
            foreach (array_slice($rows, 1) as $row) {
                do {
                    $seq++;
                    $candidate = $dup['batch_no'] . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
                } while (isset($taken[$candidate]));

                $taken[$candidate] = true;
                $db->table('batch_table')->where('batch_id', $row['batch_id'])->update(['batch_no' => $candidate]);
            }
        }

        $indexes = array_column($db->query("SHOW INDEX FROM batch_table WHERE Key_name = 'uniq_batch_no'")->getResultArray(), 'Key_name');
        if ($indexes === []) {
            $db->query('ALTER TABLE batch_table ADD UNIQUE KEY uniq_batch_no (batch_no)');
        }
    }

    public function down()
    {
        $indexes = $this->db->query("SHOW INDEX FROM batch_table WHERE Key_name = 'uniq_batch_no'")->getResultArray();
        if ($indexes !== []) {
            $this->db->query('ALTER TABLE batch_table DROP INDEX uniq_batch_no');
        }
    }
}
