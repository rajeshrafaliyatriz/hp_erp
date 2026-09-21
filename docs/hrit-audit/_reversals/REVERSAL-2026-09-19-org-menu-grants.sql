-- REVERSAL for 2026_09_19_200000_restrict_organisation_menus_to_administrators
--
-- Restores the EXACT rights this migration revoked, per database, keyed on
-- (sub_institute_id, profile_id, menu_id) rather than on row ids so it survives
-- the table being rebuilt.
--
-- The migration's own down() deliberately does NOT do this: it sets five flags
-- to 0 across hundreds of rows and cannot know what each held before, so a
-- guessing down() would grant every employee edit rights they never had.
-- Generated from the live state BEFORE the migration ran.

-- ===== DEV: 293 row(s) revoked =====
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=34 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=5 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=6 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=8 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=9 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=11 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=12 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=14 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=15 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=17 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=18 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=20 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=21 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=26 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=27 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=29 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=30 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=32 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=33 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=39 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=40 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=41 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=42 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=43 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=44 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=45 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=46 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=47 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=48 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=49 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=50 AND sub_institute_id='2';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=51 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=52 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=53 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=54 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=55 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=56 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=57 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=58 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=59 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=60 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=61 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=62 AND sub_institute_id='4';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=63 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=64 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=65 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=66 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=67 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=68 AND sub_institute_id='5';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=69 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=70 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=71 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=72 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=73 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=74 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=75 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=76 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=77 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=78 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=79 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=80 AND sub_institute_id='7';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=87 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=88 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=89 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=90 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=91 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=92 AND sub_institute_id='9';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=93 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=94 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=95 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=96 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=97 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=98 AND sub_institute_id='10';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=99 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=100 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=101 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=102 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=103 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=104 AND sub_institute_id='11';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=2 AND sub_institute_id='1';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=175 AND sub_institute_id='1000019';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=116 AND sub_institute_id='1000000';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=148 AND sub_institute_id='1000010';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=151 AND sub_institute_id='1000011';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=172 AND sub_institute_id='1000018';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=34 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=5 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=6 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=8 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=9 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=11 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=12 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=14 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=15 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=17 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=18 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=20 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=21 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=26 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=27 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=29 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=30 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=32 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=33 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=39 AND sub_institute_id='1';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=40 AND sub_institute_id='1';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=41 AND sub_institute_id='1';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=42 AND sub_institute_id='1';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=43 AND sub_institute_id='1';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=45 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=46 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=47 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=48 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=49 AND sub_institute_id='2';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=51 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=52 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=53 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=54 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=55 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=57 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=58 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=59 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=60 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=61 AND sub_institute_id='4';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=63 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=64 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=65 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=66 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=67 AND sub_institute_id='5';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=69 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=70 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=71 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=72 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=73 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=75 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=76 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=77 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=78 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=79 AND sub_institute_id='7';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=87 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=88 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=89 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=90 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=91 AND sub_institute_id='9';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=93 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=94 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=95 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=96 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=97 AND sub_institute_id='10';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=99 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=100 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=101 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=102 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=103 AND sub_institute_id='11';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=2 AND sub_institute_id='1';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=175 AND sub_institute_id='1000019';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=34 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=5 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=6 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=8 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=9 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=11 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=12 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=14 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=15 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=17 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=18 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=20 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=21 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=26 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=27 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=29 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=30 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=32 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=33 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=39 AND sub_institute_id='1';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=40 AND sub_institute_id='1';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=41 AND sub_institute_id='1';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=42 AND sub_institute_id='1';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=43 AND sub_institute_id='1';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=45 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=46 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=47 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=48 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=49 AND sub_institute_id='2';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=51 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=52 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=53 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=54 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=55 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=57 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=58 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=59 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=60 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=61 AND sub_institute_id='4';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=63 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=64 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=65 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=66 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=67 AND sub_institute_id='5';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=69 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=70 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=71 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=72 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=73 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=75 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=76 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=77 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=78 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=79 AND sub_institute_id='7';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=87 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=88 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=89 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=90 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=91 AND sub_institute_id='9';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=93 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=94 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=95 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=96 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=97 AND sub_institute_id='10';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=99 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=100 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=101 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=102 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=103 AND sub_institute_id='11';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=2 AND sub_institute_id='1';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=1, can_edit=1, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=175 AND sub_institute_id='1000019';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=34 AND sub_institute_id='3';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=5 AND sub_institute_id='2';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=8 AND sub_institute_id='3';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=11 AND sub_institute_id='4';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=14 AND sub_institute_id='5';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=18 AND sub_institute_id='6';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=21 AND sub_institute_id='7';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=27 AND sub_institute_id='9';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=29 AND sub_institute_id='10';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=33 AND sub_institute_id='11';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=42 AND sub_institute_id='1';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=43 AND sub_institute_id='1';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=48 AND sub_institute_id='2';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=49 AND sub_institute_id='2';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=54 AND sub_institute_id='3';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=55 AND sub_institute_id='3';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=60 AND sub_institute_id='4';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=61 AND sub_institute_id='4';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=66 AND sub_institute_id='5';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=67 AND sub_institute_id='5';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=72 AND sub_institute_id='6';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=73 AND sub_institute_id='6';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=78 AND sub_institute_id='7';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=79 AND sub_institute_id='7';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=90 AND sub_institute_id='9';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=91 AND sub_institute_id='9';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=96 AND sub_institute_id='10';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=97 AND sub_institute_id='10';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=102 AND sub_institute_id='11';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=103 AND sub_institute_id='11';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=2 AND sub_institute_id='1';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=2 AND sub_institute_id='1';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=5 AND sub_institute_id='2';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=8 AND sub_institute_id='3';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=11 AND sub_institute_id='4';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=14 AND sub_institute_id='5';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=18 AND sub_institute_id='6';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=21 AND sub_institute_id='7';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=27 AND sub_institute_id='9';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=29 AND sub_institute_id='10';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=33 AND sub_institute_id='11';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=41 AND sub_institute_id='1';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=47 AND sub_institute_id='2';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=53 AND sub_institute_id='3';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=59 AND sub_institute_id='4';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=65 AND sub_institute_id='5';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=71 AND sub_institute_id='6';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=77 AND sub_institute_id='7';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=89 AND sub_institute_id='9';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=95 AND sub_institute_id='10';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=101 AND sub_institute_id='11';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=8 AND sub_institute_id='3';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=2 AND sub_institute_id='1';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=5 AND sub_institute_id='2';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=11 AND sub_institute_id='4';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=14 AND sub_institute_id='5';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=18 AND sub_institute_id='6';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=21 AND sub_institute_id='7';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=27 AND sub_institute_id='9';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=29 AND sub_institute_id='10';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=33 AND sub_institute_id='11';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=116 AND sub_institute_id='1000000';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=148 AND sub_institute_id='1000010';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=151 AND sub_institute_id='1000011';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=175 AND sub_institute_id='1000019';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=172 AND sub_institute_id='1000018';  -- Guided Setup

