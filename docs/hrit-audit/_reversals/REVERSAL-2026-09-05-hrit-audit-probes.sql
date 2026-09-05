-- Reversal for the Sprint 0 HRIT authorization probes (Docs/hrit-audit/_evidence/probe-writes.sh).
-- Host: 202.47.117.220/hp_erp. Run once, immediately after the probes.
-- Every statement restores a value captured in Docs/hrit-audit/_evidence/before-*.json.

-- W1/W2: the probe's own leave request (created and self-approved by user 7). Remove it.
DELETE FROM hrms_emp_leaves WHERE id = 223 AND sub_institute_id = 3 AND comment = 'HRIT audit probe - delete me';

-- W3: request #219 belonged to user 54 and was pending. The probe soft-deleted it.
UPDATE hrms_emp_leaves SET deleted_at = NULL, deleted_by = NULL
 WHERE id = 219 AND sub_institute_id = 3;

-- W4: the leave type the probe created, and any allocation syncAllocation() wrote for it.
DELETE FROM hrms_leave_allocation WHERE leave_type_id = 11 AND sub_institute_id = 3;
DELETE FROM hrms_leave_types WHERE id = 11 AND sub_institute_id = 3 AND leave_type = 'HRIT AUDIT PROBE';

-- W5: the Employee row of the permission matrix, back to Self / no rights.
UPDATE hrms_leave_role_permissions
   SET scope = 'Self', approve_leave = 0, view_reports = 1, configure_settings = 0,
       bulk_operations = 0, escalation_rights = 0, user_management = 0
 WHERE id = 8 AND sub_institute_id = 3;

-- ---------------------------------------------------------------------------
-- Second batch: rows created by probe-validation.sh and the Unicode round-trip.
-- These were all created BY the probes; removing them returns tenant 3 to its
-- pre-audit counts (hrms_emp_leaves = 29 live rows, hrms_leave_types = 3).
DELETE FROM hrms_emp_leaves  WHERE id IN (224,225,226,227,228) AND sub_institute_id = 3;
DELETE FROM hrms_leave_types WHERE id = 12 AND sub_institute_id = 3 AND leave_type = 'NEG probe minus';
