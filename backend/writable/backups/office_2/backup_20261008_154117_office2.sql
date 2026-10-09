-- ================================================
-- BSU Inventory Backup
-- Office ID : 2
-- Generated : 2026-10-08 15:41:17
-- ================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- -- Table: `user_office_table` --
DELETE FROM `user_office_table`;
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('1', 'BAKERY') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);
INSERT INTO `user_office_table` (`user_office_id`, `user_office_name`) VALUES ('2', 'FPC') ON DUPLICATE KEY UPDATE `user_office_id` = VALUES(`user_office_id`), `user_office_name` = VALUES(`user_office_name`);

-- -- Table: `level_of_access` --
DELETE FROM `level_of_access`;
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('1', 'Staff', '1') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('2', 'Custodian', '2') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('3', 'Manager', '3') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);
INSERT INTO `level_of_access` (`lvl_of_access_id`, `role`, `lvl_of_access`) VALUES ('4', 'Technical Staff', '4') ON DUPLICATE KEY UPDATE `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `role` = VALUES(`role`), `lvl_of_access` = VALUES(`lvl_of_access`);

-- -- Table: `user_activity_table` --
DELETE FROM `user_activity_table`;
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('1', 'Active') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('2', 'Deactivated') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);
INSERT INTO `user_activity_table` (`user_activity_id`, `user_activity`) VALUES ('3', 'Pending') ON DUPLICATE KEY UPDATE `user_activity_id` = VALUES(`user_activity_id`), `user_activity` = VALUES(`user_activity`);

-- -- Table: `adjustment_reason` --
DELETE FROM `adjustment_reason`;
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('1', 'Correction') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('2', 'Spoiled') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('3', 'Damaged') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('4', 'Lost') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);
INSERT INTO `adjustment_reason` (`adjustment_reason_id`, `adjustment_reason`) VALUES ('5', 'Expired') ON DUPLICATE KEY UPDATE `adjustment_reason_id` = VALUES(`adjustment_reason_id`), `adjustment_reason` = VALUES(`adjustment_reason`);

-- -- Table: `transaction_type_table` --
DELETE FROM `transaction_type_table`;
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('1', 'receipt') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('2', 'issue') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('3', 'adjust_out') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('4', 'borrow') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);
INSERT INTO `transaction_type_table` (`transaction_type_id`, `transaction_type`) VALUES ('5', 'return') ON DUPLICATE KEY UPDATE `transaction_type_id` = VALUES(`transaction_type_id`), `transaction_type` = VALUES(`transaction_type`);

-- -- Table: `entity_table` --
DELETE FROM `entity_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `unit_table` --
DELETE FROM `unit_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `type_of_product` --
DELETE FROM `type_of_product` WHERE `user_office_id` = 2;
INSERT INTO `type_of_product` (`type_id`, `type`, `user_office_id`) VALUES ('2', 'Finished Product', '2') ON DUPLICATE KEY UPDATE `type_id` = VALUES(`type_id`), `type` = VALUES(`type`), `user_office_id` = VALUES(`user_office_id`);

-- -- Table: `reference_table` --
DELETE FROM `reference_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `office_table` --
DELETE FROM `office_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `user_table` --
DELETE FROM `user_table` WHERE `user_office_id` = 2;
INSERT INTO `user_table` (`user_id`, `name`, `first_name`, `last_name`, `middle_name`, `suffix`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`, `must_change_password`, `password_reset_token`, `password_reset_expires`) VALUES ('5', 'FPC Staff', 'FPC', 'Staff', '', '', '2', 'staff_fpc', 'staff_fpc@bsu.edu.ph', '$2y$10$9LbLxMgMvMAxbh/tRwdupeVQVW5xYpMcdYVxCZjLXRs8WPaIuS6Sm', '1', '1', '0', NULL, NULL) ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `name` = VALUES(`name`), `first_name` = VALUES(`first_name`), `last_name` = VALUES(`last_name`), `middle_name` = VALUES(`middle_name`), `suffix` = VALUES(`suffix`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`), `must_change_password` = VALUES(`must_change_password`), `password_reset_token` = VALUES(`password_reset_token`), `password_reset_expires` = VALUES(`password_reset_expires`);
INSERT INTO `user_table` (`user_id`, `name`, `first_name`, `last_name`, `middle_name`, `suffix`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`, `must_change_password`, `password_reset_token`, `password_reset_expires`) VALUES ('6', 'General Custodian', 'General', 'Custodian', '', '', '2', 'custodian', 'custodian@bsu.edu.ph', '$2y$10$p/7WO5mcFJx4rcbFtcQgZeIJleCUM5CI2Q/u3hdDnpWujCdM/iFZK', '2', '1', '0', NULL, NULL) ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `name` = VALUES(`name`), `first_name` = VALUES(`first_name`), `last_name` = VALUES(`last_name`), `middle_name` = VALUES(`middle_name`), `suffix` = VALUES(`suffix`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`), `must_change_password` = VALUES(`must_change_password`), `password_reset_token` = VALUES(`password_reset_token`), `password_reset_expires` = VALUES(`password_reset_expires`);
INSERT INTO `user_table` (`user_id`, `name`, `first_name`, `last_name`, `middle_name`, `suffix`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`, `must_change_password`, `password_reset_token`, `password_reset_expires`) VALUES ('8', 'FPC Custodian', 'FPC', 'Custodian', '', '', '2', 'custodian_fpc', 'custodian_fpc@bsu.edu.ph', '$2y$10$xIGshqc2QXW7NKbn/zJPyu3S/plDsCGHcKN.bjc3z4y6bXXRohRai', '2', '1', '0', NULL, NULL) ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `name` = VALUES(`name`), `first_name` = VALUES(`first_name`), `last_name` = VALUES(`last_name`), `middle_name` = VALUES(`middle_name`), `suffix` = VALUES(`suffix`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`), `must_change_password` = VALUES(`must_change_password`), `password_reset_token` = VALUES(`password_reset_token`), `password_reset_expires` = VALUES(`password_reset_expires`);
INSERT INTO `user_table` (`user_id`, `name`, `first_name`, `last_name`, `middle_name`, `suffix`, `user_office_id`, `username`, `email`, `password`, `lvl_of_access_id`, `user_activity_id`, `must_change_password`, `password_reset_token`, `password_reset_expires`) VALUES ('11', 'FPC Manager', 'FPC', 'Manager', '', '', '2', 'manager_fpc', 'manager_fpc@bsu.edu.ph', '$2y$10$AfD1CnnglbpGaPKMVqP9COruahKGEIWMqJiWVEfFBojFYAGmuFI7q', '3', '1', '0', NULL, NULL) ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `name` = VALUES(`name`), `first_name` = VALUES(`first_name`), `last_name` = VALUES(`last_name`), `middle_name` = VALUES(`middle_name`), `suffix` = VALUES(`suffix`), `user_office_id` = VALUES(`user_office_id`), `username` = VALUES(`username`), `email` = VALUES(`email`), `password` = VALUES(`password`), `lvl_of_access_id` = VALUES(`lvl_of_access_id`), `user_activity_id` = VALUES(`user_activity_id`), `must_change_password` = VALUES(`must_change_password`), `password_reset_token` = VALUES(`password_reset_token`), `password_reset_expires` = VALUES(`password_reset_expires`);

-- -- Table: `product_table` --
DELETE FROM `product_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `batch_table` --
DELETE FROM `batch_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `transaction_table` --
DELETE FROM `transaction_table` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `temp_stockout` --
DELETE FROM `temp_stockout` WHERE `user_office_id` = 2;
-- (no rows)

-- -- Table: `temp_stockout_item` --
DELETE FROM `temp_stockout_item` WHERE `user_office_id` = 2;
-- (no rows)


SET FOREIGN_KEY_CHECKS = 1;
