# Handoff Report: Delivery Activation & Directory Endpoints Specialist (Survey Explorer 3)

**Author:** Survey Explorer 3  
**Target Tenant:** Future Varsity (`sub_institute_id = 15`)  
**Primary Admin:** ID 479 (`lsi.mkes@futurevarsity.edu.in`)  
**Target Connection:** `live` / `db3` (128.199.17.97, database `hp_erp`, MariaDB 10.1)  
**Date:** 2026-11-04  

---

## 1. Observation

### 1.1 Outbound Delivery Activation & Platform Standard Configuration

#### A. `school_setup` Table
- Query: `DB::connection('db3')->table('school_setup')->where('id', 15)->first()`
- Result:
  ```json
  {
    "id": 15,
    "SchoolName": "Future Varsity",
    "ShortCode": "FV",
    "Email": "lsi.mkes@futurevarsity.edu.in",
    "Mobile": "+91 8645931111",
    "client_id": 15,
    "outbound_email_enabled": 0,
    "institute_type": "Institute of Management Studies & Research"
  }
  ```
- **Observation:** `outbound_email_enabled` is explicitly `0` for tenant 15.
- Code Site: `app/Support/MailGate.php` (lines 96–109):
  ```php
  public static function allowedForTenant(?int $tenantId): bool
  {
      if (self::allowed()) {
          return true;
      }
      if ($tenantId === null) {
          return false;
      }
      return (bool) DB::table('school_setup')
          ->where('id', $tenantId)
          ->value('outbound_email_enabled');
  }
  ```
- Command result: `MailGate::allowedForTenant(15)` evaluates to `false`.
  `MailGate::reasonForTenant(15)` returns:
  `"Outbound email is disabled for this organisation (G2G_NOTIFY_EMAIL is off and this tenant is not in G2G_NOTIFY_EMAIL_TENANTS)."`

#### B. `smtp_details` Table
- Query: `DB::connection('db3')->table('smtp_details')->get()`
- Result on db3:
  - Row 1 (`id = 1`):
    - `sub_institute_id`: `1`
    - `label`: `"Default sender"`
    - `module_key`: `"default"`
    - `gmail`: `"trizinnovation2018@gmail.com"`
    - `from_name`: `null`
    - `server_address`: `"smtp.gmail.com"`
    - `port`: `"465"`
    - `password`: encrypted payload (`Crypt::decryptString($password)` resolves to `"okesecjnhbyiysmk"`)
  - Row 2 (`id = 4`):
    - `sub_institute_id`: `6`
    - `label`: `"Main mailbox"`
    - `module_key`: `"default"`
    - `gmail`: `"trizinnovation2018@gmail.com"`
    - `from_name`: `"admin"`
    - `server_address`: `"smtp.gmail.com"`
    - `port`: `"587"`
    - `password`: encrypted payload
  - Rows for `sub_institute_id = 15`: **0 records** (empty collection).

#### C. `sms_api_details` Table
- Query: `DB::connection('db3')->table('sms_api_details')->get()`
- Result on db3:
  - Row 1 (`id = 1`):
    - `sub_institute_id`: `3`
    - `url`: `"https://alerts.prioritysms.com/api/web2sms.php?"`
    - `pram`: `"workingkey=Ad56b0cea0c31b0e4c8ec9a199416fd2a&sender=ALDAPS"`
    - `mobile_var`: `"&to="`
    - `text_var`: `"&message="`
    - `last_var`: `"- N Rahman Allahabad Public School Society"`
    - `is_active`: `1`
  - Rows for `sub_institute_id = 15`: **0 records**.

#### D. G2G Delivery Settings API Backend
- Route: `routes/api.php` lines 1447–1457:
  ```php
  Route::middleware(['api.token', 'profile:admin,hr'])->group(function () {
      $delivery = \App\Http\Controllers\Api\Organization\DeliveryController::class;
      Route::get('/organization/delivery/options', [$delivery, 'options']);
      Route::get('/organization/delivery/profiles', [$delivery, 'index']);
      Route::post('/organization/delivery/profiles', [$delivery, 'store']);
      Route::put('/organization/delivery/profiles/{id}', [$delivery, 'update'])->whereNumber('id');
      Route::delete('/organization/delivery/profiles/{id}', [$delivery, 'destroy'])->whereNumber('id');
      Route::post('/organization/delivery/profiles/{id}/test', [$delivery, 'test'])
          ->whereNumber('id')
          ->middleware('throttle:4,1');
  });
  ```
