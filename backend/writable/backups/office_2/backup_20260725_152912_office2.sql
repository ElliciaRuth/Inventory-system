-- ════════════════════════════════════════════════
-- BSU Inventory Backup
-- Office ID : 2
-- Generated : 2026-07-25 15:28:38
-- ════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── Table: `user_office_table` ──
DELETE FROM `user_office_table`;
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('1', 'BAKERY') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('2', 'FPC') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);

-- ── Table: `level_of_access` ──
DELETE FROM `level_of_access`;
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('1', 'Staff', '1') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('2', 'Custodian', '2') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('3', 'Manager', '3') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('4', 'Technical Staff', '4') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);

-- ── Table: `user_activity_table` ──
DELETE FROM `user_activity_table`;
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('1', 'Active') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('2', 'Deactivated') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('3', 'Pending') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);

-- ── Table: `adjustment_reason` ──
DELETE FROM `adjustment_reason`;
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('1', 'Correction') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('2', 'Spoiled') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('3', 'Damaged') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('4', 'Lost') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('5', 'Expired') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);

-- ── Table: `transaction_type_table` ──
DELETE FROM `transaction_type_table`;
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('1', 'receipt') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('2', 'issue') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('3', 'adjust_out') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);

-- ── Table: `entity_table` ──
DELETE FROM `entity_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `unit_table` ──
DELETE FROM `unit_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `type_of_product` ──
DELETE FROM `type_of_product` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `reference_table` ──
DELETE FROM `reference_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `office_table` ──
DELETE FROM `office_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `user_table` ──
DELETE FROM `user_table` WHERE `user_office_id` = 2;
INSERT INTO `user_table` (`user_id`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`) VALUES ('4', '2', 'manager_FPC', 'manager_FPC@gmail.com', '$2y$10$ezRUUb6LZ.ckZHkB/ZDg5.JD83Gf6faPLgC3B51eoD690PAy8OJ9u', '3', '1') ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`);

-- ── Table: `product_table` ──
DELETE FROM `product_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `batch_table` ──
DELETE FROM `batch_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `transaction_table` ──
DELETE FROM `transaction_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `temp_stockout` ──
DELETE FROM `temp_stockout` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `temp_stockout_item` ──
DELETE FROM `temp_stockout_item` WHERE `user_office_id` = 2;
-- (no rows)


SET FOREIGN_KEY_CHECKS = 1;


-- ═══════════════════════════════════════
-- Backup appended: 20260725_152911
-- ═══════════════════════════════════════

-- ════════════════════════════════════════════════
-- BSU Inventory Backup
-- Office ID : 2
-- Generated : 2026-07-25 15:29:11
-- ════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── Table: `user_office_table` ──
DELETE FROM `user_office_table`;
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('1', 'BAKERY') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('2', 'FPC') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);

-- ── Table: `level_of_access` ──
DELETE FROM `level_of_access`;
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('1', 'Staff', '1') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('2', 'Custodian', '2') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('3', 'Manager', '3') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('4', 'Technical Staff', '4') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);

-- ── Table: `user_activity_table` ──
DELETE FROM `user_activity_table`;
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('1', 'Active') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('2', 'Deactivated') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('3', 'Pending') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);

-- ── Table: `adjustment_reason` ──
DELETE FROM `adjustment_reason`;
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('1', 'Correction') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('2', 'Spoiled') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('3', 'Damaged') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('4', 'Lost') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('5', 'Expired') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);

-- ── Table: `transaction_type_table` ──
DELETE FROM `transaction_type_table`;
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('1', 'receipt') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('2', 'issue') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('3', 'adjust_out') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);

-- ── Table: `entity_table` ──
DELETE FROM `entity_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `unit_table` ──
DELETE FROM `unit_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `type_of_product` ──
DELETE FROM `type_of_product` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `reference_table` ──
DELETE FROM `reference_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `office_table` ──
DELETE FROM `office_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `user_table` ──
DELETE FROM `user_table` WHERE `user_office_id` = 2;
INSERT INTO `user_table` (`user_id`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`) VALUES ('4', '2', 'manager_FPC', 'manager_FPC@gmail.com', '$2y$10$ezRUUb6LZ.ckZHkB/ZDg5.JD83Gf6faPLgC3B51eoD690PAy8OJ9u', '3', '1') ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`);

-- ── Table: `product_table` ──
DELETE FROM `product_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `batch_table` ──
DELETE FROM `batch_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `transaction_table` ──
DELETE FROM `transaction_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `temp_stockout` ──
DELETE FROM `temp_stockout` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `temp_stockout_item` ──
DELETE FROM `temp_stockout_item` WHERE `user_office_id` = 2;
-- (no rows)


SET FOREIGN_KEY_CHECKS = 1;


-- ═══════════════════════════════════════
-- Backup appended: 20260725_152912
-- ═══════════════════════════════════════

-- ════════════════════════════════════════════════
-- BSU Inventory Backup
-- Office ID : 2
-- Generated : 2026-07-25 15:29:12
-- ════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── Table: `user_office_table` ──
DELETE FROM `user_office_table`;
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('1', 'BAKERY') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('2', 'FPC') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);

-- ── Table: `level_of_access` ──
DELETE FROM `level_of_access`;
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('1', 'Staff', '1') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('2', 'Custodian', '2') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('3', 'Manager', '3') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('4', 'Technical Staff', '4') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);

-- ── Table: `user_activity_table` ──
DELETE FROM `user_activity_table`;
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('1', 'Active') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('2', 'Deactivated') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('3', 'Pending') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);

-- ── Table: `adjustment_reason` ──
DELETE FROM `adjustment_reason`;
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('1', 'Correction') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('2', 'Spoiled') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('3', 'Damaged') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('4', 'Lost') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('5', 'Expired') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);

-- ── Table: `transaction_type_table` ──
DELETE FROM `transaction_type_table`;
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('1', 'receipt') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('2', 'issue') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('3', 'adjust_out') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);

-- ── Table: `entity_table` ──
DELETE FROM `entity_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `unit_table` ──
DELETE FROM `unit_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `type_of_product` ──
DELETE FROM `type_of_product` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `reference_table` ──
DELETE FROM `reference_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `office_table` ──
DELETE FROM `office_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `user_table` ──
DELETE FROM `user_table` WHERE `user_office_id` = 2;
INSERT INTO `user_table` (`user_id`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`) VALUES ('4', '2', 'manager_FPC', 'manager_FPC@gmail.com', '$2y$10$ezRUUb6LZ.ckZHkB/ZDg5.JD83Gf6faPLgC3B51eoD690PAy8OJ9u', '3', '1') ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`);

-- ── Table: `product_table` ──
DELETE FROM `product_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `batch_table` ──
DELETE FROM `batch_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `transaction_table` ──
DELETE FROM `transaction_table` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `temp_stockout` ──
DELETE FROM `temp_stockout` WHERE `user_office_id` = 2;
-- (no rows)

-- ── Table: `temp_stockout_item` ──
DELETE FROM `temp_stockout_item` WHERE `user_office_id` = 2;
-- (no rows)


SET FOREIGN_KEY_CHECKS = 1;
