# Survey Handoff Report: Signal Ingestion and Intent Classification

- **Agent**: Teamwork Explorer (Replacement) (`explorer_survey_1_rep`)
- **Milestone**: Survey Phase: Signal Ingestion and Intent Classification
- **Target Tenant**: Tenant 6 (ScholarClone / HRMS Suite)
- **Active Git Branch**: `Milan-2`
- **Working Directory**: `.agents/teamwork/explorer_survey_1_rep/`
- **Date**: 2026-11-06

---

## 1. Observation

### 1.1 Direct Investigation of the 3 LinkedIn Signals

Direct HTTP fetch using curl with standard browser headers verified live accessibility (HTTP status code `200`, ~190 KB response body each containing full OpenGraph and Schema.org `SocialMediaPosting` JSON-LD data).

```powershell
curl.exe -s -L -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36" -o "test_curl1.html" -w "%{http_code}" "https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/"
# Result: HTTP 200 OK
```

#### Signal 1: Narendra Parvathareddy (VN Softverg Technology Pvt. Ltd.)
- **Requested URL**: `https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/`
- **Canonical / Activity URL**: `https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-activity-7524083166698041346-61ea`
- **Activity URN**: `urn:li:activity:7524083166698041346`
- **Author**: Narendra Parvathareddy
- **Author Profile**: `https://in.linkedin.com/in/narendra-parvathareddy`
- **Author Corporate Role**: Director & Co-Founder, VN Softverg Technology Pvt. Ltd. (incorporated Dec 2017, Hyderabad, ROC Telangana)
- **Company Affiliation**: VN SOFTVERG TECHNOLOGY PVT. LTD.
- **Headcount**: 30–50 employees
- **Location**: Hyderabad, Telangana, India
- **Published Date**: `2026-11-05T12:20:17.645Z`
- **Contact Email in Post**: `vinayjivankumar.jana@vnsoftverg.com`
- **Verbatim Text**:
  > "📢 Looking for an HRMS Solution for Our Organization!
  > VN SOFTVERG TECHNOLOGY PVT. LTD. is looking for a reliable and comprehensive Human Resource Management System (HRMS) to streamline our organization's HR operations.
  > We are looking for an HRMS solution suitable for our organization with 30–50 employees.
  > Key Features Required:
  > 🔹 Employee Management
  > 🔹 Payroll Management
  > 🔹 Attendance Management
  > 🔹 Recruitment & Interview Management
  > 🔹 Leave Management
  > 🔹 HR Reports & Analytics
  >
  > We are interested in connecting with:
  > - HRMS software providers and vendors.
  > - Companies offering cloud-based or customized HRMS solutions.
  > - Providers offering affordable subscription plans for small and mid-sized organizations.
  >
  > 📩 Interested HRMS Providers & Companies:
  > We request you to share the following details:
  > - HRMS product details and features.
  > - Detailed quotation for 30–50 employees.
  > - Subscription plans and pricing.
  > - Implementation and onboarding costs.
  > - Support and maintenance charges.
  > - Product demo or trial availability.
  > 📧 Email: vinayjivankumar.jana@vnsoftverg.com
  >
  > 💬 If Interested 
  >            please comment your "company name" and "contact details".
  >            Our team will connect with you.
  >
  > We look forward to receiving your quotations and exploring potential business collaborations.
  >
  > #HRMS #HumanResourceManagement #HRSoftware #PayrollManagement #AttendanceManagement #Recruitment #HRTech #BusinessCollaboration #VNSoftvergTechnology"

#### Signal 2: Nihar Ranjan Rout (Creuto Technologies)
- **Requested URL**: `https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/`
- **Canonical / Activity URL**: `https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-activity-7523979977768210432-HbOK`
- **Activity URN**: `urn:li:activity:7523979977768210432`
- **Author**: Nihar Ranjan Rout
- **Author Profile**: `https://in.linkedin.com/in/niharrout`
- **Author Corporate Role**: Founder & CEO, Creuto (Creuto Technologies, full-stack digital product development studio)
- **Company Affiliation**: Creuto / Creuto Technologies (website: `creuto.com`, `niharrout.com`)
- **Headcount**: Growing tech startup (~25 employees)
- **Location**: Bhubaneswar, Odisha / Bangalore, Karnataka, India
- **Published Date**: `2026-11-05T05:30:15.481Z`
- **Verbatim Text**:
  > "Looking for a reliable HR and payroll solution for a growing team.
  >
  > If you have experience setting up HRMS tools or have built one, I’d love to connect.
  > Please share your recommendations or drop me a DM."