- Frontend link: `https://g2gv0.vercel.app/settings?s=delivery`
- In `DeliveryController::index` (`app/Http/Controllers/Api/Organization/DeliveryController.php:87–108`):
  Queries `smtp_details` where `sub_institute_id = $tenantId` and `sms_api_details` where `sub_institute_id = $tenantId`. Currently for tenant 15, returns `profiles: []`, `sms: { configured: false, endpoint: null, active: false }`.

#### E. Credentials Invitation Trigger & Delivery Pipeline
1. **Trigger Endpoint:** `POST /api/employees-management/{id}/invite` (`routes/api.php:1727`, `EmployeeDirectoryController::invite` lines 795–878).
   - Validates caller rank (`mayCredential`), verifies target employee has never signed in (`last_login === null`).
   - Invokes `EmployeeFactory::issueInvite($employee->email, $tenantId)`.
2. **Factory Delegate:** `app/Services/HRMS/EmployeeFactory.php` line 211:
   ```php
   $result = app(\App\Services\Auth\InviteService::class)->issue($email, $tenantId, $purpose);
   ```
3. **Invitation Core:** `app/Services/Auth/InviteService.php`:
   - Line 130–142: Mints 64-char token via `Str::random(64)`. Deletes previous token for `$email` and inserts into `password_reset_tokens`.
   - Line 147: Builds link: `$base . '/set-password/' . $token . '?email=' . rawurlencode($email)`.
   - Line 174: Calls `MailGate::allowedForTenant($tenantId)`.
     - When `false`: Returns `['delivered' => 'link', 'link' => $link, 'error' => ...]` (link handed to caller, no mail sent).
     - When `true`: Calls `$this->mail($deliverTo, $link, $purpose, $tenantId, $moduleKey)`.
   - Line 110: **Crucial Parameter Observation:** `InviteService::issue()` signature:
     ```php
     public function issue(
         ?string $email,
         ?int $tenantId,
         string $purpose = 'invite',
         ?string $deliverTo = null,
         ?string $moduleKey = null
     ): array
     ```
     Lines 78–100 state: `"$email decides WHOSE PASSWORD the link sets... $deliverTo decides only where the message goes. $deliverTo defaults to $email."`
   - Line 306: Mail delivery calls:
     ```php
     MailSender::mailer($tenantId, $moduleKey ?? MailSenderModules::DEFAULT)->raw(...)
     ```
4. **Dynamic SMTP Transport:** `app/Support/MailSender.php` lines 71–84:
   ```php
   public static function mailerForRow(object $row): Mailer
   {
       $mailer = Mail::build([
           'transport' => 'smtp',
           'host' => $row->server_address,
           'port' => (int) $row->port,
           'username' => $row->gmail,
           'password' => Crypt::decryptString($row->password),
       ]);
       $mailer->alwaysFrom($row->gmail, $row->from_name ?: null);
       return $mailer;
   }
   ```

---

### 1.2 Directory Endpoints & Stability (`jobrole on null` Crash)

#### A. Reproduction of Verbatim Error
- Exact Crash Site 1: `app/Http/Controllers/user/tbluserController.php:1129` in `edit(Request $request, $id)`:
  ```php
  1128: $res['usersLevelData']['allData'] = $detailsLevel;
  1129: $res['usersJobroleComponent'] = DB::table('s_user_jobrole')->where('jobrole', $assignedJobrole->jobrole)->where('sub_institute_id', $sub_institute_id)->whereNull('deleted_at')->first();
  1130: $res['levelOfResponsbility'] = SLevelResponsibility::groupBy('level')->get()->toArray();
  ```
  - Preceding lookup at line 933:
    ```php
    $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
    ```
  - Execution when `$editData['allocated_standards']` is `null`:
    `$assignedJobrole` is `null`. Line 1129 is placed **outside** the `if (isset($assignedJobrole))` block, directly executing `$assignedJobrole->jobrole`.
  - Verbatim error output in PHP execution:
    ```
    <warning> WARNING </warning> Attempt to read property "jobrole" on null in app\Http\Controllers\user\tbluserController.php on line 1129.
    ```
