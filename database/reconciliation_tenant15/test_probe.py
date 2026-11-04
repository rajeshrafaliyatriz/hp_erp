import pymysql

conn = pymysql.connect(
    host='128.199.17.97',
    user='dev_db',
    password='dev@sql',
    database='hp_erp',
    charset='utf8mb4'
)
with conn.cursor() as cur:
    cur.execute("SHOW TABLES LIKE 'tbluser_backup_tenant15_purge_20261104'")
    res = cur.fetchone()
    print("tbluser_backup_tenant15_purge_20261104 exists:", res is not None)
conn.close()
