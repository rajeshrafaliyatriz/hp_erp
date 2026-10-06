-- Reversal for 2026_09_07_110000_canonicalise_payroll_month.
-- Applied to 202.47.117.220/hp_erp on 2026-09-07.
--
-- WHAT WAS DONE
--   1. employee_monthly_salary_data.month  'july' -> 'Jul'   (17 rows)
--   2. those 17 collapsed to 1, keeping id 5 (the earliest, so the payslip's
--      original created_at survives)
--   3. staff_document id 8 renamed july -> Jul so the next save's checkDoc
--      lookup, which keys on file_name, still matches
--   4. UNIQUE (sub_institute_id, employee_id, year, month) added
--
-- WHY THIS WAS SAFE ON REAL PAYSLIPS
--   All 17 rows were byte-identical in every figure -- one distinct value each
--   for total_payment (35000.00), total_deduction (100.00), total_day (10.00)
--   and employee_salary_data ({"6":"7000"}). They differed only in id and
--   created_at. Collapsing them lost NO financial information.
--
-- TO REVERSE, IN THIS ORDER:
--
--   1. Drop the index first -- the INSERTs below violate it, which is the point:
--        ALTER TABLE employee_monthly_salary_data
--          DROP INDEX employee_monthly_salary_data_period_unique;
--
--   2. Remove the surviving row, so the originals go back with their own ids:
--        DELETE FROM employee_monthly_salary_data WHERE id = 5;
--
--   3. Restore the document name:
--        UPDATE staff_document
--           SET file_name = 'emp_1_payslip_july_2026.pdf',
--               document_title = 'Payslip july 2026'
--         WHERE id = 8;
--
--   4. Run the INSERTs at the bottom of this file.
--
-- WHAT REVERSING COSTS YOU
--   The seventeen rows come back, and with them the defect: they are spelled
--   'july', the screen posts 'Jul', and every query that matches on month --
--   the upsert, the payslip delete, the PDF lookup, the annual Form 16 report,
--   the month lock and My HR's ordering -- stops seeing them again.
--
-- WHAT IT CANNOT PUT BACK
--   The payslip PDF in DigitalOcean Spaces still carries its original object
--   name. The rename above only changes the database row that points at it, so
--   after a reverse the pointer is correct again -- but if the month has been
--   re-saved since, a second object now exists under the new name. Neither is
--   deleted by this script; the unreferenced one is harmless and worth tidying
--   by hand.
--
--   The supersession is also recorded in g2g_event as
--   'payroll.payslip.superseded' with the full before-image. That store is
--   append-only by design, so reversing the data does NOT remove the event --
--   correctly: it happened.
--
-- ---------------------------------------------------------------------------
-- THE 17 ORIGINAL ROWS, verbatim:

INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('5', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 06:54:19', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('6', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:01:27', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('7', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:03:19', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('8', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:05:03', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('9', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:09:27', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('10', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:10:39', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('11', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:12:20', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('12', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:12:40', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('13', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:12:56', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('14', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:15:05', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('15', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:16:13', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('16', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:17:10', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('17', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 07:23:14', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('18', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 08:58:31', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('19', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 08:58:52', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('20', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 08:59:50', NULL, NULL);
INSERT INTO employee_monthly_salary_data (`id`, `sub_institute_id`, `total_deduction`, `total_payment`, `employee_id`, `received_by`, `month`, `total_day`, `year`, `employee_salary_data`, `salary_amount`, `created_by`, `updated_by`, `deleted_by`, `created_at`, `updated_at`, `deleted_at`)
  VALUES ('21', '1', '100.00', '35000.00', '1', 'Bank 1', 'july', '10.00', '2026', '{"6":"7000"}', NULL, NULL, NULL, NULL, '2025-10-06 09:00:36', NULL, NULL);
