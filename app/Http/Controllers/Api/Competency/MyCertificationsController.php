<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use App\Support\Competency\CertificationCompliance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * THE EMPLOYEE'S OWN CERTIFICATIONS - read-only, and nobody else's.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * The Certification & Compliance Center has a "My Certifications" TAB. A tab
 * cannot express ownership. Its filter is `user_id_filter: user.id` sent from
 * the browser, over an endpoint that accepts any id it is handed - so the tab
 * is a convenience, not a boundary, and the screen it sits on is an admin
 * surface that can create, edit, verify, revoke and delete anyone's records.
 *
 * That leaves an administrator with no good option. Grant the module to an
 * employee so they can see their own certificate, and they can also edit a
 * colleague's. Withhold it, and they cannot see their own at all. Both answers
 * are wrong, which is the sign the capability was in the wrong place.
 *
 * ── THE SECURITY DESIGN IS THE SIGNATURE, NOT A CHECK ───────────────────────
 *
 * This endpoint TAKES NO SUBJECT PARAMETER. Same principle as
 * MyCapabilityController, quoted from its docblock because it is exactly right:
 *
 *     AN ENDPOINT THAT ACCEPTS NO user_id CANNOT BE MADE TO RETURN SOMEBODY
 *     ELSE'S DATA. There is no parameter to tamper with, no id to guess, and no
 *     authorisation rule that can be got wrong later, because there is no
 *     decision being made.
 *
 * A `user_id` in the request is IGNORED, not refused. A stale value in a
 * caller's localStorage is common and must not lock a legitimate employee out;
 * ignoring it is equally safe because it never reaches a query.
 *
 * ── WHY DOCUMENTS ARE EMBEDDED RATHER THAN FETCHED PER ROW ──────────────────
 *
 * The obvious build was to reuse GET /certifications/{id}/documents for the
 * download links. That endpoint is tenant-scoped only: it checks the
 * certification belongs to the caller's institute, not to the caller. Pointing
 * an employee screen at it would hand every employee a working read of any
 * colleague's evidence, one id at a time - reintroducing on the employee
 * surface the exact hole this controller exists to avoid.
 *
 * So the documents come back inside this response, for certification ids that
 * were themselves derived from the caller's own rows. The employee page needs
 * no other endpoint, and there is no id for it to pass.
 *
 * ── WHY IT DOES NOT COMPUTE COMPLIANCE ITSELF ───────────────────────────────
 *
 * CertificationCompliance does that, and CertificationController now calls the
 * same class. If this controller re-implemented the ladder, HR and the employee
 * could disagree about whether the same credential is valid - and nobody would
 * catch it, because the two screens are never open side by side.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route     routes/api.php, api.token guarded - any authenticated employee
 *   identity  competencyContext() -> resolveApiIdentity(), token only
 *   subject   THE CALLER. Never a request field.
 *   read-only this controller writes nothing. HR remains the issuer of record.
 */
class MyCertificationsController extends Controller
{
    use ResolvesCompetencyContext;

    private const TABLE = 's_competency_certifications';
    private const EVIDENCE = 's_competency_evidence';

    /**
     * An employee holds a handful of credentials, not hundreds, so this is
     * unpaginated on purpose - a pager over four rows is noise. The cap is a
     * backstop against a data-entry accident, and when it bites the response
     * says so rather than quietly showing a prefix.
     */
    private const MAX_ROWS = 200;

    /** GET /competency/my-certifications */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        // THE SUBJECT IS THE CALLER, from the resolved identity. No request
        // field is consulted here and there must never be one.
        $me = (int) $context['user_id'];

        if ($me <= 0) {
            return response()->json(['status' => 0, 'message' => 'Unable to identify your record.'], 401);
        }

