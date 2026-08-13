"""Substitute the three IDENTITY reads of user_id in PayrollController.

Classified by hand first (R6):
  167, 238 -> $user_id feeds created_by / updated_by. IDENTITY. Forgeable audit
              provenance: a caller could attribute a write to another user.
  2092     -> $profileUserId is passed to employeeDetails(...) alongside the
              acting user's profile name, and scopes which employees are visible.
              IDENTITY.
  2550     -> $empId = $explodeIds[1] ?? ($request->user_id ?? 0) - the employee
              whose payslip is being generated. SUBJECT. Left alone.
"""
import io, re

P = r"C:\Users\MILAN\Downloads\hp_erp\app\Http\Controllers\Payroll\PayrollController.php"
src = io.open(P, encoding="utf-8", newline="").read()

before = src

# 1+2. created_by / updated_by provenance
src = src.replace(
    "$user_id = $request->get('user_id');",
    "$user_id = $this->payrollActorId($request);   // identity, not a parameter")

# 3. acting user for employee visibility scoping
src = src.replace(
    "$profileUserId = $request->user_id;",
    "$profileUserId = $this->payrollActorId($request);")

io.open(P, "w", encoding="utf-8", newline="").write(src)

n_actor = len(re.findall(r"payrollActorId\(\$request\)", src)) - 1   # minus the definition
left = re.findall(r"\$request->get\('user_id'\)|\$request->user_id\b", src)
print("substituted identity reads :", n_actor)
print("remaining raw user_id reads:", len(left), "->", left)