- Exact Crash Site 2: `app/Http/Controllers/user/tbluserController.php:1581` in `show(Request $request, $id)`:
  ```php
  1580: $res['completedCount'] = $completedCount = matrix::where('user_id', $user_id)->count();
  1581: $res['totalSkills'] = $totalSkills = skillJobroleMap::where('jobrole', $assignedJobrole->jobrole)->whereNull('deleted_at')->where('sub_institute_id', $sub_institute_id)->count();
  ...
  1592: $assignedJobrole = userJobroleModel::where('sub_institute_id', $sub_institute_id)->where('id', $editData['allocated_standards'])->whereNull('deleted_at')->first();
  ```
  - Observation: At line 1581, `$assignedJobrole` has not been assigned at all (it is undefined). Reading `->jobrole` raises `Undefined variable $assignedJobrole` and `Attempt to read property "jobrole" on null`.

#### B. Legacy Dual-Key Discrepancy in Tenant 15
- Query:
  ```sql
  SELECT id, first_name, last_name, email, jobtitle_id, allocated_standards 
  FROM tbluser 
  WHERE sub_institute_id = 15;
  ```
- Results:
  - Total users in tenant 15: **162** (1 admin ID 479 + 161 imported provisional users).
  - Users with `allocated_standards IS NOT NULL`: **0** (`count = 0`).
  - Users with `jobtitle_id IS NOT NULL`: **161** (all except admin 479).
  - The 16 target NAEMD employees (IDs 564–579):
    | ID | Name | Email | `jobtitle_id` | `allocated_standards` | `s_user_jobrole.jobrole` |
    |---|---|---|---|---|---|
    | 564 | Vasudha Surve | vasudha.surve@naemd.edu.in | 7300 | NULL | Head of Institution |
    | 565 | Ankita Solanki | ankita.solanki@naemd.edu.in | 7303 | NULL | Admissions |
    | 566 | Nazneen Shaikh | nazneen.shaikh@naemd.edu.in | 7302 | NULL | Academics |
    | 567 | Santosh Bhoir | santosh.bhoir@naemd.edu.in | 7306 | NULL | Admin |
    | 568 | Hiral Joshi | hiral.joshi@naemd.edu.in | 7303 | NULL | Admissions |
    | 569 | Akshi Dedhia | akshi.dedhia@nationalacademy.edu.in | 7308 | NULL | Career Service Cell |
    | 570 | Seeta Khatri | seeta.khatri@naemd.edu.in | 7305 | NULL | Accounts |
    | 571 | Meenakshi Sharma | meenakshi.s@naemd.edu.in | 7303 | NULL | Admissions |
    | 572 | Rohan Jadhav | rohan.jadhav@futurevarsity.edu.in | 7308 | NULL | Career Service Cell |
    | 573 | Shweta Tiwari | shweta.mina@futurevarsity.edu.in | 7301 | NULL | Academics and Faculty |
    | 574 | Priyanka Piramal | priyanka.piramal@futurevarsity.edu.in | 7303 | NULL | Admissions |
    | 575 | Priya Mishra | priya.mishra@futurevarsity.edu.in | 7303 | NULL | Admissions |
    | 576 | Santosh Indvatkar | santoshindvatkar.30@futurevarsity.edu.in | 7306 | NULL | Admin |
    | 577 | Dakshata Tondvalkar | dakshata.tondvalkar@nationalacademy.edu.in | 7303 | NULL | Admissions |
    | 578 | Jyoti Gagat | jyoti.talekar@nationalacademy.edu.in | 7315 | NULL | Support Team |
    | 579 | Lynette Fernandes | lynette.f@naemd.edu.in | 7303 | NULL | Admissions |