        $rows = DB::table(self::TABLE)
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->whereNull('deleted_at')
            /*
             * Soonest expiry first, and rows that never expire last rather than
             * first. An employee opens this page to answer "what do I need to
             * renew", so the thing needing action has to be at the top; MySQL
             * sorts NULL first by default, which would bury it.
             */
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC, id DESC')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        $truncated = $rows->count() > self::MAX_ROWS;
        if ($truncated) {
            $rows = $rows->take(self::MAX_ROWS);
        }

        $documents = $this->documentsFor($rows->pluck('id')->all(), $sid);

        $items = $rows->map(function ($r) use ($documents) {
            $state = CertificationCompliance::state($r);

            return [
                'id'                  => (int) $r->id,
                'name'                => $r->name,
                'issuing_body'        => $r->issuing_body,
                'certification_type'  => $r->certification_type,
                'credential_id'       => $r->credential_id,
                'competency_id'       => $r->competency_id ? (int) $r->competency_id : null,
                'status'              => $r->status,
                'status_label'        => CertificationCompliance::statusLabel($r->status),
                'compliance'          => $state['label'],
                'compliance_key'      => $state['key'],
                'compliance_reason'   => $state['reason'],
                /*
                 * Whether HR has verified it. The employee should see this -
                 * "submitted but not yet verified" is the state they most often
                 * need to chase - but they cannot change it from here.
                 */
                'verification_status' => $r->verification_status,
                'issued_date'         => $r->issued_date,
                'expiry_date'         => $r->expiry_date,
                'issued_date_label'   => CertificationCompliance::humanDate($r->issued_date),
                'expiry_date_label'   => CertificationCompliance::humanDate($r->expiry_date),
                'days_to_expiry'      => CertificationCompliance::daysToExpiry($r->expiry_date),
                'notes'               => $r->notes,
                'documents'           => $documents[(int) $r->id] ?? [],
            ];
        })->values()->all();

        return response()->json([
            'status'  => 1,
            'message' => 'Certifications fetched successfully',
            'data'    => $items,
            // Counted off the rows returned, not queried separately, so the
            // tiles can never disagree with the list under them.
            'summary' => $this->summarise($items),
            'meta'    => [
                'total'     => count($items),
                'truncated' => $truncated,
            ],
        ]);
    }

    /**
     * Attached files, grouped by certification id.
     *
     * @param  array<int, mixed>  $certificationIds  Already known to be the caller's own.
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function documentsFor(array $certificationIds, int $sid): array
    {
        if ($certificationIds === []) {
            return [];
        }

        $rows = DB::table(self::EVIDENCE)
            ->where('sub_institute_id', $sid)
            ->whereIn('certification_id', $certificationIds)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get();

        $grouped = [];

        foreach ($rows as $r) {
            $url = $r->file_path ? $this->fileUrl($r->file_path) : null;

            $grouped[(int) $r->certification_id][] = [
                'id'            => (int) $r->id,
                'title'         => $r->title,
                'evidence_type' => $r->evidence_type,
                'file_name'     => $r->file_name,
                /*
                 * `url` is what the download button uses: an uploaded file when
                 * there is one, otherwise the external link the record carries.
                 * Null means the row exists but points nowhere - the button has
                 * to be disabled rather than linking to "null".
                 */
                'url'           => $url ?: $r->link,
                'is_file'       => $url !== null,
                'uploaded_on'   => CertificationCompliance::humanDate($r->created_at),
            ];
        }

        return $grouped;
    }

    /**
     * The tiles above the list.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summarise(array $items): array
    {
        $summary = ['total' => count($items), 'compliant' => 0, 'expiring' => 0, 'non_compliant' => 0];

        foreach ($items as $item) {
            $key = $item['compliance_key'];
            if (isset($summary[$key])) {
                $summary[$key]++;
            }
        }

        return $summary;
    }

    private function fileUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        try {
            return Storage::disk('digitalocean')->url($path);
        } catch (\Throwable $e) {
            // A misconfigured disk must not take the whole page down; the row
            // simply comes back with no download target.
            return null;
        }
    }
}
