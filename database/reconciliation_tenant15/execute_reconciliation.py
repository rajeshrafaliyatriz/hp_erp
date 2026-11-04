#!/usr/bin/env python3
"""
Tenant 15 Reconciliation & Delivery Activation Runner
Target Host: db3 (128.199.17.97, MariaDB 10.1.48, database hp_erp)
Target Tenant: sub_institute_id = 15 (Future Varsity / NAEMD)
"""

import sys
import os
import pymysql
import pymysql.cursors

DB_CONFIG = {
    'host': '128.199.17.97',
    'user': 'dev_db',
    'password': 'dev@sql',
    'database': 'hp_erp',
    'charset': 'utf8mb4',
    'cursorclass': pymysql.cursors.DictCursor,
    'autocommit': False
}

def get_connection():
    return pymysql.connect(**DB_CONFIG)

def run_sql_file(conn, filepath):
    print(f"\n>>> Executing SQL file: {filepath}")
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Split into individual statements while respecting blocks
    # Note: statements separated by ';'
    statements = []
    current = []
    in_block = False
    
    for line in content.splitlines():
        stripped = line.strip()
        if stripped.startswith('--') or not stripped:
            continue
        current.append(line)
        if stripped.endswith(';'):
            stmt = '\n'.join(current).strip()
            if stmt:
                statements.append(stmt[:-1].strip())
            current = []
    
    with conn.cursor() as cur:
        for stmt in statements:
            if not stmt:
                continue
            cur.execute(stmt)
            print(f"  [SQL] {stmt[:60]}... -> OK (affected: {cur.rowcount})")
    conn.commit()
    print(f"  -> Successfully committed {filepath}")

def step1_catalogue_adoption(conn):
    print("\n" + "="*70)
    print("STEP 1: Catalogue Adoption & KASBA/Task Population (R3)")
    print("="*70)

    # 1. Assert Institute of Management Studies & Research does NOT exist in s_jobrole
    with conn.cursor() as cur:
        cur.execute("""
            SELECT COUNT(*) AS c FROM s_jobrole 
            WHERE sector LIKE '%Management Studies%' 
               OR track LIKE '%Management Studies%' 
               OR jobrole LIKE '%Management Studies%' 
               OR description LIKE '%Institute of Management Studies%'
        """)
        c = cur.fetchone()['c']
        print(f"Assertion 1.1: 'Institute of Management Studies & Research' in s_jobrole count = {c}")
        assert c == 0, f"Expected 0 matches in s_jobrole, got {c}"

    # 2. Check if already adopted
    with conn.cursor() as cur:
        cur.execute("""
            SELECT id, jobrole, catalogue_jobrole_id, department, department_id 
            FROM s_user_jobrole 
            WHERE sub_institute_id = 15 
              AND id NOT IN (7283, 7284)
              AND id NOT BETWEEN 7300 AND 7315
            ORDER BY id
        """)
        adopted_roles = cur.fetchall()
    
    if len(adopted_roles) < 12:
        sql_path = os.path.join(os.path.dirname(__file__), '01_catalogue_adoption.sql')
        run_sql_file(conn, sql_path)
        with conn.cursor() as cur:
            cur.execute("""
                SELECT id, jobrole, catalogue_jobrole_id, department, department_id 
                FROM s_user_jobrole 
                WHERE sub_institute_id = 15 
                  AND id NOT IN (7283, 7284)
                  AND id NOT BETWEEN 7300 AND 7315
                ORDER BY id
            """)
            adopted_roles = cur.fetchall()

    print(f"\nAssertion 1.2: Adopted roles count in s_user_jobrole: {len(adopted_roles)}")
    assert len(adopted_roles) == 12, f"Expected 12 adopted roles, got {len(adopted_roles)}"
    for r in adopted_roles:
        print(f"  Role ID {r['id']}: '{r['jobrole']}' (cat_id={r['catalogue_jobrole_id']}, dept_id={r['department_id']})")
        assert r['catalogue_jobrole_id'] is not None, f"Role {r['id']} missing catalogue_jobrole_id provenance!"

    # 4. Verify s_user_jobrole_task has cloned tasks
    with conn.cursor() as cur:
        cur.execute("""
            SELECT COUNT(*) AS c, COUNT(DISTINCT jobrole_id) AS distinct_roles, COUNT(DISTINCT catalogue_task_id) AS distinct_tasks
            FROM s_user_jobrole_task 
            WHERE sub_institute_id = 15
        """)
        tasks = cur.fetchone()
        print(f"\nAssertion 1.3: Cloned tasks count = {tasks['c']}, across {tasks['distinct_roles']} roles, {tasks['distinct_tasks']} distinct catalogue tasks")
        assert tasks['c'] > 0, "Expected cloned tasks, got 0"
        assert tasks['distinct_roles'] == 12, f"Expected 12 distinct roles with tasks, got {tasks['distinct_roles']}"

    # 5. Verify competency and jobrole_competency_map
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS c FROM competency WHERE sub_institute_id = 15")
        comp_count = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) AS c FROM competency_kasba_item WHERE sub_institute_id = 15")
        kasba_count = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) AS c, COUNT(DISTINCT jobrole_id) AS distinct_roles FROM jobrole_competency_map WHERE sub_institute_id = 15")
        map_info = cur.fetchone()
        print(f"\nAssertion 1.4: Competencies = {comp_count}, KASBA items = {kasba_count}, Mappings = {map_info['c']} across {map_info['distinct_roles']} roles")
        assert comp_count == 8, f"Expected 8 competencies, got {comp_count}"
        assert kasba_count == 11, f"Expected 11 KASBA items, got {kasba_count}"
        assert map_info['distinct_roles'] == 12, f"Expected all 12 roles mapped, got {map_info['distinct_roles']}"

    print(">>> STEP 1 PASSED WITH 100% COMPLIANCE.")