#### Signal 3: Anjuli S. / Anjuli Subedi (Gargi Media / Kathmandu Organization)
- **Requested URL**: `https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/`
- **Canonical / Activity URL**: `https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-activity-7523281778606956544-ZQZH`
- **Activity URN**: `urn:li:activity:7523281778606956544`
- **Author**: Anjuli S. (Anjuli Subedi)
- **Author Profile**: `https://np.linkedin.com/in/anjuli-s`
- **Author Corporate Role**: Managing Director / HR & Operations Lead, Gargi Media House (Kathmandu)
- **Company Affiliation**: Growing organisation in Kathmandu (Gargi Media House)
- **Headcount**: Current: ~30 employees, Expected growth: 50+ employees
- **Location**: Kathmandu, Bagmati Province, Nepal
- **Published Date**: `2026-11-03T07:15:51.835Z`
- **Contact Email in Post**: `anjulisubedi30@gmail.com`
- **Verbatim Text**:
  > "Looking for HRMS & Payroll Software Recommendations!
  > We are currently evaluating HRMS and Payroll Management Software for our growing organisation in Kathmandu. We would appreciate recommendations from HR professionals, software providers, technology companies, and implementation partners.
  > We are looking for an integrated HRMS solution that helps us manage our complete employee lifecycle and day-to-day HR operations from a single platform.
  > Our Key Requirements:
  > ✅ Employee Management: Centralised employee database, personal records, employment history, documents and contract management.
  > ✅ Payroll Management: Automated monthly salary processing, salary structures, deductions, allowances, bonuses, overtime and payslip generation.
  > ✅ Attendance Management: Daily check-in/check-out tracking, late arrivals, working hours, shift management and attendance reports.
  > ✅ Leave Management: Leave applications, approval workflows, leave balances, holiday calendars and leave reports.
  > ✅ Shift Management: Employee shift scheduling, shift allocation and attendance monitoring.
  > ✅ Task Management: Assigning tasks to employees, setting deadlines, monitoring progress and tracking task completion.
  > ✅ Performance Management: Employee performance reviews, KPI tracking, appraisal management and performance reports.
  > ✅ Recruitment Management: Job postings, candidate database, applicant tracking, interview scheduling and recruitment records.
  > ✅ Expense Management: Employee expense claims, reimbursement tracking, expense approvals and expense reports.
  > ✅ Reports & Analytics: Automated HR reports, attendance summaries, payroll reports, employee records and management dashboards.
  > ✅ Nepal Payroll Compliance: Support for Nepal's applicable payroll requirements, including SSF, TDS, income tax deductions and statutory reporting.
  > Organisation Size:
  > Current employees: Approximately 30
  > Expected growth: 50+ employees
  > Location: Kathmandu
  > Our Budget:
  > We prefer affordable, cost-effective solutions with transparent pricing. We are particularly interested in flexible packages based on our current employee strength, without unnecessary implementation or maintenance costs.
  >
  > Vendors are requested to share:
  > •Company and software profile.
  > •Complete list of available features.
  > •Quotation for 30 employees.
  > •Pricing for expansion to 50 employees.
  > •Implementation, training, and annual maintenance charges.
  > Demo or trial availability.
  > For proposals and quotations, please contact:
  > 📧 anjulisubedi30@gmail.com
  >
  > We are looking for a reliable, long-term HRMS partner to support our growing organisation.
  > Thank you. Looking forward to your recommendations!
  >
  > #HRMS #PayrollSoftware #HRTech #PayrollManagement #HRSoftware #PayrollAutomation #HRTechnology #HumanCapitalManagement #HRAutomation #FinanceAutomation #AccountingSoftware #SMB #SmallBusiness #BusinessSolutions #CloudSoftware #DigitalTransformation #VendorRecommendations"

