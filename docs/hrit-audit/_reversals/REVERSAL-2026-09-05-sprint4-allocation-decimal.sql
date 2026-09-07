-- Reversal for HRIT Sprint 4's allocation value widening
-- (2026_09_05_170000_widen_hrms_leave_allocation_value).
-- Host: 202.47.117.220/hp_erp.
--
-- WARNING: narrowing back to int TRUNCATES any half-day entitlement set since
-- the widening. Check for them first:
--   SELECT id, value FROM hrms_leave_allocation WHERE value <> FLOOR(value);
ALTER TABLE hrms_leave_allocation MODIFY value INT(11) NOT NULL DEFAULT 0;