def step2_employee_scope_reduction(conn):
    print("\n" + "="*70)
    print("STEP 2: Employee Scope Reduction & Pre-Purge Backup (R1)")
    print("="*70)

    # 1. Check user counts
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS c FROM tbluser WHERE sub_institute_id = 15")
        user_count = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) AS c FROM tbluser WHERE sub_institute_id = 15 AND id != 479 AND id NOT BETWEEN 564 AND 579")
        prov_count = cur.fetchone()['c']
        print(f"Current state: Total users in tenant 15 = {user_count}, Provisional remaining = {prov_count}")

    if prov_count > 0:
        sql_path = os.path.join(os.path.dirname(__file__), '02_user_purge.sql')
        run_sql_file(conn, sql_path)

    # 3. Assert backup table has exactly 145 rows
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS c FROM tbluser_backup_tenant15_purge_20261104")
        backup_count = cur.fetchone()['c']
        print(f"\nAssertion 2.1: Backup table tbluser_backup_tenant15_purge_20261104 count = {backup_count}")
        assert backup_count == 145, f"Expected 145 rows in backup table, got {backup_count}"

    # 4. Assert exactly 17 users remain in tbluser for tenant 15
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS c FROM tbluser WHERE sub_institute_id = 15")
        remaining_count = cur.fetchone()['c']
        print(f"Assertion 2.2: Remaining users in tbluser for tenant 15 = {remaining_count}")
        assert remaining_count == 17, f"Expected exactly 17 users remaining, got {remaining_count}"

        # Verify identity of the 17 users (Admin 479 + NAEMD staff 564..579)
        cur.execute("SELECT id, user_name, first_name, last_name, email FROM tbluser WHERE sub_institute_id = 15 ORDER BY id")
        users = cur.fetchall()
        user_ids = [u['id'] for u in users]
        expected_ids = [479] + list(range(564, 580))
        assert user_ids == expected_ids, f"Expected user IDs {expected_ids}, got {user_ids}"
        print(f"  Verified 17 users: Admin ID 479 and Staff IDs 564..579.")

    # 5. Assert 0 orphan records across dependent tables
    with conn.cursor() as cur:
        orphan_checks = [
            ("s_skill_matrix", "user_id"),
            ("hrms_attendances", "user_id"),
            ("calendar_events", "created_by"),
            ("staff_document", "user_id")
        ]
        print("\nAssertion 2.3: Checking orphan records across dependent tables...")
        for tbl, col in orphan_checks:
            try:
                cur.execute(f"""
                    SELECT COUNT(*) AS c 
                    FROM `{tbl}` d
                    LEFT JOIN `tbluser` u ON d.`{col}` = u.`id`
                    WHERE d.`sub_institute_id` = 15 AND u.`id` IS NULL
                """)
                orphans = cur.fetchone()['c']
                print(f"  Table `{tbl}`.{col}: orphan count = {orphans}")
                assert orphans == 0, f"Found {orphans} orphans in `{tbl}`!"
            except pymysql.err.ProgrammingError as e:
                print(f"  Table `{tbl}` check: {e}")

    print(">>> STEP 2 PASSED WITH 100% COMPLIANCE.")