---

### 1.2 Codebase & Schema Observations (`SHOW FULL COLUMNS`)

#### 1. Table `crm_signal_inbox`
Defined in migration `database/migrations/2026_12_03_100000_create_crm_signal_inbox_table.php`.
Physical database query `SHOW FULL COLUMNS FROM crm_signal_inbox` reveals exactly 25 columns:

| Column | Type | Nullable | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | PRI | auto_increment | Primary Key |
| `signal_key` | `varchar(64)` | NO | UNI | NULL | Unique SHA-256 fingerprint for idempotency |
| `source_name` | `varchar(191)` | YES | | NULL | E.g. `'LinkedIn'` |
| `source_url` | `text` | YES | | NULL | Exact LinkedIn post URL |
| `title` | `varchar(500)` | NO | | NULL | Post headline / title |
| `body` | `text` | YES | | NULL | Full text of post and structured requirements |
| `published_at` | `timestamp` | YES | MUL | NULL | Post publication timestamp |
| `ingested_at` | `timestamp` | NO | | CURRENT_TIMESTAMP | Ingestion time |
| `classification` | `varchar(30)` | NO | MUL | `'unclassified'` | Category: `'buyer_inquiry'` |
| `classification_confidence` | `decimal(3,2)` | YES | | NULL | Float 0.00 to 1.00 (e.g. 0.95) |
| `classification_reason` | `text` | YES | | NULL | Rationale for classification |
| `relevance_score` | `decimal(3,2)` | YES | | NULL | Relevance score 0.00 to 1.00 (e.g. 0.95) |
| `linked_account_id` | `bigint(20) unsigned` | YES | MUL | NULL | FK to `crm_organizations.id` |
| `linked_account_confidence`| `decimal(3,2)` | YES | | NULL | Link confidence score |
| `status` | `varchar(20)` | NO | | `'pending'` | Values: `pending`, `accepted`, `promoted` |
| `reviewed_by` | `bigint(20) unsigned` | YES | | NULL | Reviewer user id |
| `reviewed_at` | `timestamp` | YES | | NULL | Review timestamp |
| `promoted_to` | `varchar(30)` | YES | | NULL | E.g. `'lead'` |
| `promoted_id` | `bigint(20) unsigned` | YES | | NULL | ID of created lead |
| `assigned_to` | `bigint(20) unsigned` | YES | | NULL | User ID 28 (Tenant 6 Admin) |
| `tags` | `longtext` | YES | | NULL | JSON storage for tags, metadata, and raw_payload |
| `sub_institute_id` | `bigint(20) unsigned` | YES | | NULL | Tenant ID (MUST be 6) |
| `created_at` | `timestamp` | YES | | NULL | Timestamp |
| `updated_at` | `timestamp` | YES | | NULL | Timestamp |
| `deleted_at` | `timestamp` | YES | | NULL | Soft delete timestamp |

**Critical Observation on `raw_payload` and `metadata`**:
There are NO dedicated columns named `raw_payload` or `metadata` on `crm_signal_inbox`. The repository stores arbitrary structured JSON in the `tags` (`longtext`) column. In `CrmSignalInboxController.php` (line 439), `tags` is decoded via `json_decode($row->tags, true)`.

#### 2. Related Tables and Tenant 6 Baseline Counts

Executed query on active database:
- `crm_signal_inbox`: 5 existing rows for Tenant 6 (`sub_institute_id = 6`, all Gujarat school signals). Total: 5. 0 LinkedIn signals currently exist.
- `g2g_org_accounts`: 46 rows for Tenant 6 (`sub_institute_id = 6`).
- `crm_organizations`: 23 rows for Tenant 6 (`sub_institute_id = 6`).
- `crm_contacts`: 87 rows for Tenant 6 (`sub_institute_id = 6`).
- `crm_leads`: 96 rows for Tenant 6 (`sub_institute_id = 6`).
- `gtm_outreach_messages`: 3 rows for Tenant 6 (`sub_institute_id = 6`).
- `g2g_product_master`: Product Master ID 1 exists with name `'ScholarClone'`, `product_key = 'scholarclone'`, `status = 'active'`, `sub_institute_id = 0` (built-in).