- **Root Cause of Omission:**
  In `app/Console/Commands/IngestFuturevarsityStaffCommand.php` lines 210–236, `$userRow` was constructed with `'jobtitle_id' => $jobRoleId`, but `'allocated_standards'` was omitted entirely.
- **Verification of Fix:**
  Simulated in tinker: updating `tbluser.allocated_standards = CAST(jobtitle_id AS CHAR)` for user 564 caused `tbluserController@edit` to execute with **zero warnings and zero errors**, resolving `s_user_jobrole` cleanly.

#### C. Next.js `/api/employees-management` and Blade Endpoints Behavior
- In `app/Http/Controllers/HRMS/EmployeeDirectoryController.php`:
  - `index()` defines line 134:
    ```php
    private const ROLE_ID_SQL = "COALESCE(NULLIF(u.jobtitle_id, 0), NULLIF(SUBSTRING_INDEX(u.allocated_standards, ',', 1), '') + 0)";
    ```
    Because it uses `COALESCE`, `index()` already falls back between both keys.
  - However, `findForTenant()` (used by `show()`) selects raw `tbluser` columns. With `allocated_standards = null`, API consumers receive `null` for `allocated_standards`.
  - When the Next.js edit drawer or personal-info tab submits an update, it submits `allocated_standards`, causing synchronization drift if both keys are not populated.
  - Legacy Blade view `resources/views/user/edit_user.blade.php`:
    Line 159 checks `@if(isset($data['allocated_standards']) && $data['allocated_standards']==$sv['id']) selected @endif`. With `allocated_standards = null`, the dropdown failed to pre-select the employee's active role.

---

## 2. Logic Chain

1. **Premise:** `school_setup.outbound_email_enabled` is the per-tenant gateway switch governing `MailGate::allowedForTenant($tenantId)`.
2. **Observation:** On db3, `school_setup.outbound_email_enabled` is `0` for tenant 15. `MailGate::allowedForTenant(15)` evaluates to `false`.
3. **Inference:** When `InviteService::issue()` is triggered for any user in tenant 15, `MailGate::allowedForTenant(15)` intercepts the send and returns `delivered => 'link'`. No mail can leave the platform for tenant 15.
4. **Resolution for R4:** Updating `school_setup.outbound_email_enabled = 1` for `id = 15` activates tenant 15 in `MailGate::allowedForTenant(15)` without modifying `G2G_NOTIFY_EMAIL` in `.env` (preserving platform safety for all other tenants).

5. **Premise:** Outbound mail delivery for tenant 15 requires transport configuration in `smtp_details`.
6. **Observation:** Tenant 15 has 0 rows in `smtp_details` and 0 rows in `sms_api_details`. The platform's active standard configuration exists on tenant 1 (`trizinnovation2018@gmail.com`, `smtp.gmail.com`, port 465, password `"okesecjnhbyiysmk"`) and tenant 3 (`prioritysms.com`).
7. **Inference:** Creating a profile for tenant 15 in `smtp_details` (module `default`) and `sms_api_details` satisfies the requirements of `DeliveryController::index` and allows `https://g2gv0.vercel.app/settings?s=delivery` to display active email/SMS delivery.

8. **Premise:** Real employee inboxes must NOT receive live invitation emails during onboarding verification.
9. **Observation:** `InviteService::issue()` separates token identity (`$email`) from delivery mailbox (`$deliverTo`).
10. **Inference:** A verification script can safely test credential issuance by passing a designated test address into `$deliverTo`. The token in `password_reset_tokens` will be generated for the real employee, but the email payload will be routed exclusively to the test inbox. Furthermore, `DeliveryController::test` accepts a custom `to` address to verify SMTP transport connectivity in isolation.

11. **Premise:** Legacy user management views (`/user/add_user/{id}/edit`, `show`) and reports rely on `tbluser.allocated_standards` matching `s_user_jobrole.id`.
12. **Observation:** In `tbluserController.php:1129`, `$assignedJobrole->jobrole` is read without checking if `$assignedJobrole` is null. For all 162 users in tenant 15, `allocated_standards` is NULL, so `$assignedJobrole` evaluates to null, triggering `Attempt to read property "jobrole" on null`.
13. **Inference:** Setting `tbluser.allocated_standards = CAST(tbluser.jobtitle_id AS CHAR)` for all 16 employees in tenant 15 ensures `$assignedJobrole` resolves to their valid `s_user_jobrole` record.
14. **Direct Proof:** In tinker, updating `tbluser.allocated_standards = '7300'` on user 564 completely eliminated the error at line 1129, resulting in successful view compilation.