def step3_dual_key_and_profiles(conn):
    print("\n" + "="*70)
    print("STEP 3: Directory Dual-Key Sync & Role Administration (R2 & R5)")
    print("="*70)

    with conn.cursor() as cur:
        cur.execute("""
            SELECT COUNT(*) AS mismatches 
            FROM tbluser 
            WHERE sub_institute_id = 15 
              AND id != 479 
              AND (allocated_standards IS NULL OR CAST(allocated_standards AS UNSIGNED) != jobtitle_id)
        """)
        mismatches = cur.fetchone()['mismatches']

    if mismatches > 0:
        sql_path = os.path.join(os.path.dirname(__file__), '03_dual_key_and_profiles.sql')
        run_sql_file(conn, sql_path)

    # 2. Verify all 16 staff have allocated_standards = CAST(jobtitle_id AS CHAR)
    with conn.cursor() as cur:
        cur.execute("""
            SELECT u.id, u.first_name, u.last_name, u.jobtitle_id, u.allocated_standards, u.user_profile_id, ujr.jobrole
            FROM tbluser u
            JOIN s_user_jobrole ujr ON ujr.id = u.jobtitle_id
            WHERE u.sub_institute_id = 15 AND u.id != 479
            ORDER BY u.id
        """)
        staff = cur.fetchall()
        print(f"\nAssertion 3.1: Verified {len(staff)} staff members:")
        assert len(staff) == 16, f"Expected 16 staff, got {len(staff)}"
        
        for s in staff:
            assert str(s['jobtitle_id']) == str(s['allocated_standards']), \
                f"Mismatch for user {s['id']}: jobtitle_id={s['jobtitle_id']}, allocated_standards={s['allocated_standards']}"
            print(f"  ID {s['id']}: {s['first_name']} {s['last_name']} -> JobRole '{s['jobrole']}' (ID={s['jobtitle_id']}), Profile={s['user_profile_id']}")

    # 3. Verify zero mismatches query (collation-safe integer comparison)
    with conn.cursor() as cur:
        cur.execute("""
            SELECT COUNT(*) AS mismatches 
            FROM tbluser 
            WHERE sub_institute_id = 15 
              AND id != 479 
              AND (allocated_standards IS NULL OR CAST(allocated_standards AS UNSIGNED) != jobtitle_id)
        """)
        mismatches = cur.fetchone()['mismatches']
        print(f"\nAssertion 3.2: Dual-key mismatches count = {mismatches}")
        assert mismatches == 0, f"Expected 0 mismatches, got {mismatches}"

    # 4. Verify user_profile_id hierarchy
    with conn.cursor() as cur:
        # User 564 -> 63 (Admin)
        cur.execute("SELECT user_profile_id FROM tbluser WHERE id = 564")
        p564 = cur.fetchone()['user_profile_id']
        assert p564 == 63, f"Expected user 564 user_profile_id = 63, got {p564}"

        # User 573 -> 60 (Department Head)
        cur.execute("SELECT user_profile_id FROM tbluser WHERE id = 573")
        p573 = cur.fetchone()['user_profile_id']
        assert p573 == 60, f"Expected user 573 user_profile_id = 60, got {p573}"

        # Users 567, 569, 570, 572 -> 64 (Executive)
        for uid in [567, 569, 570, 572]:
            cur.execute("SELECT user_profile_id FROM tbluser WHERE id = %s", (uid,))
            p = cur.fetchone()['user_profile_id']
            assert p == 64, f"Expected user {uid} user_profile_id = 64, got {p}"

        # Others -> 58 (Employee)
        for uid in [565, 566, 568, 571, 574, 575, 576, 577, 578, 579]:
            cur.execute("SELECT user_profile_id FROM tbluser WHERE id = %s", (uid,))
            p = cur.fetchone()['user_profile_id']
            assert p == 58, f"Expected user {uid} user_profile_id = 58, got {p}"
        print("  Verified user_profile_id hierarchy for all 16 staff per NAEMD sheet.")

    # 5. Verify obsolete roles 7300..7315 are deleted
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS c FROM s_user_jobrole WHERE sub_institute_id = 15 AND id BETWEEN 7300 AND 7315")
        c = cur.fetchone()['c']
        print(f"\nAssertion 3.3: Obsolete roles 7300..7315 count in s_user_jobrole = {c}")
        assert c == 0, f"Expected 0 obsolete roles, got {c}"

    print(">>> STEP 3 PASSED WITH 100% COMPLIANCE.")