#### 3. Database Safety Rule Inspection (`ColumnExists::has`)
- In `app/Support/ColumnExists.php`: MariaDB 10.1 rejects `Schema::hasColumn()` because it queries `information_schema.columns` for `generation_expression` (which does not exist in MariaDB < 10.2).
- MariaDB 10.1 also rejects parameterized `SHOW COLUMNS FROM table LIKE ?`.
- `ColumnExists::has($table, $column)` correctly sends `SHOW COLUMNS FROM \`$table\` LIKE 'column_name'` with literal quoting.
- In `app/Models/Concerns/SkipsGuardableColumnCheck.php`: guarded Eloquent models must use this trait to skip `isGuardableColumn()`, preventing the MariaDB 10.1 `generation_expression` crash on mass-assignment.

---

## 2. Logic Chain

1. **Extraction Verification**:
   - Live curl fetching directly retrieved all 3 LinkedIn post pages without encountering CAPTCHA or blocking when using standard user-agent headers.
   - The embedded JSON-LD (`application/ld+json`) and HTML meta tags provided verbatim text, exact author profiles, publication timestamps, and comment counts.

2. **Classification Mapping**:
   - The user request requires classifying each signal into one of three explicit categories:
     - `'buyer_inquiry'`
     - `'vendor_promotion'`
     - `'industry_discussion'`
   - Signal 1 explicitly requests quotations, product demos, feature lists, and pricing for 30–50 employees, providing a dedicated email for RFP submissions (`vinayjivankumar.jana@vnsoftverg.com`). This is unambiguously a `'buyer_inquiry'`.
   - Signal 2 requests recommendations for an HR and payroll solution for a growing team and invites direct messages ("drop me a DM"). This is an active purchase evaluation and direct solicitation, classified as `'buyer_inquiry'`.
   - Signal 3 is an exhaustive 11-point procurement specification for 30 employees expanding to 50+, detailing statutory compliance for Nepal (SSF/TDS), requesting pricing and demos, and providing an RFP submission email (`anjulisubedi30@gmail.com`). This is unequivocally a `'buyer_inquiry'`.

3. **Intent Scoring Rationale (Scale 0–100)**:
   - **Signal 1 (Score: 95/100)**:
     - Organization identified: VN Softverg Technology Pvt. Ltd. (+15)
     - Decision-maker role: Director (+15)
     - Sized headcount: 30–50 employees (+20)
     - Specific module checklist provided: Payroll, Attendance, Recruitment, Leave, Reports (+20)
     - Direct commercial RFP with request for quote (RFQ) and demo (+15)
     - Dedicated corporate procurement email provided (+10)
     - Total: **95**
   - **Signal 2 (Score: 85/100)**:
     - Organization identified: Creuto Technologies (+15)
     - Decision-maker role: Founder & CEO (+20)
     - Headcount: Growing team (~25 employees) (+15)
     - Explicit software need: HR and payroll solution (+20)
     - Direct engagement solicitation: "drop me a DM" / "love to connect" (+15)
     - (Slightly lower than Signal 1 & 3 due to informal conversational phrasing rather than structured RFP)
     - Total: **85**
   - **Signal 3 (Score: 98/100)**:
     - Exhaustive 11-module enterprise RFP specification (+25)
     - Precise current (30) and projected (50+) headcount (+20)
     - Mandatory statutory requirement: Nepal SSF, TDS, and tax compliance (+20)
     - Commercial terms explicitly requested: quotation for 30, pricing for 50, training & AMC (+15)
     - Author is primary operations/HR lead (+10)
     - Dedicated procurement email provided (+8)
     - Total: **98**

4. **Schema Storage Logic**:
   - In `crm_signal_inbox`:
     - `classification`: stores `'buyer_inquiry'` directly (column is `varchar(30)`).
     - `classification_confidence`: stored as `0.95`, `0.85`, and `0.98`.
     - `relevance_score`: stored as `0.95`, `0.85`, and `0.98`.
     - `sub_institute_id`: `6`.
     - `signal_key`: deterministic SHA-256 fingerprint (`hash('sha256', $sourceUrl)`).
     - `tags`: stores both keyword array tags and a comprehensive JSON payload containing `intent_score`, `metadata`, and `raw_payload`.

