-- Reversal for the Sprint 9 migrations as applied to 128.199.17.97/hp_erp
-- (the connection NAMED `live`, MariaDB 10.1.48 — NOT the host .env points at).
-- Applied 2026-09-07.
--
-- The app's own database is 202.47.117.220 and has its own reversal script;
-- the ids and timestamps differ between the two, so this file is that host's,
-- captured from it directly rather than copied.
--
-- TO REVERSE, IN THIS ORDER:
--   1. ALTER TABLE employee_monthly_salary_data
--        DROP INDEX employee_monthly_salary_data_period_unique;
--   2. DELETE FROM employee_monthly_salary_data WHERE id = 5;
--   3. UPDATE staff_document SET file_name = 'emp_1_payslip_july_2026.pdf',
--          document_title = 'Payslip july 2026' WHERE file_name LIKE '%payslip_Jul_2026%';
--   4. Run the INSERTs below.
--
-- Reversing restores the defect: seventeen rows spelled 'july' that no query
-- matching on month can see. Read SPRINT-9-UNVERIFIED-REVIEW.md first.

INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('5', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:24:19', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('6', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:31:27', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('7', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:33:19', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('8', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:35:03', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('9', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:39:27', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('10', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:40:39', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('11', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:42:20', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('12', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:42:40', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('13', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:42:56', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('14', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:45:05', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('15', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:46:13', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('16', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:47:10', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('17', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:53:14', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('18', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 11:28:31', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('19', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 11:28:52', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('20', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 11:29:50', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('21', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 11:30:36', NULL, NULL);