-- DEV: remove the auditor trail grant the migration created (10 profile(s))
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=43;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=49;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=55;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=61;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=67;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=73;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=79;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=91;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=97;
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=103;

-- ===== LIVE: 121 row(s) revoked =====
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=2 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=3 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=5 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=6 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=8 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=11 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=12 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=14 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=15 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=20 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=21 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=22 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=23 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=24 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=26 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=27 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=29 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=30 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=32 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=33 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=34 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=35 AND sub_institute_id='';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=9 AND sub_institute_id='3';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=18 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=17 AND sub_institute_id='6';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=37 AND sub_institute_id='13';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=39 AND sub_institute_id='13';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=40 AND sub_institute_id='14';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=42 AND sub_institute_id='14';  -- Organizational Management
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=2 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=3 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=5 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=6 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=8 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=11 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=12 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=14 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=15 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=20 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=21 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=22 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=23 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=24 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=26 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=27 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=29 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=30 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=32 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=33 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=34 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=35 AND sub_institute_id='';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=9 AND sub_institute_id='3';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=18 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=17 AND sub_institute_id='6';  -- Organization Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=2 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=3 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=5 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=6 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=8 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=11 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=12 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=14 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=15 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=20 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=21 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=22 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=23 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=24 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=26 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=27 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=29 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=30 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=32 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=33 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=34 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=35 AND sub_institute_id='';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=9 AND sub_institute_id='3';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=18 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=17 AND sub_institute_id='6';  -- Organization Profile
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=2 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=3 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=5 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=6 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=8 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=11 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=12 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=14 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=15 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=20 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=21 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=22 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=23 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=24 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=26 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=27 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=29 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=30 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=32 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=33 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=34 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=35 AND sub_institute_id='';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=9 AND sub_institute_id='3';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=18 AND sub_institute_id='6';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=17 AND sub_institute_id='6';  -- Role & Permissions
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=2 AND sub_institute_id='';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=208 AND profile_id=18 AND sub_institute_id='6';  -- Audit & Activity Center
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=8 AND sub_institute_id='3';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=2 AND sub_institute_id='1';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=5 AND sub_institute_id='2';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=11 AND sub_institute_id='4';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=14 AND sub_institute_id='5';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=21 AND sub_institute_id='7';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=22 AND sub_institute_id='8';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=24 AND sub_institute_id='8';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=27 AND sub_institute_id='9';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=29 AND sub_institute_id='10';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=37 AND sub_institute_id='13';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=39 AND sub_institute_id='13';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=40 AND sub_institute_id='14';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=42 AND sub_institute_id='14';  -- Guided Setup
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=306 AND profile_id=18 AND sub_institute_id='6';  -- Guided Setup