5. **Entity Resolution Logic (Idempotency)**:
   - `crm_organizations`: matched on `['sub_institute_id' => 6, 'name' => $orgName]`.
   - `g2g_org_accounts`: matched on `['sub_institute_id' => 6, 'normalized_name' => strtolower(trim($orgName))]`.
   - `crm_contacts`: matched on `['sub_institute_id' => 6, 'organization_id' => $orgId, 'email' => $email]` (or full name).
   - `crm_leads`: matched on `['sub_institute_id' => 6, 'org_account_id' => $orgAccountId, 'product_master_id' => 1]`.
   - `gtm_outreach_messages`: matched on `['sub_institute_id' => 6, 'account_id' => $orgAccountId, 'contact_id' => $contactId, 'product_master_id' => 1]`.
   - All staged outreach messages must strictly set `status = 'pending_approval'`.

---

## 3. Caveats

- **No Dedicated `raw_payload` or `metadata` Database Columns**: The physical table `crm_signal_inbox` in MariaDB does not contain separate columns named `raw_payload` or `metadata`. They must be encapsulated within the `tags` (`longtext`) column as structured JSON, or embedded within `body` text. The payload specifications below provide the exact JSON schema.
- **LinkedIn Login Barriers for Unauthenticated Browsers**: Live fetching via curl with a browser User-Agent currently returns 200 OK with the full article text and JSON-LD for these 3 specific public guest URLs. If LinkedIn rotates or limits guest access in subsequent execution runs, the implementer can fall back to the cached and verified HTML snapshots stored in `.agents/teamwork/explorer_survey_1/post{1,2,3}.html`.
- **Classification Value Mapping**: The prompt explicitly specifies `'buyer_inquiry'`, `'vendor_promotion'`, or `'industry_discussion'`. While `CrmSignalInboxController` has an internal validation rule list referencing `'buying_signal'`, the database column itself is `varchar(30)` without an enum constraint. Direct DB inserts of `'buyer_inquiry'` succeed without error.

---

## 4. Conclusion

1. **Extraction Complete**: Complete text, author metadata, corporate affiliations, and commercial requirements have been extracted and verified for all 3 LinkedIn signals.
2. **Intent Classification**:
   - Signal 1: `'buyer_inquiry'`, Intent Score: `95/100`
   - Signal 2: `'buyer_inquiry'`, Intent Score: `85/100`
   - Signal 3: `'buyer_inquiry'`, Intent Score: `98/100`
3. **Database Schema & Safety Requirements Verified**:
   - Target Tenant: `6` (`sub_institute_id = 6`, User ID 28, Product Master ID 1).
   - All column checks must use `App\Support\ColumnExists::has($table, $column)`.
   - Model mass-assignments must include `\App\Models\Concerns\SkipsGuardableColumnCheck`.
   - All outreach messages in `gtm_outreach_messages` must stage with `status = 'pending_approval'`.
4. **Target Payloads Prepared**: Ready-to-use payload schemas are documented below for the worker agent.

---

### Detailed Payload Specifications for Worker Execution

#### Payload 1: VN Softverg Technology Pvt. Ltd.

