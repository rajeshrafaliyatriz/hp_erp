-- Reversal for HRIT Sprint 1's role backfill migration
-- (2026_09_05_120000_backfill_auditor_recruiter_leave_roles).
-- Host: 202.47.117.220/hp_erp. Equivalent to `php artisan migrate:rollback`.
--
-- Only removes rows still untouched since insertion, so a tenant that has since
-- edited Auditor or Recruiter keeps their edit.
DELETE FROM hrms_leave_role_permissions
 WHERE role_name IN ('Auditor', 'Recruiter')
   AND created_at = updated_at;