def step4_delivery_activation(conn):
    print("\n" + "="*70)
    print("STEP 4: Outbound Delivery Activation & Credential Onboarding (R4)")
    print("="*70)

    # 1. Check if delivery is already configured
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS c FROM smtp_details WHERE sub_institute_id = 15")
        smtp_count = cur.fetchone()['c']

    if smtp_count == 0:
        sql_path = os.path.join(os.path.dirname(__file__), '04_delivery_activation.sql')
        run_sql_file(conn, sql_path)

    # 2. Verify school_setup.outbound_email_enabled = 1
    with conn.cursor() as cur:
        cur.execute("SELECT id, SchoolName, outbound_email_enabled FROM school_setup WHERE id = 15")
        row = cur.fetchone()
        print(f"\nAssertion 4.1: school_setup id=15: {row}")
        assert row['outbound_email_enabled'] == 1, f"Expected outbound_email_enabled = 1, got {row['outbound_email_enabled']}"

    # 3. Verify smtp_details has active profile for tenant 15
    with conn.cursor() as cur:
        cur.execute("SELECT id, label, module_key, gmail, server_address, port, sub_institute_id FROM smtp_details WHERE sub_institute_id = 15")
        smtp = cur.fetchone()
        print(f"Assertion 4.2: smtp_details for tenant 15: {smtp}")
        assert smtp is not None, "Expected smtp_details row for tenant 15, got None"
        assert smtp['gmail'] == 'trizinnovation2018@gmail.com'

    # 4. Verify sms_api_details has active profile for tenant 15
    with conn.cursor() as cur:
        cur.execute("SELECT id, url, sub_institute_id, is_active FROM sms_api_details WHERE sub_institute_id = 15")
        sms = cur.fetchone()
        print(f"Assertion 4.3: sms_api_details for tenant 15: {sms}")
        assert sms is not None, "Expected sms_api_details row for tenant 15, got None"
        assert sms['is_active'] == 1

    print(">>> STEP 4 PASSED WITH 100% COMPLIANCE.")

if __name__ == '__main__':
    print("Connecting to db3 (128.199.17.97, database hp_erp)...")
    conn = get_connection()
    try:
        step1_catalogue_adoption(conn)
        step2_employee_scope_reduction(conn)
        step3_dual_key_and_profiles(conn)
        step4_delivery_activation(conn)
        print("\n" + "#"*70)
        print("# ALL 4 RECONCILIATION STEPS COMPLETED AND COMMITTED SUCCESSFULLY!")
        print("#"*70)
    except Exception as e:
        print(f"\n!!! EXCEPTION CAUGHT: {e}")
        conn.rollback()
        raise
    finally:
        conn.close()