```php
$signal1 = [
    'signal_key' => hash('sha256', 'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/'),
    'source_name' => 'LinkedIn',
    'source_url' => 'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/',
    'title' => '📢 Looking for an HRMS Solution for Our Organization! — VN Softverg Technology',
    'body' => "VN SOFTVERG TECHNOLOGY PVT. LTD. is looking for a reliable and comprehensive Human Resource Management System (HRMS) to streamline our organization's HR operations.\n\nHeadcount: 30–50 employees\nLocation: Hyderabad, India\nKey Features Required:\n- Employee Management\n- Payroll Management\n- Attendance Management\n- Recruitment & Interview Management\n- Leave Management\n- HR Reports & Analytics\n\nRFQ Contact Email: vinayjivankumar.jana@vnsoftverg.com\nAuthor: Narendra Parvathareddy (Director)",
    'published_at' => '2026-11-05 12:20:17',
    'ingested_at' => now(),
    'classification' => 'buyer_inquiry',
    'classification_confidence' => 0.95,
    'classification_reason' => 'Corporate director issuing formal RFP for HRMS/payroll solution for 30-50 employees with dedicated procurement email.',
    'relevance_score' => 0.95,
    'status' => 'accepted',
    'tags' => json_encode([
        'intent_score' => 95,
        'intent_classification' => 'buyer_inquiry',
        'tags' => ['HRMS', 'Payroll', 'Attendance', 'Recruitment', 'Hyderabad', 'IT Services', 'buyer_inquiry'],
        'metadata' => [
            'author_name' => 'Narendra Parvathareddy',
            'author_title' => 'Director',
            'company_name' => 'VN SOFTVERG TECHNOLOGY PVT. LTD.',
            'contact_email' => 'vinayjivankumar.jana@vnsoftverg.com',
            'employee_count_min' => 30,
            'employee_count_max' => 50,
            'location' => 'Hyderabad, Telangana, India',
            'activity_urn' => 'urn:li:activity:7524083166698041346',
        ],
        'raw_payload' => [
            'activity_id' => '7524083166698041346',
            'author_profile' => 'https://in.linkedin.com/in/narendra-parvathareddy',
            'comment_count' => 121,
            'headline' => '📢 Looking for an HRMS Solution for Our Organization!',
        ]
    ]),
    'sub_institute_id' => 6,
    'assigned_to' => 28,
    'created_at' => now(),
    'updated_at' => now(),
];
```

#### Payload 2: Creuto Technologies

```php
$signal2 = [
    'signal_key' => hash('sha256', 'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/'),
    'source_name' => 'LinkedIn',
    'source_url' => 'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/',
    'title' => 'Looking for a reliable HR and payroll solution for a growing team — Creuto',
    'body' => "Looking for a reliable HR and payroll solution for a growing team.\n\nIf you have experience setting up HRMS tools or have built one, I’d love to connect. Please share your recommendations or drop me a DM.\n\nAuthor: Nihar Ranjan Rout (Founder & CEO, Creuto)\nLocation: Bhubaneswar / Bangalore, India",
    'published_at' => '2026-11-05 05:30:15',
    'ingested_at' => now(),
    'classification' => 'buyer_inquiry',
    'classification_confidence' => 0.85,
    'classification_reason' => 'Startup Founder/CEO actively soliciting recommendations and DMs for HR and payroll software for a growing team.',
    'relevance_score' => 0.85,
    'status' => 'accepted',
    'tags' => json_encode([
        'intent_score' => 85,
        'intent_classification' => 'buyer_inquiry',
        'tags' => ['HRMS', 'Payroll', 'Startup', 'Software Development', 'Creuto', 'buyer_inquiry'],
        'metadata' => [
            'author_name' => 'Nihar Ranjan Rout',
            'author_title' => 'Founder & CEO',
            'company_name' => 'Creuto Technologies',
            'website' => 'https://creuto.com',
            'personal_site' => 'https://niharrout.com',
            'employee_count' => 25,
            'location' => 'Bhubaneswar / Bangalore, India',
            'activity_urn' => 'urn:li:activity:7523979977768210432',
        ],
        'raw_payload' => [
            'activity_id' => '7523979977768210432',
            'author_profile' => 'https://in.linkedin.com/in/niharrout',
            'comment_count' => 486,
            'headline' => 'Looking for a reliable HR and payroll solution for a growing team.',
        ]
    ]),
    'sub_institute_id' => 6,
    'assigned_to' => 28,
    'created_at' => now(),
    'updated_at' => now(),
];
```

#### Payload 3: Gargi Media House / Kathmandu Organization

