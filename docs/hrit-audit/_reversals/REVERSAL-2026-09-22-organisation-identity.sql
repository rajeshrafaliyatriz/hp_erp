-- REVERSAL for 2026_09_22_100000_one_identity_per_organisation
--
-- Captured BEFORE the migration ran, from each database in turn. Every value
-- below is the exact prior state, so this file is the only way back: the
-- migration down() is deliberately empty because a cleared logo cannot be
-- guessed and a backfilled statutory record cannot be told apart from one
-- somebody has since filled in by hand.
--
-- Run ONLY the section for the database you are reverting.

-- ══════════════════════════════════════════════════════════════════════
-- DEV
-- ══════════════════════════════════════════════════════════════════════

-- 1. school_setup.Logo as it was.
UPDATE school_setup SET Logo = 'scholar_clone.png' WHERE id = 1;  -- Triz High School
UPDATE school_setup SET Logo = 'finace_logo.jpeg' WHERE id = 2;  -- Finance
UPDATE school_setup SET Logo = 'healthcare_logo.png' WHERE id = 3;  -- Healthcare
UPDATE school_setup SET Logo = 'sids_logo.png' WHERE id = 4;  -- SIDS HealthCare
UPDATE school_setup SET Logo = 'scholar_clone.png' WHERE id = 5;  -- IT
UPDATE school_setup SET Logo = 'scholar_clone.png' WHERE id = 6;  -- Scholar Clone
UPDATE school_setup SET Logo = 'education.png' WHERE id = 7;  -- Education
UPDATE school_setup SET Logo = 'Altius fortius.png' WHERE id = 9;  -- Altius fortius
UPDATE school_setup SET Logo = 'IPE.png' WHERE id = 10;  -- IPE GLOBAL
UPDATE school_setup SET Logo = NULL WHERE id = 11;  -- Jainam Solutions
UPDATE school_setup SET Logo = NULL WHERE id = 1000000;  -- Sunrise International School
UPDATE school_setup SET Logo = NULL WHERE id = 1000010;  -- lions
UPDATE school_setup SET Logo = NULL WHERE id = 1000011;  -- xyz
UPDATE school_setup SET Logo = NULL WHERE id = 1000018;  -- Fiber Valley
UPDATE school_setup SET Logo = NULL WHERE id = 1000019;  -- Sprint1 Test Org

-- 2. org_details.logo as it was, for rows that already existed.
UPDATE org_details SET logo = '1759922340_JEE Main 2025-S1P1-images-1.jpg' WHERE id = 1;  -- tenant 1
UPDATE org_details SET logo = '1759921173_black-jpg.jpg' WHERE id = 2;  -- tenant 3
UPDATE org_details SET logo = '1756884159_Sids.jpg' WHERE id = 3;  -- tenant 4
UPDATE org_details SET logo = '1763104263_scholarclonelogo.jpg' WHERE id = 4;  -- tenant 6
UPDATE org_details SET logo = NULL WHERE id = 5;  -- tenant 7
UPDATE org_details SET logo = NULL WHERE id = 10;  -- tenant 1000000
UPDATE org_details SET logo = NULL WHERE id = 20;  -- tenant 1000010
UPDATE org_details SET logo = NULL WHERE id = 21;  -- tenant 1000011
UPDATE org_details SET logo = NULL WHERE id = 24;  -- tenant 1000018

-- 3. The statutory records that did NOT exist before the migration.
--    Delete these to undo the backfill - but ONLY if nobody has since
--    edited them, which this file cannot know. Check updated_at first.
-- DELETE FROM org_details WHERE sub_institute_id IN (2, 5, 9, 10, 11, 1000019);

-- ══════════════════════════════════════════════════════════════════════
-- LIVE
-- ══════════════════════════════════════════════════════════════════════

-- 1. school_setup.Logo as it was.
UPDATE school_setup SET Logo = 'scholar_clone.png' WHERE id = 1;  -- Triz High School
UPDATE school_setup SET Logo = 'finace_logo.jpeg' WHERE id = 2;  -- Finance
UPDATE school_setup SET Logo = 'healthcare_logo.png' WHERE id = 3;  -- Healthcare
UPDATE school_setup SET Logo = 'sids_logo.png' WHERE id = 4;  -- SIDS HealthCare
UPDATE school_setup SET Logo = 'scholar_clone.png' WHERE id = 5;  -- IT
UPDATE school_setup SET Logo = 'scholar_clone.png' WHERE id = 6;  -- Scholar Clone
UPDATE school_setup SET Logo = 'education.png' WHERE id = 7;  -- Education
UPDATE school_setup SET Logo = 'npst.png' WHERE id = 8;  -- NPST
UPDATE school_setup SET Logo = 'Altius fortius.png' WHERE id = 9;  -- Altius fortius
UPDATE school_setup SET Logo = 'IPE.png' WHERE id = 10;  -- IPE GLOBAL
UPDATE school_setup SET Logo = 'jainam.png' WHERE id = 13;  -- Jainam Solutions
UPDATE school_setup SET Logo = 'aqua.png' WHERE id = 14;  -- Aqua Solutions

-- 2. org_details.logo as it was, for rows that already existed.
UPDATE org_details SET logo = '1759922340_JEE Main 2025-S1P1-images-1.jpg' WHERE id = 1;  -- tenant 1
UPDATE org_details SET logo = '1759921173_black-jpg.jpg' WHERE id = 2;  -- tenant 3
UPDATE org_details SET logo = '1756884159_Sids.jpg' WHERE id = 3;  -- tenant 4
UPDATE org_details SET logo = '1763104263_scholarclonelogo.jpg' WHERE id = 4;  -- tenant 6

-- 3. The statutory records that did NOT exist before the migration.
--    Delete these to undo the backfill - but ONLY if nobody has since
--    edited them, which this file cannot know. Check updated_at first.
-- DELETE FROM org_details WHERE sub_institute_id IN (2, 5, 7, 8, 9, 10, 13, 14);

