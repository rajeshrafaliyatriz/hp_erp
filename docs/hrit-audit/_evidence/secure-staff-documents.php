<?php
/**
 * MAKE EVERY FILED PERSONNEL DOCUMENT PRIVATE.
 *
 *   php Docs/hrit-audit/_evidence/secure-staff-documents.php            # report only
 *   php Docs/hrit-audit/_evidence/secure-staff-documents.php --apply    # change them
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * Three writers have filed into `staff_document`, and they disagreed about
 * whether the object should be readable without signing in:
 *
 *   EmployeeDocumentController::store   private   (self-service, since 2026-09)
 *   tbluserController::addUserDocument  PUBLIC    (the HR directory)
 *   PayrollController                   public    (payslips)
 *
 * So a résumé, an ID proof or a payslip filed through the older paths can be
 * read by anyone who has - or guesses - the URL, with no login at all. The keys
 * are not secret: `{userId}_{random}.pdf` and `emp_{id}_payslip_{Month}_{year}.pdf`,
 * the second of which is entirely predictable from a name and a month.
 *
 * Downloads keep working after this runs, because they no longer go to the
 * bucket: `EmployeeDocumentController::download` streams the file after checking
 * the caller owns it or is HR in the same tenant.
 *
 * ── WHAT IT WILL BREAK, SAID PLAINLY ────────────────────────────────────────
 *
 * Any bucket link already pasted into an email or a chat stops working. That is
 * the point - those links are exactly the exposure - but somebody may be relying
 * on one, so the before-image is written to
 * `Docs/hrit-audit/_reversals/` and the reversal is a one-line loop over it.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────────
 *
 * Reports by default; changes nothing without --apply. It only ever moves an
 * object from public to private, never the other way, and it touches only keys
 * that a `staff_document` row actually points at - not everything in the folder,
 * so an unrelated file that happens to live there is left alone.
 */

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$apply = in_array('--apply', $argv, true);
$disk = Storage::disk('digitalocean');

/** Every folder these files have ever been written to. */
const FOLDERS = ['public/hp_staff_document/', 'public/staff_document/'];

printf("\n=== Personnel documents: visibility ===\n");
printf("mode: %s\n\n", $apply ? 'APPLY - objects will be changed' : 'report only (pass --apply to change)');

$rows = DB::table('staff_document')
    ->whereNull('deleted_at')
    ->orderBy('id')
    ->get(['id', 'user_id', 'sub_institute_id', 'document_title', 'file_name', 'file_path']);

$public = [];
$private = 0;
$missing = [];
$errors = [];

foreach ($rows as $row) {
    // Where the object actually is. `file_path` only exists on rows filed since
    // 2026-09-24, so for everything older the folder has to be found.
    $path = null;

    try {
        if (!empty($row->file_path) && $disk->exists($row->file_path)) {
            $path = $row->file_path;
        } elseif (!empty($row->file_name)) {
            foreach (FOLDERS as $folder) {
                if ($disk->exists($folder . $row->file_name)) {
                    $path = $folder . $row->file_name;
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['id' => $row->id, 'why' => substr($e->getMessage(), 0, 90)];
        continue;
    }

    if ($path === null) {
        // A row whose file is not in the bucket. Reported, never "repaired":
        // inventing a file would be worse than saying it is gone.
        $missing[] = ['id' => $row->id, 'user_id' => $row->user_id, 'file_name' => $row->file_name];
        continue;
    }

    try {
        $visibility = $disk->getVisibility($path);
    } catch (\Throwable $e) {
        $errors[] = ['id' => $row->id, 'path' => $path, 'why' => substr($e->getMessage(), 0, 90)];
        continue;
    }

    if ($visibility !== 'public') {
        $private++;
        continue;
    }

    $public[] = [
        'id' => $row->id,
        'user_id' => $row->user_id,
        'sub_institute_id' => $row->sub_institute_id,
        'title' => $row->document_title,
        'path' => $path,
    ];
}

printf("rows examined            : %d\n", count($rows));
printf("already private         : %d\n", $private);
printf("PUBLICLY READABLE       : %d%s\n", count($public), $public ? '   <-- readable by anyone with the link' : '');
printf("file not in the bucket  : %d\n", count($missing));
printf("could not be checked    : %d\n", count($errors));

if ($public) {
    printf("\n  the exposed ones:\n");
    foreach (array_slice($public, 0, 30) as $p) {
        printf("    doc=%-5s user=%-5s tenant=%-4s %-28s %s\n",
            $p['id'], $p['user_id'], $p['sub_institute_id'],
            substr((string) $p['title'], 0, 28), $p['path']);
    }
    if (count($public) > 30) {
        printf("    ... and %d more\n", count($public) - 30);
    }
}

if ($missing) {
    printf("\n  rows whose file is gone - these cannot be downloaded whatever we do:\n");
    foreach (array_slice($missing, 0, 20) as $m) {
        printf("    doc=%-5s user=%-5s %s\n", $m['id'], $m['user_id'], $m['file_name']);
    }
    if (count($missing) > 20) {
        printf("    ... and %d more\n", count($missing) - 20);
    }
}

foreach ($errors as $e) {
    printf("    ERROR doc=%s %s\n", $e['id'], $e['why']);
}

if (!$apply) {
    printf("\nNothing was changed. Re-run with --apply to make the public ones private.\n\n");
    exit(0);
}

if (!$public) {
    printf("\nNothing to change.\n\n");
    exit(0);
}

// The before-image FIRST, so a failure halfway through still leaves a record of
// what was public when we started.
$stamp = date('Y-m-d');
$reversal = __DIR__ . "/../_reversals/REVERSAL-{$stamp}-staff-document-visibility.json";
@mkdir(dirname($reversal), 0777, true);
file_put_contents($reversal, json_encode([
    'taken_at' => date('c'),
    'note' => 'Objects that were PUBLIC before secure-staff-documents.php ran. '
        . 'To undo: foreach path -> Storage::disk("digitalocean")->setVisibility($path, "public").',
    'objects' => $public,
], JSON_PRETTY_PRINT));
printf("\nbefore-image written: %s\n", realpath($reversal) ?: $reversal);

$changed = 0;
$failed = 0;

foreach ($public as $p) {
    try {
        $disk->setVisibility($p['path'], 'private');
        $changed++;
    } catch (\Throwable $e) {
        $failed++;
        printf("  FAILED %s - %s\n", $p['path'], substr($e->getMessage(), 0, 90));
    }
}

printf("made private: %d\n", $changed);
printf("failed      : %d\n", $failed);

// Confirm rather than assume. setVisibility can succeed against a bucket policy
// that overrides it, and a silent success would be the worst outcome here.
$stillPublic = 0;
foreach ($public as $p) {
    try {
        if ($disk->getVisibility($p['path']) === 'public') {
            $stillPublic++;
        }
    } catch (\Throwable $e) {
        // counted as unverified rather than as secured
        $stillPublic++;
    }
}

printf("still readable after the change: %d%s\n\n",
    $stillPublic,
    $stillPublic ? '   <-- NOT SECURED, investigate the bucket policy' : '   (verified)');

exit($stillPublic === 0 ? 0 : 1);
