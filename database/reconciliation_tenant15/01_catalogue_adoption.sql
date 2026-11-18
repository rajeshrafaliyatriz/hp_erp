-- =============================================================================
-- STEP 1: CATALOGUE ADOPTION & KASBA/TASK POPULATION (R3)
-- Database: hp_erp (MariaDB 10.1 on db3 / 128.199.17.97)
-- Tenant: Future Varsity (sub_institute_id = 15)
-- Author: Teamwork Worker 1
-- Date: 2026-11-04
-- =============================================================================

START TRANSACTION;

-- 1. Adopt the 12 distinct NAEMD designations into s_user_jobrole with provenance
INSERT INTO `s_user_jobrole` (
    `jobrole`,
    `industries`,
    `department`,
    `department_id`,
    `sub_institute_id`,
    `catalogue_jobrole_id`,
    `status`,
    `created_by`,
    `updated_by`,
    `created_at`,
    `updated_at`
)
VALUES
('Insitue Incharge', 'Institute of Management Studies & Research', 'Institutional Leadership', 2235, 15, 1810, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor - Admissions and Academics', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Admin Executive', 'Institute of Management Studies & Research', 'General Administration', 2239, 15, 2054, 'Active', 479, 479, NOW(), NOW()),
('Executive – Creative and Industry Relations', 'Institute of Management Studies & Research', 'Training & Placements', 2240, 15, 2095, 'Active', 479, 479, NOW(), NOW()),
('Admin And Accounts Executive ', 'Institute of Management Studies & Research', 'Accounts & Finance', 2238, 15, 2057, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor - Admissions and Academics ', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Industry and Operations Executive', 'Institute of Management Studies & Research', 'Training & Placements', 2240, 15, 2079, 'Active', 479, 479, NOW(), NOW()),
('Faculty and Academic Coordinator ', 'Institute of Management Studies & Research', 'Academics & Faculty', 2236, 15, 2170, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor -Admissions and Academics ', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Education Counselor - Admissions and Academics ', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW()),
('Support Staff', 'Institute of Management Studies & Research', 'Campus Facilities', 2246, 15, 2099, 'Active', 479, 479, NOW(), NOW()),
('Education Counsellor Admissions & Academics', 'Institute of Management Studies & Research', 'Admissions & Outreach', 2237, 15, 2157, 'Active', 479, 479, NOW(), NOW());

-- 2. Clone corresponding catalogue tasks into s_user_jobrole_task
INSERT INTO `s_user_jobrole_task` (
    `sector`,
    `track`,
    `jobrole`,
    `critical_work_function`,
    `task`,
    `task_type`,
    `sub_institute_id`,
    `jobrole_id`,
    `catalogue_task_id`,
    `created_by`,
    `updated_by`,
    `created_at`,
    `updated_at`
)
SELECT 
    t.`sector`,
    t.`track`,
    ujr.`jobrole`,
    t.`critical_work_function`,
    t.`task`,
    t.`task_type`,
    15 AS `sub_institute_id`,
    ujr.`id` AS `jobrole_id`,
    t.`id` AS `catalogue_task_id`,
    479 AS `created_by`,
    479 AS `updated_by`,
    NOW() AS `created_at`,
    NOW() AS `updated_at`
FROM `s_user_jobrole` ujr
JOIN `s_jobrole` c ON c.`id` = ujr.`catalogue_jobrole_id`
JOIN `s_jobrole_task` t ON t.`jobrole` = c.`jobrole`
WHERE ujr.`sub_institute_id` = 15
  AND ujr.`id` NOT IN (7283, 7284)
  AND ujr.`id` NOT BETWEEN 7300 AND 7315;