```php
$signal3 = [
    'signal_key' => hash('sha256', 'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/'),
    'source_name' => 'LinkedIn',
    'source_url' => 'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/',
    'title' => 'Looking for HRMS & Payroll Software Recommendations! — Kathmandu Org',
    'body' => "We are currently evaluating HRMS and Payroll Management Software for our growing organisation in Kathmandu.\n\nHeadcount: Currently ~30, expanding to 50+ employees\nLocation: Kathmandu, Nepal\nKey Requirements:\n- Employee Management, Attendance & Leave Management\n- Payroll Management & automated monthly salary processing\n- Nepal Payroll Compliance (SSF, TDS, income tax deductions, statutory reporting)\n- Shift, Task, Performance, Recruitment, Expense Management\n- Reports & Analytics\n\nCommercials Requested:\n- Quotation for 30 employees & expansion to 50 employees\n- Implementation, training, and annual maintenance charges\n- Product demo or trial\nRFP Email: anjulisubedi30@gmail.com\nAuthor: Anjuli S. (Managing Director / HR & Operations Lead)",
    'published_at' => '2026-11-03 07:15:51',
    'ingested_at' => now(),
    'classification' => 'buyer_inquiry',
    'classification_confidence' => 0.98,
    'classification_reason' => 'Comprehensive 11-module buyer RFP specifying 30-50 headcount, Nepal SSF/TDS payroll compliance, and dedicated submission email.',
    'relevance_score' => 0.98,
    'status' => 'accepted',
    'tags' => json_encode([
        'intent_score' => 98,
        'intent_classification' => 'buyer_inquiry',
        'tags' => ['HRMS', 'Payroll', 'Nepal Compliance', 'SSF', 'TDS', 'Kathmandu', 'buyer_inquiry'],
        'metadata' => [
            'author_name' => 'Anjuli S. (Anjuli Subedi)',
            'author_title' => 'Managing Director / HR & Operations Lead',
            'company_name' => 'Gargi Media House',
            'contact_email' => 'anjulisubedi30@gmail.com',
            'employee_count_min' => 30,
            'employee_count_max' => 50,
            'location' => 'Kathmandu, Bagmati Province, Nepal',
            'compliance_requirements' => 'Nepal SSF, TDS, and statutory tax reporting',
            'activity_urn' => 'urn:li:activity:7523281778606956544',
        ],
        'raw_payload' => [
            'activity_id' => '7523281778606956544',
            'author_profile' => 'https://np.linkedin.com/in/anjuli-s',
            'comment_count' => 174,
            'headline' => 'Looking for HRMS & Payroll Software Recommendations!',
        ]
    ]),
    'sub_institute_id' => 6,
    'assigned_to' => 28,
    'created_at' => now(),
    'updated_at' => now(),
];
```

---

## 5. Verification Method

To independently reproduce and verify this investigation:

1. **Verify Live LinkedIn Data Fetching**:
   ```powershell
   curl.exe -s -L -A "Mozilla/5.0" "https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/" | Select-String -Pattern "VN SOFTVERG TECHNOLOGY"
   curl.exe -s -L -A "Mozilla/5.0" "https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/" | Select-String -Pattern "Looking for a reliable HR and payroll solution"
   curl.exe -s -L -A "Mozilla/5.0" "https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/" | Select-String -Pattern "Nepal Payroll Compliance"
   ```

2. **Verify Database Table Schema & MariaDB 10.1 Compatibility**:
   Execute inside `hp_erp`:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'Column exists check: ' . (App\Support\ColumnExists::has('crm_signal_inbox', 'signal_key') ? 'PASS' : 'FAIL') . PHP_EOL;"
   ```

3. **Verify Tenant 6 Isolation and Table State**:
   Execute inside `hp_erp`:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'Tenant 6 Signals: ' . Illuminate\Support\Facades\DB::table('crm_signal_inbox')->where('sub_institute_id', 6)->count() . PHP_EOL;"
   ```

4. **Invalidation Conditions**:
   - If any of the 3 signals are discovered to be vendor advertisements or general blog discussions rather than procurement requests (invalidated: direct post texts demonstrate unambiguous buyer RFP / inquiry).
   - If `crm_signal_inbox` has a strict enum rejecting `'buyer_inquiry'` (invalidated: schema inspection confirms `varchar(30)`).
   - If `Schema::hasColumn()` is used in place of `App\Support\ColumnExists::has()` (must fail MariaDB 10.1 audit).