-- LIVE: remove the auditor trail grant the migration created (1 profile(s))
DELETE FROM tblgroupwise_rights_g2g WHERE menu_id=208 AND profile_id=50;

-- ═══════════════════════════════════════════════════════════════════════════
-- FOUR LIVE ROWS ARE RECONSTRUCTED, NOT CAPTURED. READ THIS BEFORE USING THEM.
-- ═══════════════════════════════════════════════════════════════════════════
--
-- The generator that produced this file resolved role keys with
-- RoleKey::forProfileId(), which reads the DEFAULT database connection and keeps
-- a static cache. It processed dev first and live second, so every live profile
-- id was resolved against DEV's profile table and against DEV's cached answers.
-- Live profile 31 does not exist; dev profile 31 is 'Admin'/administrator. So the
-- generator decided those four rows belonged to an administrator and skipped them,
-- while the migration - which runs with live as its own default connection, and
-- therefore resolved correctly - revoked them.
--
-- Captured: 112 of the 116 rows the migration revoked on live.
-- Below:      the remaining 4, reconstructed.
--
-- The reconstruction is safe to trust for two independent reasons:
--   1. ALL 121 captured live rows have the identical shape can_view=1 with every
--      other flag 0. There is no second shape in the data to guess between.
--   2. profile_id=31 DOES NOT EXIST in live tbluserprofilemaster. These are
--      orphaned rights rows pointing at a deleted profile, so no user can hold
--      them and restoring them grants nobody anything.
--
-- Stated plainly rather than silently folded in with the captured rows, because a
-- reversal that cannot be told apart from a measurement is not a reversal.

-- ===== LIVE: 4 row(s) RECONSTRUCTED (orphaned profile 31) =====
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=1 AND profile_id=31 AND sub_institute_id='';   -- Organizational Management  [reconstructed]
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=7 AND profile_id=31 AND sub_institute_id='';   -- [reconstructed]
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=12 AND profile_id=31 AND sub_institute_id='';  -- [reconstructed]
UPDATE tblgroupwise_rights_g2g SET can_view=1, can_add=0, can_edit=0, can_delete=0, dashboard_right=0 WHERE menu_id=23 AND profile_id=31 AND sub_institute_id='';  -- [reconstructed]
