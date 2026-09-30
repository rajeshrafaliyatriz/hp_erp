#!/usr/bin/env bash
# EMPLOYEE DOCUMENTS: ONE WAY IN, ONE WAY OUT.
#
#   bash Docs/hrit-audit/_evidence/probe-employee-documents.sh
#
# WHAT WENT WRONG
#
# `staff_document` had THREE writers that disagreed and TWO readers that
# disagreed.
#
#   EmployeeDocumentController::store   public/hp_staff_document/  PRIVATE
#   tbluserController::addUserDocument  public/hp_staff_document/  PUBLIC
#   PayrollController                   public/staff_document/     public
#
# Two of those file the SAME FOLDER with OPPOSITE visibility, so whether a
# download worked depended on which screen had filed the document. The Employee
# Directory then built its own link in the browser -
#
#   https://s3-triz.fra1.digitaloceanspaces.com/public/hp_staff_document/{file}
#
# - against an object the newer path deliberately wrote private. Result:
# AccessDenied on exactly the documents employees had just been asked to upload.
#
# And the two readers disagreed about the same employee: My Profile LEFT joins
# the type table and filters soft deletes, the HR drawer INNER joins and does
# not. Eight live rows point at document_type_id 56, which has no type row, so
# payslips were visible to the employee and invisible to HR - and a document the
# employee deleted still showed on the HR screen.
#
# WHAT THIS ASSERTS
#
# That one upload is visible to BOTH the employee and HR, that the object is NOT
# publicly readable, and that the refusals hold. The refusals are the half that
# matters: a probe that only ever sees a 200 proves the feature works for the
# person who already had access.
#
# SELF-SEEDING AND SELF-CLEANING. Everything is marked ZZPROBE in the title, so
# teardown is by marker and cannot be malformed. Tenant 6 is the scratch tenant.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

E6=$(tok 6 employee)        # user 63, tenant 6 - the subject
A6=$(tok 6 administrator)   # user 28, tenant 6 - HR
E3=$(tok 3 employee)        # user 7,  tenant 3 - an outsider, must be refused
A3=$(tok 3 administrator)   # tenant 3 HR - has the ROLE but not the tenant

SUBJECT=63
MARKER="ZZPROBE document"
FIXTURE="/tmp/zzprobe-employee-document.txt"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]);
  foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; }
  echo is_scalar($d)?$d:json_encode($d);' "$1"; }
count_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"] ?? []);'; }

teardown() {
  php -r '
    require "vendor/autoload.php"; $a=require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
    foreach (DB::table("staff_document")->where("document_title","like","ZZPROBE%")->get(["file_path"]) as $r) {
      try { if ($r->file_path && Storage::disk("digitalocean")->exists($r->file_path)) Storage::disk("digitalocean")->delete($r->file_path); }
      catch (\Throwable $e) {}
    }
    DB::table("staff_document")->where("document_title","like","ZZPROBE%")->delete();
  ' >/dev/null 2>&1
  rm -f "$FIXTURE"
}

echo
echo "============ Employee documents ============"
trap teardown EXIT
teardown   # start-of-run clear (F-163)

printf 'ZZPROBE employee document fixture\n' > "$FIXTURE"

# ---------------------------------------------------------------- 1. upload
echo
echo "1. An employee files their own"
R=$(curl -s -m 90 -X POST "$BASE/api/account/documents" -H "Authorization: Bearer $E6" -H 'Accept: application/json' \
      -F "document=@$FIXTURE" -F "document_title=$MARKER self" -F "document_type_id=1")
ID=$(echo "$R" | jq_ data.id)
check "the upload is accepted" "1" "$(echo "$R" | jq_ status)"
check "  and returns the row it created" "yes" "$([ -n "$ID" ] && echo yes || echo no)"

# ------------------------------------------------ 2. BOTH readers see it
echo
echo "2. The employee and HR see the same document"
# This is the whole point. Before the fix the HR drawer read a different query -
# INNER join, no deleted_at filter - so the two screens could disagree about the
# same employee's documents, and did.
check "it is in the employee's own list" "1" \
  "$(curl -s -m 30 "$BASE/api/account/documents" -H "Authorization: Bearer $E6" -H 'Accept: application/json' | count_)"
check "  and in HR's list for that employee" "1" \
  "$(curl -s -m 30 "$BASE/api/employees-management/$SUBJECT/documents" -H "Authorization: Bearer $A6" -H 'Accept: application/json' | count_)"

# ------------------------------------------------ 3. the object is private
echo
echo "3. The file is NOT readable without signing in"
PATH_IN_BUCKET=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select file_path from staff_document where id=$ID" | jq_ file_path)
check "the row records where the object is" "yes" \
  "$([ -n "$PATH_IN_BUCKET" ] && echo yes || echo no)"
