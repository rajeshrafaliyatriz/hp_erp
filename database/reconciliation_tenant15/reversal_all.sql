-- =============================================================================
-- COMPLETE TRANSACTIONAL REVERSAL SCRIPT: TENANT 15 RECONCILIATION
-- Database: hp_erp (MariaDB 10.1 on db3 / 128.199.17.97)
-- Tenant: Future Varsity (sub_institute_id = 15)
-- Author: Teamwork Worker 1
-- Date: 2026-11-04
-- =============================================================================

START TRANSACTION;

-- 1. Revert Delivery Activation (R4)
UPDATE `school_setup`
SET `outbound_email_enabled` = 0
WHERE `id` = 15;

DELETE FROM `smtp_details` WHERE `sub_institute_id` = 15;
DELETE FROM `sms_api_details` WHERE `sub_institute_id` = 15;

-- 2. Restore Obsolete Department Placeholder Roles (7300..7315)
INSERT IGNORE INTO `s_user_jobrole` (
    `id`, `industries`, `department`, `department_id`, `jobrole`, `status`, `sub_institute_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `catalogue_jobrole_id`
) VALUES
(7300, 'Institute of Management Studies & Research', 'Institutional Leadership', 2235, 'Head of Institution', 'Active', 15, 479, 479, NOW(), NOW(), 1810),
(7301, 'Institute of Management Studies & Research', 'Academics & Faculty', 2236, 'Academics and Faculty', 'Active', 15, 479, 479, NOW(), NOW(), 1808),
(7302, 'Institute of Management Studies & Research', 'Academics & Faculty', 2236, 'Academics', 'Active', 15, 479, 479, NOW(), NOW(), 1806),
(7303, 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 'Admissions', 'Active', 15, 479, 479, NOW(), NOW(), 3322),
(7304, 'Institute of Management Studies & Research', 'Marketing', 2222, 'Marketing', 'Active', 15, 479, 479, NOW(), NOW(), 1028),
(7305, 'Institute of Management Studies & Research', 'Accounts & Finance', 2238, 'Accounts', 'Active', 15, 479, 479, NOW(), NOW(), 13),
(7306, 'Institute of Management Studies & Research', 'General Administration', 2239, 'Admin', 'Active', 15, 479, 479, NOW(), NOW(), 2054),
(7307, 'Institute of Management Studies & Research', 'General Administration', 2239, 'General Staff', 'Active', 15, 479, 479, NOW(), NOW(), 2054),
(7308, 'Institute of Management Studies & Research', 'Training & Placements', 2240, 'Career Service Cell', 'Active', 15, 479, 479, NOW(), NOW(), 1049),
(7309, 'Institute of Management Studies & Research', 'Examination & Records', 2241, 'Exam and Eligibility', 'Active', 15, 479, 479, NOW(), NOW(), 1804),
(7310, 'Institute of Management Studies & Research', 'Information Technology', 2242, 'IT', 'Active', 15, 479, 479, NOW(), NOW(), 1142),
(7311, 'Institute of Management Studies & Research', 'Technical & Laboratories', 2243, 'Lab', 'Active', 15, 479, 479, NOW(), NOW(), 2060),
(7312, 'Institute of Management Studies & Research', 'Operations & Systems', 2244, 'MIS', 'Active', 15, 479, 479, NOW(), NOW(), 2060),
(7313, 'Institute of Management Studies & Research', 'Human Resources', 2245, 'HR', 'Active', 15, 479, 479, NOW(), NOW(), 1035),
(7314, 'Institute of Management Studies & Research', 'Human Resources', 2245, 'Talent Management', 'Active', 15, 479, 479, NOW(), NOW(), 1050),
(7315, 'Institute of Management Studies & Research', 'Campus Facilities', 2246, 'Support Team', 'Active', 15, 479, 479, NOW(), NOW(), 1813);

-- 3. Revert tbluser columns for 16 NAEMD staff
UPDATE `tbluser` SET `jobtitle_id` = 7300, `allocated_standards` = NULL, `user_profile_id` = 63, `department_id` = 2235 WHERE `id` = 564 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 565 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7302, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2236 WHERE `id` = 566 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7306, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2239 WHERE `id` = 567 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 568 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7308, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2240 WHERE `id` = 569 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7305, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2238 WHERE `id` = 570 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 571 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7308, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2240 WHERE `id` = 572 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7301, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2236 WHERE `id` = 573 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 574 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 575 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7306, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2239 WHERE `id` = 576 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 577 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7315, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2246 WHERE `id` = 578 AND `sub_institute_id` = 15;
UPDATE `tbluser` SET `jobtitle_id` = 7303, `allocated_standards` = NULL, `user_profile_id` = 58, `department_id` = 2237 WHERE `id` = 579 AND `sub_institute_id` = 15;

-- 4. Restore Purged Provisional Users (R1)
INSERT IGNORE INTO `tbluser`
SELECT * FROM `tbluser_backup_tenant15_purge_20261104`;

-- 5. Delete Competencies and Tasks
DELETE FROM `jobrole_competency_map` WHERE `sub_institute_id` = 15;
DELETE FROM `competency_kasba_item` WHERE `sub_institute_id` = 15;
DELETE FROM `competency` WHERE `sub_institute_id` = 15;
DELETE FROM `s_user_jobrole_task` WHERE `sub_institute_id` = 15;

-- 6. Delete Adopted Job Roles
DELETE FROM `s_user_jobrole`
WHERE `sub_institute_id` = 15
  AND `id` NOT IN (7283, 7284)
  AND `id` NOT BETWEEN 7300 AND 7315;

COMMIT;
