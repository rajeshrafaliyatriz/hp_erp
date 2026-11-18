-- =============================================================================
-- STEP 4: OUTBOUND DELIVERY ACTIVATION (R4)
-- Database: hp_erp (MariaDB 10.1 on db3 / 128.199.17.97)
-- Tenant: Future Varsity (sub_institute_id = 15)
-- Author: Teamwork Worker 1
-- Date: 2026-11-04
-- =============================================================================

START TRANSACTION;

-- 1. Enable outbound email delivery in school_setup
UPDATE `school_setup`
SET `outbound_email_enabled` = 1
WHERE `id` = 15;

-- 2. Configure active platform SMTP profile for tenant 15
INSERT INTO `smtp_details` (
    `label`,
    `module_key`,
    `gmail`,
    `from_name`,
    `password`,
    `server_address`,
    `port`,
    `sub_institute_id`,
    `created_by`,
    `updated_by`,
    `created_at`,
    `updated_at`
) VALUES (
    'Default sender',
    'default',
    'trizinnovation2018@gmail.com',
    'Future Varsity',
    'eyJpdiI6Im5saDZkYTZ1aHNUNm8xbnM0UVpSQ0E9PSIsInZhbHVlIjoiTkJxNG9TVUtpUnB5b2xKeXNTcEZVV2daWHkvcGhDd0RLRStzenplRW9tWT0iLCJtYWMiOiI1MTU0YzRjOTdhMTE3MTQ0MWIwYmFhNzk1Yzg3MDgxNDRiODIzM2U0ZDA1MjA5NGQ0NTlhMTdiNzExOTIzNTFmIiwidGFnIjoiIn0=',
    'smtp.gmail.com',
    '465',
    15,
    479,
    479,
    NOW(),
    NOW()
);

-- 3. Configure SMS gateway details for tenant 15
INSERT INTO `sms_api_details` (
    `url`,
    `pram`,
    `mobile_var`,
    `text_var`,
    `last_var`,
    `sub_institute_id`,
    `is_active`,
    `created_at`,
    `updated_at`
) VALUES (
    'https://alerts.prioritysms.com/api/web2sms.php?',
    'workingkey=Ad56b0cea0c31b0e4c8ec9a199416fd2a&sender=ALDAPS',
    '&to=',
    '&message=',
    '- Future Varsity',
    15,
    1,
    NOW(),
    NOW()
);

COMMIT;