-- 3. Seed core institutional competencies for Tenant 15
INSERT INTO `competency` (
    `sub_institute_id`, `code`, `name`, `description`, `competency_type`, `criticality`, `requires_assessment`, `status`, `version`, `created_by`, `created_at`, `updated_at`
) VALUES
(15, 'LEAD-01', 'Institutional Leadership & Strategic Governance', 'Leading educational vision, regulatory compliance, and institutional administration.', 'leadership', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'ACAD-01', 'Curriculum Coordination & Pedagogical Delivery', 'Managing course curricula, faculty instructional standards, and academic assessments.', 'functional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'GUIDE-01', 'Student Guidance, Admissions & Career Counselling', 'Delivering student advisory, intake counseling, and career progression pathways.', 'professional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'ADMIN-01', 'Office Administration & Operational Execution', 'Executing institutional record-keeping, office workflows, and facilities coordination.', 'functional', 'medium', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'FIN-01', 'Financial Record-Keeping & Accounts Administration', 'Managing institutional bookkeeping, fee records, vendor accounts, and audits.', 'functional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'REL-01', 'Industry Relations, Event Management & Placements', 'Cultivating industry partnerships, organizing campus events, and securing placements.', 'professional', 'high', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'SUPP-01', 'Campus Support & Operational Maintenance', 'Delivering reliable facility support, campus upkeep, and administrative assistance.', 'functional', 'medium', 1, 'active', 1, 479, NOW(), NOW()),
(15, 'CORE-01', 'Professional Ethics, Communication & Learner Welfare', 'Upholding student safeguarding, professional ethics, and cross-functional communication.', 'behavioural', 'high', 1, 'active', 1, 479, NOW(), NOW());

-- 4. Link KASBA items for each competency
INSERT INTO `competency_kasba_item` (`sub_institute_id`, `competency_id`, `kasba_type`, `item_label`, `weight`, `created_at`, `updated_at`)
SELECT 15, id, 'knowledge', 'Institutional governance standards and educational regulatory framework', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'LEAD-01'
UNION ALL
SELECT 15, id, 'skill', 'Strategic planning and institutional resource allocation', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'LEAD-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Curriculum design principles and assessment methodologies', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'ACAD-01'
UNION ALL
SELECT 15, id, 'skill', 'Faculty coordination and classroom instructional observation', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'ACAD-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Course options, eligibility norms, and career trajectory mapping', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'GUIDE-01'
UNION ALL
SELECT 15, id, 'skill', 'Active listening, student counseling, and admission pipeline conversion', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'GUIDE-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Accounting principles, ledger maintenance, and statutory records', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'FIN-01'
UNION ALL
SELECT 15, id, 'skill', 'Financial reporting, reconciliation, and audit documentation', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'FIN-01'
UNION ALL
SELECT 15, id, 'knowledge', 'Event planning logistics, industry vendor networks, and corporate outreach', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'REL-01'
UNION ALL
SELECT 15, id, 'skill', 'Corporate relations management, placement coordination, and event execution', 4.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'REL-01'
UNION ALL
SELECT 15, id, 'behaviour', 'Student data confidentiality, integrity, and respectful communication', 3.00, NOW(), NOW() FROM `competency` WHERE `sub_institute_id` = 15 AND `code` = 'CORE-01';

-- 5. Map each adopted role to competencies in jobrole_competency_map
-- All adopted roles get CORE-01
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 3, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'CORE-01'
WHERE ujr.`sub_institute_id` = 15
  AND ujr.`id` NOT IN (7283, 7284)
  AND ujr.`id` NOT BETWEEN 7300 AND 7315;

-- Map LEAD-01 to Insitue Incharge
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'LEAD-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND ujr.`jobrole` = 'Insitue Incharge';

-- Map ACAD-01 to Faculty Coordinator
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'ACAD-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND ujr.`jobrole` LIKE '%Faculty%';

-- Map GUIDE-01 to Education Counsellors
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'GUIDE-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND ujr.`jobrole` LIKE '%Counse%';

-- Map ADMIN-01 to Admin & Operations Executives
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 3, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'ADMIN-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND (ujr.`jobrole` LIKE '%Admin%' OR ujr.`jobrole` LIKE '%Operations%');

-- Map FIN-01 to Accounts Executive
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'FIN-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND ujr.`jobrole` LIKE '%Accounts%';

-- Map REL-01 to Industry & Creative Relations Executive
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 4, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'REL-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND (ujr.`jobrole` LIKE '%Creative%' OR ujr.`jobrole` LIKE '%Industry%');

-- Map SUPP-01 to Support Staff
INSERT INTO `jobrole_competency_map` (`sub_institute_id`, `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, `created_by`, `created_at`, `updated_at`)
SELECT 15, ujr.`id`, c.`id`, 3, 1, 479, NOW(), NOW()
FROM `s_user_jobrole` ujr
JOIN `competency` c ON c.`sub_institute_id` = 15 AND c.`code` = 'SUPP-01'
WHERE ujr.`sub_institute_id` = 15 
  AND ujr.`id` NOT IN (7283, 7284) AND ujr.`id` NOT BETWEEN 7300 AND 7315
  AND ujr.`jobrole` LIKE '%Support%';

COMMIT;
