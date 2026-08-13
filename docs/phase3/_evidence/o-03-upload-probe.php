<?php
/**
 * O-03's FIFTH ROUTE — `POST /api/excel-agent/upload`, the one that was HELD.
 *
 * WHY IT WAS HELD. The first probe sent no file, so validation refused at the
 * validator (line 35) and never reached the tenant guard (line 67):
 *
 *     upload  3->6  HTTP 422  The file field is required.
 *
 * A 4xx BEFORE THE CODE UNDER TEST IS NOT A RESULT. Four of five routes were
 * decided; this one was recorded as HELD rather than counted as refused, because
 * "the request was rejected" and "the guard rejected it" are different claims.
 *
 * WHAT THIS ADDS. A real .xlsx - a ZIP carrying the three parts that make PHP's
 * finfo report the spreadsheet mime, so `mimes:xlsx` passes and the request
 * reaches line 67. Built here rather than committed as a binary: a fixture whose
 * construction is visible can be checked; a checked-in blob cannot.
 *
 * `validate_only=1` keeps the dry-run path, so nothing reaches Google Sheets.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// ── the fixture ─────────────────────────────────────────────────────────────
$path = sys_get_temp_dir() . '/o03-probe.xlsx';
@unlink($path);
$z = new ZipArchive();
$z->open($path, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml',
    '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
  . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
  . '<Default Extension="xml" ContentType="application/xml"/>'
  . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
$z->addFromString('_rels/.rels',
    '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
  . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$z->addFromString('xl/workbook.xml',
    '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets>'
  . '<sheet name="S" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets></workbook>');
$z->close();

printf("fixture: %d bytes, mime %s\n\n", filesize($path),
    (new finfo(FILEINFO_MIME_TYPE))->file($path));

// ── callers ─────────────────────────────────────────────────────────────────
$tok = [];
foreach ([3, 6] as $t) {
    $u = DB::table('tbluser')->where('sub_institute_id', $t)->value('id');
    $tok[$t] = tbluserModel::find($u)->createToken('o03up')->plainTextToken;
}

function upload($kernel, string $path, string $token, ?int $tenant): array
{
    $file = new UploadedFile($path, 'probe.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    $params = ['token' => $token, 'type' => 'API', 'validate_only' => 1];
    if ($tenant !== null) $params['sub_institute_id'] = $tenant;

    $req = Illuminate\Http\Request::create('/api/excel-agent/upload', 'POST', $params, [], ['file' => $file]);
    $req->headers->set('Accept', 'application/json');
    $res = $kernel->handle($req);
    $b = json_decode((string) $res->getContent(), true);
    return [$res->getStatusCode(), trim(substr((string) ($b['message'] ?? ''), 0, 52))];
}

// ── (1) REACH: did the request get past the validator this time? ────────────
echo "REACH - own tenant, no tenant requested. A 422 here means the fixture\n";
echo "        still does not satisfy the validator and nothing below counts.\n";
[$c, $m] = upload($kernel, $path, $tok[3], null);
$reached = $c !== 422 || !str_contains($m, 'file field');
printf("  caller 3, no tenant   -> HTTP %-4d %-52s %s\n", $c, $m,
    $reached ? 'PAST THE VALIDATOR' : '*** STILL BLOCKED AT VALIDATION ***');

if (!$reached) {
    echo "\nHELD STANDS - the probe still cannot reach the guard.\n";
    DB::table('personal_access_tokens')->where('name', 'o03up')->delete();
    @unlink($path);
    exit(1);
}

// ── (2) DISCRIMINATE: own tenant vs foreign tenant ─────────────────────────
echo "\nTHE GUARD - a foreign tenant must be refused where an own tenant is not.\n";
[$cOwn, $mOwn]     = upload($kernel, $path, $tok[3], 3);
[$cForeign, $mFor] = upload($kernel, $path, $tok[3], 6);
[$cRev, $mRev]     = upload($kernel, $path, $tok[6], 3);

printf("  caller 3 asks 3       -> HTTP %-4d %s\n", $cOwn, $mOwn);
printf("  caller 3 asks 6       -> HTTP %-4d %s\n", $cForeign, $mFor);
printf("  caller 6 asks 3       -> HTTP %-4d %s\n", $cRev, $mRev);

$refuses = $cForeign === 403 && $cRev === 403;
$allows  = $cOwn !== 403;

printf("\n  (1) REACH        : yes - the validator is satisfied\n");
printf("  (2) DISCRIMINATE : %s\n", ($refuses && $allows)
    ? 'yes - foreign refused 403, own tenant not refused'
    : 'NO - both directions give the same answer, so nothing is proven');
printf("  VERDICT          : %s\n", ($refuses && $allows)
    ? 'HELD -> DECIDED. upload REFUSES a foreign tenant. O-03 is 5 of 5.'
    : 'HELD STANDS');

DB::table('personal_access_tokens')->where('name', 'o03up')->delete();
@unlink($path);
echo "\ncleaned up: probe tokens deleted, fixture removed\n";