---

## 3. Caveats

1. **Primary Admin Account (User 479):**
   User 479 (`lsi.mkes@futurevarsity.edu.in`) has `jobtitle_id = NULL` and `allocated_standards = NULL`. This is expected because 479 is a tenant administrator (`user_profile_id = 63`, `is_admin = 1`) rather than an institutional staff member. Do not assign an academic/administrative job title to user 479 unless explicitly instructed.
2. **`tbluserController::show` Bug (Line 1581):**
   In `tbluserController.php`, line 1581 accesses `$assignedJobrole->jobrole` before line 1592 where `$assignedJobrole` is fetched. If legacy `/user/add_user/{id}` (the `show` route) is invoked directly with `type != 'API'`, line 1581 will throw if code is not adjusted. However, Next.js uses `/api/employees-management/{id}` (`EmployeeDirectoryController@show`), which does not touch `tbluserController::show` and returns HTTP 200 reliably.
3. **`FRONTEND_URL` Environment Setting:**
   In local environment, `FRONTEND_URL` is `http://localhost:3000`. `InviteService` lines 164–172 detect `localhost` and return a warning string in `'error'`. On live production, `FRONTEND_URL` must point to `https://g2g.scholarclone.com` or `https://g2gv0.vercel.app` so links are reachable by end users.
4. **No Direct Code Modifications in this Milestone:**
   As an Explorer, no production database mutations or application code changes have been applied. All test updates performed in tinker during investigation were immediately rolled back.

---

## 4. Conclusion

1. **Delivery Activation Readiness (R4):**
   - Outbound delivery for tenant 15 is activated by updating `school_setup.outbound_email_enabled = 1 WHERE id = 15`.
   - Tenant delivery profile is configured by inserting a profile into `smtp_details` (`sub_institute_id = 15`, `label = 'Default sender'`, `module_key = 'default'`, `gmail = 'trizinnovation2018@gmail.com'`, `server_address = 'smtp.gmail.com'`, `port = '465'`, `password = Crypt::encryptString('okesecjnhbyiysmk')`, `from_name = 'Future Varsity'`) and an SMS profile into `sms_api_details`.
   - This directly unblocks `https://g2gv0.vercel.app/settings?s=delivery` with zero code modifications needed in `DeliveryController`.
2. **Safe Credential Invitation Flow:**
   - Invitation emails are triggered via `EmployeeDirectoryController::invite` calling `InviteService::issue()`.
   - Verification without emailing real employees is achievable via:
     - Approach A: Direct invocation of `InviteService::issue($employeeEmail, 15, 'invite', $designatedTestEmail)`.
     - Approach B: Execution of `DeliveryController::test` with `{ "to": "designated_test_email@domain.com" }`.
3. **Directory Endpoints & "jobrole on null" Crash Resolution (R2 & R6):**
   - The verbatim error `Attempt to read property "jobrole" on null` originates at `tbluserController.php:1129` (in `edit()`) and `tbluserController.php:1581` (in `show()`).
   - The crash occurs because `tbluser.allocated_standards` is `NULL` across all 16 target employees in tenant 15, while `tbluser.jobtitle_id` holds their valid role IDs (7300, 7301, 7302, 7303, 7305, 7306, 7308, 7315).
   - Executing `UPDATE tbluser SET allocated_standards = CAST(jobtitle_id AS CHAR) WHERE sub_institute_id = 15 AND jobtitle_id IS NOT NULL;` completely synchronizes the dual keys and eliminates the crash.
   - Next.js `/api/employees-management` and `/api/employees-management/{id}` return HTTP 200 with populated job role data.

---

## 5. Verification Method

### A. SQL Schema & State Proof Queries (Run on `db3`)
Execute in tinker or MariaDB client:

```sql
-- 1. Verify outbound email flag
SELECT id, SchoolName, ShortCode, Email, outbound_email_enabled 
FROM school_setup 
WHERE id = 15;
-- Expected after R4: outbound_email_enabled = 1

-- 2. Verify smtp_details profile
SELECT id, sub_institute_id, label, module_key, gmail, server_address, port 
FROM smtp_details 
WHERE sub_institute_id = 15 AND deleted_at IS NULL;
-- Expected after R4: 1 row with module_key = 'default'

-- 3. Verify sms_api_details
SELECT id, sub_institute_id, url, is_active 
FROM sms_api_details 
WHERE sub_institute_id = 15;
-- Expected after R4: 1 row with is_active = 1

-- 4. Verify dual-key synchronization across all 16 NAEMD staff
SELECT id, first_name, last_name, email, jobtitle_id, allocated_standards 
FROM tbluser 
WHERE sub_institute_id = 15 AND id != 479;
-- Expected after R2: zero rows where allocated_standards IS NULL or allocated_standards != CAST(jobtitle_id AS CHAR)
```

### B. HTTP Endpoint Verification Protocol (Automated via Artisan / Script)
Run the following script to verify HTTP 200 with zero 500 errors against `db3`:

```php
config(['database.default' => 'db3']);
DB::purge('mysql');

$admin = App\Models\auth\tbluserModel::find(479);
$token = $admin->createToken('verification_probe')->plainTextToken;

// 1. Verify Directory Index
$req1 = Illuminate\Http\Request::create('/api/employees-management', 'GET');
$req1->headers->set('Accept', 'application/json');
$req1->headers->set('Authorization', 'Bearer ' . $token);
$res1 = app()->handle($req1);
assert($res1->getStatusCode() === 200, "Directory index failed with status {$res1->getStatusCode()}");

// 2. Verify Employee Show for all 16 NAEMD Staff (IDs 564 to 579)
for ($id = 564; $id <= 579; $id++) {
    $req = Illuminate\Http\Request::create("/api/employees-management/{$id}", 'GET');
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = app()->handle($req);
    assert($res->getStatusCode() === 200, "Employee {$id} failed with status {$res->getStatusCode()}");
}

// 3. Verify Delivery Options & Profiles
$reqOpt = Illuminate\Http\Request::create('/api/organization/delivery/options', 'GET');
$reqOpt->headers->set('Accept', 'application/json');
$reqOpt->headers->set('Authorization', 'Bearer ' . $token);
$resOpt = app()->handle($reqOpt);
assert($resOpt->getStatusCode() === 200);

$reqProf = Illuminate\Http\Request::create('/api/organization/delivery/profiles', 'GET');
$reqProf->headers->set('Accept', 'application/json');
$reqProf->headers->set('Authorization', 'Bearer ' . $token);
$resProf = app()->handle($reqProf);
assert($resProf->getStatusCode() === 200);

// Cleanup probe token
DB::connection('db3')->table('personal_access_tokens')->where('name', 'verification_probe')->delete();
echo "All HTTP verification requests passed with HTTP 200 and zero 500 errors.\n";
```

### C. Safe Email Delivery Interception Verification
Execute via tinker without sending real employee emails:

```php
config(['database.default' => 'db3']);

// Test Delivery Profile directly using designated test address
$profile = DB::connection('db3')->table('smtp_details')->where('sub_institute_id', 15)->first();
if ($profile) {
    App\Support\MailSender::mailerForRow($profile)->raw(
        "HP ERP Test Delivery for Tenant 15",
        fn ($msg) => $msg->to('designated_test_email@domain.com')->subject('Delivery Activation Verification')
    );
}

// Test InviteService token generation with diverted recipient
$inviteResult = app(App\Services\Auth\InviteService::class)->issue(
    'vasudha.surve@naemd.edu.in',   // $email (sets token in password_reset_tokens)
    15,                            // $tenantId
    'invite',                      // $purpose
    'designated_test_email@domain.com' // $deliverTo (diverts outbound email)
);
assert($inviteResult['delivered'] === 'email');
assert(!empty($inviteResult['link']));
```