# The reproduction of the reported bug: this exact URL shape is what the Employee
# Directory used to put in an <a href>. It must refuse.
RAW=$(curl -s -o /dev/null -m 30 -w '%{http_code}' "https://s3-triz.fra1.digitaloceanspaces.com/$PATH_IN_BUCKET")
check "  and the raw bucket URL refuses it" "403" "$RAW"

# ------------------------------------------------ 4. download authorisation
echo
echo "4. Who may fetch it"
dl() { curl -s -o /dev/null -m 60 -w '%{http_code}' "$BASE/api/account/documents/$ID/download" ${1:+-H "Authorization: Bearer $1"}; }
check "the owner may" "200" "$(dl "$E6")"
check "  HR in the same organisation may" "200" "$(dl "$A6")"
# The half that matters. Without this the three checks above would pass on a
# route that served the file to anybody who asked.
check "  an employee of another organisation may NOT" "404" "$(dl "$E3")"
check "  nor HR of another organisation" "404" "$(dl "$A3")"
check "  and an unauthenticated caller may not" "401" "$(dl "")"

# ------------------------------------------------ 5. HR files for somebody
echo
echo "5. HR files a document for an employee"
R=$(curl -s -m 90 -X POST "$BASE/api/employees-management/$SUBJECT/documents" -H "Authorization: Bearer $A6" -H 'Accept: application/json' \
      -F "document=@$FIXTURE" -F "document_title=$MARKER hr" -F "document_type_id=2")
HRID=$(echo "$R" | jq_ data.id)
check "the upload is accepted" "1" "$(echo "$R" | jq_ status)"
# Filed AGAINST the employee, but recorded as filed BY the HR user - so the row
# still says who put it there.
check "  it belongs to the employee" "$SUBJECT" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php "select user_id from staff_document where id=$HRID" | jq_ user_id)"
check "  and records the HR user as the filer" "28" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php "select created_by from staff_document where id=$HRID" | jq_ created_by)"
# The legacy writer recorded none of this, which is why downloads had to guess a
# folder.
check "  with the metadata the legacy path never wrote" "yes" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php "select file_path, mime_type, file_size from staff_document where id=$HRID" \
     | php -r '$d=json_decode(stream_get_contents(STDIN),true);
        echo (!empty($d["file_path"]) && !empty($d["mime_type"]) && !empty($d["file_size"])) ? "yes" : "no";')"

echo
echo "  and an ordinary employee cannot file against somebody else"
check "filing for another employee is refused" "403" \
  "$(curl -s -o /dev/null -m 60 -w '%{http_code}' -X POST "$BASE/api/employees-management/$SUBJECT/documents" \
     -H "Authorization: Bearer $E6" -H 'Accept: application/json' \
     -F "document=@$FIXTURE" -F "document_title=nope" -F "document_type_id=1")"

# ------------------------------------------------ 6. removal
echo
echo "6. Removal, and that both lists agree about it"
check "an ordinary employee cannot remove it" "403" \
  "$(curl -s -o /dev/null -m 30 -w '%{http_code}' -X DELETE "$BASE/api/employees-management/$SUBJECT/documents/$HRID" \
     -H "Authorization: Bearer $E3" -H 'Accept: application/json')"
# THE ONE THAT ACTUALLY TESTS THE CONTROLLER.
#
# The check above is satisfied by the ROUTE gate (profile:admin,hr) on its
# own - it still passed with the controller's tenant check deleted, which
# makes it vacuous for that purpose. The tenant check exists for a caller who
# IS HR, just not here: an HR manager is HR for one organisation, not for all
# twelve. Asserted with a real administrator token from another tenant.
check "  HR in ANOTHER organisation cannot remove it" "404" \
  "$(curl -s -o /dev/null -m 30 -w '%{http_code}' -X DELETE "$BASE/api/employees-management/$SUBJECT/documents/$HRID" \
     -H "Authorization: Bearer $A3" -H 'Accept: application/json')"
check "  HR can" "1" \
  "$(curl -s -m 30 -X DELETE "$BASE/api/employees-management/$SUBJECT/documents/$HRID" \
     -H "Authorization: Bearer $A6" -H 'Accept: application/json' | jq_ status)"
# The old HR query had no deleted_at filter, so a document the employee removed
# stayed on the HR screen for ever.
check "  and it is gone from HR's list" "1" \
  "$(curl -s -m 30 "$BASE/api/employees-management/$SUBJECT/documents" -H "Authorization: Bearer $A6" -H 'Accept: application/json' | count_)"
check "  and from the employee's" "1" \
  "$(curl -s -m 30 "$BASE/api/account/documents" -H "Authorization: Bearer $E6" -H 'Accept: application/json' | count_)"

echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left" "0" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
     "select count(*) c from staff_document where document_title like 'ZZPROBE%'" | jq_ c)"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
