-- =============================================================================
-- STEP 3: DIRECTORY DUAL-KEY SYNC & ROLE ADMINISTRATION (R2 & R5)
-- Database: hp_erp (MariaDB 10.1 on db3 / 128.199.17.97)
-- Tenant: Future Varsity (sub_institute_id = 15)
-- Author: Teamwork Worker 1
-- Date: 2026-11-04
-- =============================================================================

START TRANSACTION;

-- 1. Vasudha Surve (ID 564) -> Insitue Incharge | Admin Profile (63)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Insitue Incharge'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 63,
    u.`department_id` = 2235,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 564;

-- 2. Education Counsellors (IDs 565, 566, 568, 577) -> Education Counsellor - Admissions and Academics | Employee (58)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor - Admissions and Academics'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` IN (565, 566, 568, 577);

-- 3. Meenakshi Sharma (ID 571) -> Education Counsellor - Admissions and Academics  (trailing space)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor - Admissions and Academics '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 571;

-- 4. Priyanka Piramal (ID 574) -> Education Counsellor -Admissions and Academics 
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor -Admissions and Academics '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 574;

-- 5. Priya Mishra (ID 575) -> Education Counselor - Admissions and Academics 
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counselor - Admissions and Academics '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 575;

-- 6. Lynette Fernandes (ID 579) -> Education Counsellor Admissions & Academics
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Education Counsellor Admissions & Academics'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2237,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 579;

-- 7. Santosh Bhoir (ID 567) -> Admin Executive | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Admin Executive'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2239,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 567;

-- 8. Akshi Dedhia (ID 569) -> Executive – Creative and Industry Relations | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Executive – Creative and Industry Relations'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2240,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 569;

-- 9. Seeta Khatri (ID 570) -> Admin And Accounts Executive  | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Admin And Accounts Executive '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2238,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 570;

-- 10. Rohan Jadhav (ID 572) -> Industry and Operations Executive | Executive Profile (64)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Industry and Operations Executive'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 64,
    u.`department_id` = 2240,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 572;

-- 11. Shweta Tiwari (ID 573) -> Faculty and Academic Coordinator  | Department Head Profile (60)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Faculty and Academic Coordinator '
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 60,
    u.`department_id` = 2236,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` = 573;

-- 12. Support Staff (IDs 576, 578) -> Support Staff | Employee Profile (58)
UPDATE `tbluser` u
JOIN `s_user_jobrole` j ON j.`sub_institute_id` = 15 AND j.`jobrole` = 'Support Staff'
SET u.`jobtitle_id` = j.`id`,
    u.`allocated_standards` = CAST(j.`id` AS CHAR),
    u.`user_profile_id` = 58,
    u.`department_id` = 2246,
    u.`updated_at` = NOW()
WHERE u.`sub_institute_id` = 15 AND u.`id` IN (576, 578);

-- 13. Delete obsolete department placeholder roles (IDs 7300..7315)
DELETE FROM `s_user_jobrole`
WHERE `sub_institute_id` = 15
  AND `id` BETWEEN 7300 AND 7315;

COMMIT;
