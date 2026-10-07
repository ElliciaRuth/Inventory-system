<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUniqueProductNoPerOffice extends Migration
{
    public function up()
    {
        // Add a unique composite index so product_no must be unique within each office
        $this->db->query('ALTER TABLE product_table ADD UNIQUE KEY uq_product_no_per_office (product_no, user_office_id)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE product_table DROP INDEX uq_product_no_per_office');
    }
}
