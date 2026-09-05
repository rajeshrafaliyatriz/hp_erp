<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\TalentOffer;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\talent_jobposting;
use App\Models\settings\organizationDetails;
use App\Models\auth\tbluserModel;
use App\Mail\OfferLetterMail;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

class TalentOfferController extends Controller
{
    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    public function __construct(
        private \App\Services\HRMS\EmployeeFactory $employees,
        private \App\Services\Talent\OfferAcceptanceService $acceptance,
        private \App\Services\Talent\OfferLinkService $links,
    ) {
    }

    /**
     * A candidate's answer to an offer.
     *
     * VARCHAR + const, never ENUM - talent_offers.status is a real MySQL ENUM and
     * that is exactly why acceptance could not be recorded on it.
     */
    public const DECISIONS = ['pending', 'accepted', 'declined'];

    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $type = $request->input('type');

        // Allow execution only if request type is API
        if ($type !== "API") {
            return response()->json(['message' => 'Invalid request type'], 400);
        }

        // Check and validate token
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $sub_institute_id = $this->apiTenantId($request);

        // Fetch organization details
        $org = organizationDetails::where('sub_institute_id', $sub_institute_id)->first();

        // Fetch job posting to get created_by
        $job = talent_jobposting::find($request->job_id);
        $signerUser = null;
        if ($job && $job->created_by) {
            $signerUser = tbluserModel::find($job->created_by);
        }

        // Validation rules
        $validator = Validator::make($request->all(), [
            'application_id' => 'required|exists:talent_job_applications,id',
            'job_id' => 'required|exists:talent_job_postings,id',
            'position' => 'required|string|max:255',
            'salary' => 'nullable|string|max:100',
            'start_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'sub_institute_id' => 'required|integer',
            'user_id' => 'required|integer',
            /*
             * AN ID, NOT A STRING - and it must exist.
             *
             * The column is bigint(20) unsigned, but this rule was
             * `nullable|string` while the field was a free-text box asking HR to
             * type a raw user id. A recruiter typing a NAME instead would error
             * on the app host (STRICT_TRANS_TABLES) and, on LIVE - which is not
             * strict - be silently coerced to 0: an offer letter reporting to
             * employee zero. That is the same failure as F-73 and F-78, a
             * plausible value from the wrong space written into an id column.
             *
             * The picker makes it unreachable from the UI; this makes it
             * unreachable from the API, which is where it has to be closed.
             */
            'reportmanager' => 'nullable|integer|exists:tbluser,id',
            'punchintime' => 'nullable|date_format:H:i',
            'punchouttime' => 'nullable|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            // Create new talent offer entry
            $offer = new TalentOffer([
                'application_id' => $request->application_id,
                'job_id' => $request->job_id,
                'position' => $request->position,
                'salary' => $request->salary,
                'start_date' => $request->start_date,
                'notes' => $request->notes,
                'sub_institute_id' => $sub_institute_id,
                'created_by' => $this->g2gActorId($request),
                'reportmanager' => $request->reportmanager,
                'punchintime' => $request->punchintime,
                'punchouttime' => $request->punchouttime,
                'status' => 'draft', // default status
            ]);

            if ($offer->save()) {
                // Tracked across the PDF/mail block below, which is conditional.
                $mailed = false;

                // Set updated_at to null for new records
                DB::table('talent_offers')->where('id', $offer->id)->update(['updated_at' => null]);

                // Delete old offer letters from DigitalOcean for the same application
                $existingOffers = TalentOffer::where('application_id', $request->application_id)
                    ->whereNotNull('offer_letter_url')
                    ->where('id', '!=', $offer->id)
                    ->get();
                foreach ($existingOffers as $existingOffer) {
                    $url = $existingOffer->offer_letter_url;
                    $baseUrl = 'https://' . env('DO_SPACES_BUCKET') . '.' . env('DO_SPACES_REGION') . '.digitaloceanspaces.com/';
                    if (str_starts_with($url, $baseUrl)) {
                        $file_path = str_replace($baseUrl, '', $url);
                        try {
                            Storage::disk('digitalocean')->delete($file_path);
                            Log::info('Deleted old offer letter: ' . $file_path);
                            $existingOffer->delete(); // Delete the old offer record
                        } catch (\Exception $e) {
                            Log::error('Failed to delete old offer letter: ' . $e->getMessage());
                        }
                    }
                
                }

                // Send offer letter email with PDF attachment
                $application = talent_jobapplication::find($offer->application_id);
                if ($application && $application->email) {
                    $pdfPath = null;

                    // Prepare data for blade view
                    $userName = $application->first_name . ' ' . $application->last_name;
                    $todayDate = now()->format('F j, Y');
                    $deadlineDate = $offer->start_date ? \Carbon\Carbon::parse($offer->start_date)->subDays(3)->format('F j, Y') : now()->addDays(7)->format('F j, Y');
                    $signerName = $signerUser ? ($signerUser->first_name . ' ' . ($signerUser->middle_name ? $signerUser->middle_name . ' ' : '') . $signerUser->last_name) : 'Signer Name';

                    $data = [
                        'candidate_name' => $userName,
                        'position' => $offer->position,
                        'start_date' => $offer->start_date ? \Carbon\Carbon::parse($offer->start_date)->format('F d, Y') : null,
                        'salary' => $offer->salary,
                        'deadline' => $deadlineDate,
                        'company_name' => $org->legal_name ?? 'Company Name',
                        'company_address' => $org->registered_address ?? 'Address',
                        'cin' => $org->cin ?? 'CIN',
                        'signer_name' => $signerName,
                        'mobile_no' => $org->mobile_no ?? null,
                        'country_code' => $org->country_code ?? '+91',
                        'email' => $org->email ?? null,
                        'website' => $org->website ?? null,
                    ];

                    // Render blade view to HTML
                    $html = view('offer_letter2', $data)->render();

                    // Generate PDF
                    $pdf = PDF::loadHTML($html);
                    $fileName = 'offer_letter_' . $offer->id . '_' . str_replace(' ', '_', $userName) . '.pdf';
                    $pdfPath = storage_path('app/public/' . $fileName);
                    $pdf->save($pdfPath);

                    // Store PDF in DigitalOcean Space
                    try {
                        $file_path = 'public/offerLetter/' . $fileName;
                        Log::info('Attempting to store offer letter: ' . $file_path);
                        $result = Storage::disk('digitalocean')->put($file_path, file_get_contents($pdfPath), 'public', [
                            'Cache-Control' => 'max-age=0, no-cache, no-store'
                        ]);
                        Log::info('Storage result: ' . ($result ? 'success' : 'failed'));
                    } catch (\Exception $e) {
                        // Log the error if storage fails
                        Log::error('Failed to store offer letter in DigitalOcean: ' . $e->getMessage());
                    }

                    // Save the offer letter URL to the database if storage was successful
                    if (isset($result) && $result) {
                        $url = 'https://' . env('DO_SPACES_BUCKET') . '.' . env('DO_SPACES_REGION') . '.digitaloceanspaces.com/' . $file_path;
                        $offer->offer_letter_url = $url;
                        $offer->save();
                    }

                    /*
                     * THE GATE - per tenant, and the offer survives it either way.
                     *
                     * This used to call MailGate::allowed() (the global switch)
                     * and return 503 if it was off. Two bugs in one line, both
                     * hit during the live lifecycle re-audit:
                     *
                     *   1. It asked the wrong gate. Tenant 6 is on the
                     *      G2G_NOTIFY_EMAIL_TENANTS allowlist, but the global
                     *      flag is off, so allowed() said no and creating an
                     *      offer for an allowlisted tenant failed with 503.
                     *
                     *   2. The offer was already saved above. Returning 503 for
                     *      the whole request reported failure for a record that
                     *      exists, so HR sees an error and the candidate has an
                     *      offer - the worst split.
                     *
                     * The offer is a record; the email is a notification about
                     * it. A notification that cannot go out does not undo the
                     * record. This mirrors candidateLink() below, which Sprint 4b
                     * already built the right way.
                     */
                    /*
                     * MINT THE ACCEPT LINK BEFORE SENDING, SO ONE ACTION DOES THE JOB.
                     *
                     * The token used to be issued by a SEPARATE button on another
                     * screen (candidateLink()), pressed after this email had already
                     * gone out. So the candidate received an offer with no way to
                     * answer it and waited for a follow-up that depended on somebody
                     * remembering. Now creating the offer sends a letter the
                     * candidate can act on.
                     *
                     * Minted OUTSIDE the mail gate on purpose: the link must exist
                     * whether or not this organisation may send email, because a
                     * recruiter can still copy it from the offer screen. That is the
                     * same reasoning candidateLink() already used.
                     *
                     * NO CREDENTIALS TRAVEL WITH THIS. The candidate has no account
                     * yet and must not - EmployeeFactory::issueInvite() issues one on
                     * ACCEPTANCE, which is the moment they become an employee.
                     */
                    $responseUrl = null;
                    $linkExpires = null;

                    try {
                        $minted = $this->links->mint(
                            $offer,
                            (int) $sub_institute_id,
                            (string) ($request->input('syear') ?: date('Y')),
                            $this->g2gActorId($request),
                            $application->email
                        );
                        $responseUrl = rtrim((string) config('app.url'), '/') . '/offer/' . $minted['token'];
                        $linkExpires = $minted['expires_at'];
                    } catch (\Throwable $e) {
                        // The offer and its letter stand without a link; the email
                        // falls back to "someone will be in touch".
                        Log::error('Offer link could not be minted: ' . $e->getMessage());
                    }

                    if (\App\Support\MailGate::allowedForTenant($sub_institute_id)) {
                        try {
                            Mail::to($application->email)->send(new OfferLetterMail(
                                $offer,
                                $pdfPath,
                                trim($application->first_name . ' ' . $application->last_name),
                                $org->legal_name ?? null,
                                $responseUrl,
                                $linkExpires
                            ));
                            $offer->status = 'sent';
                            $offer->sent_at = now();
                            $offer->save();
                            $mailed = true;
                        } catch (\Throwable $e) {
                            // A failed send must not lose the offer.
                            Log::error('Offer letter email failed: ' . $e->getMessage());
                        }
                    }
                }

                return response()->json([
                    'status' => 1,
                    'message' => $mailed
                        ? 'Talent offer created and emailed to the candidate.'
                        : 'Talent offer created. Email was not sent (' . \App\Support\MailGate::reasonForTenant($sub_institute_id) . ').',
                    'data' => $offer,
                    'mail' => ['sent' => $mailed],
                ], 200);
            }

            return response()->json(['message' => 'Failed to save offer'], 500);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $type = $request->input('type');

        // Allow execution only if request type is API
        if ($type !== "API") {
            return response()->json(['message' => 'Invalid request type'], 400);
        }

        // Check and validate token
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $user = $accessToken->tokenable;
        $sub_institute_id = $this->apiTenantId($request) ?? $user->sub_institute_id;

        if (!$sub_institute_id) {
            return response()->json(['message' => 'sub_institute_id not provided'], 400);
        }

        try {
            $offers = TalentOffer::where('sub_institute_id', $sub_institute_id)->get();

            /*
             * The presented status, not the stored one.
             *
             * talent_offers.status is ENUM('draft','sent','rejected','expired') and
             * cannot hold 'accepted', so acceptance lives in talent_offer_acceptances
             * and is folded in here. The frontend already maps 'accepted' -> Accepted
             * (use-recruitment.ts:139-146) and already counts it in the Offers KPI, so
             * nothing downstream needs to change for the badge to flip.
             */
            $accepted = DB::table('talent_offer_acceptances')
                ->where('sub_institute_id', $sub_institute_id)
                ->where('decision', 'accepted')
                ->whereNull('deleted_at')
                ->pluck('accepted_employee_id', 'offer_id');

            $offers->each(function ($offer) use ($accepted) {
                if ($accepted->has($offer->id)) {
                    $offer->status = 'accepted';
                    $offer->accepted_employee_id = $accepted->get($offer->id);
                }
            });

            return response()->json([
                'status' => 1,
                'message' => 'Offers retrieved successfully!',
                'data' => $offers
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Reject a talent offer.
     */
    public function reject(Request $request, $id)
    {
        $type = $request->input('type');

        // Allow execution only if request type is API
        if ($type !== "API") {
            return response()->json(['message' => 'Invalid request type'], 400);
        }

        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        try {
            // The tenant predicate belongs in the lookup. Before Sprint 1 this was
            // TalentOffer::find($id), so any token from any organisation could reject
            // any offer by id and cascade that rejection onto the application.
            $offer = TalentOffer::where('id', $id)
                ->where('sub_institute_id', $tenantId)
                ->first();

            if (!$offer) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Offer not found'
                ], 404);
            }

            // 'rejected' is correct for talent_offers, whose enum is lower case.
            // talent_job_applications.status is Title Case, and sql_mode carries
            // STRICT_TRANS_TABLES, so the previous lower-case write raised a
            // truncation error and this endpoint returned 500 instead of rejecting.
            $offer->status = 'rejected';
            DB::table('talent_job_applications')
                ->where('id', $offer->application_id)
                ->where('sub_institute_id', $tenantId)
                ->update(['status' => 'Rejected']);
            $offer->rejected_at = now();
            $offer->save();

            return response()->json([
                'status' => 1,
                'message' => 'Offer rejected successfully!',
                'data' => $offer
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Accept an offer, and turn the candidate into an employee.
     *
     * ── THE JOINT THE LIFECYCLE WAS SEVERED AT ──────────────────────────────
     *
     * This controller had store(), index() and reject() and no accept(). A
     * candidate could be rejected but never accepted, so the hire stopped at the
     * offer and somebody retyped the person into the Employee Directory by hand.
     * Everything downstream - onboarding, the directory, a job role, a first task -
     * waited on a step the product could not perform.
     *
     * talent_offers.status is an ENUM with no 'accepted' member, so acceptance is
     * recorded in talent_offer_acceptances and index() derives the presented status
     * from it. No ALTER on a live table, and the acceptance keeps its own audit
     * trail: who answered, when, through which surface, and which employee resulted.
     *
     * IDEMPOTENT, in two independent ways, because an accepted offer must never
     * produce two employees:
     *   1. an acceptance already marked accepted returns the employee it created;
     *   2. failing that, an existing employee with the candidate's email in this
     *      tenant is adopted rather than duplicated.
     */
    public function accept(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $syear    = (string) ($request->input('syear') ?: date('Y'));

        $offer = TalentOffer::where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (!$offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        // The sequence lives in OfferAcceptanceService so that HR recording the
        // answer and the candidate answering their own link cannot drift apart.
        $result = $this->acceptance->accept(
            $offer, $tenantId, $syear, $identity['user_id'], 'hr', $request->input('note')
        );

        if (!$result['ok']) {
            return response()->json(['status' => 0, 'message' => $result['message']], $result['status']);
        }

        return response()->json([
            'status'  => 1,
            'message' => $result['message'],
            'data'    => [
                'offer_id'     => (int) $offer->id,
                'employee_id'  => $result['employee_id'],
                'created'      => $result['created'],
                'invite_sent'  => $result['invite']['sent'],
                'invite_error' => $result['invite']['error'],
            ],
        ], 200);
    }

    /**
     * Mint a candidate link for an offer, and try to email it.
     *
     * Returns the link to HR regardless of whether the email went out. Outbound
     * mail is gated per tenant (see MailGate), so the honest thing is to hand the
     * recruiter something they can paste into their own message rather than fail
     * the whole action because a send was refused - the same shape
     * EmployeeFactory::issueInvite() uses.
     *
     * Re-issuing invalidates the previous link: the hash is overwritten.
     */
    public function candidateLink(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $syear    = (string) ($request->input('syear') ?: date('Y'));

        $offer = TalentOffer::where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (!$offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        if (in_array($offer->status, ['rejected', 'expired'], true)) {
            return response()->json([
                'status'  => 0,
                'message' => 'This offer is ' . $offer->status . ' and cannot be sent to a candidate.',
            ], 422);
        }

        $application = DB::table('talent_job_applications')
            ->where('id', $offer->application_id)
            ->where('sub_institute_id', $tenantId)
            ->first(['id', 'email', 'first_name']);

        if (!$application || !$application->email) {
            return response()->json([
                'status'  => 0,
                'message' => 'This candidate has no email address, so a link cannot be issued.',
            ], 422);
        }

        $minted = $this->links->mint($offer, $tenantId, $syear, $identity['user_id'], $application->email);
        $url = rtrim((string) config('app.url'), '/') . '/offer/' . $minted['token'];

        $mail = ['sent' => false, 'error' => \App\Support\MailGate::reasonForTenant($tenantId)];

        if (\App\Support\MailGate::allowedForTenant($tenantId)) {
            try {
                Mail::raw(
                    "Hello " . ($application->first_name ?: 'there') . ",\n\n"
                    . "Your offer for " . $offer->position . " is ready. You can accept or decline it here:\n\n"
                    . $url . "\n\n"
                    . "This link expires on " . $minted['expires_at']->format('j M Y') . ".\n",
                    function ($m) use ($application, $offer) {
                        $m->to($application->email)->subject('Your offer: ' . $offer->position);
                    }
                );
                $mail = ['sent' => true, 'error' => null];
            } catch (\Throwable $e) {
                // A failed send must not lose the link - HR can still copy it.
                $mail = ['sent' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'status'  => 1,
            'message' => $mail['sent']
                ? 'Link created and emailed to the candidate.'
                : 'Link created. Copy it to the candidate - email was not sent.',
            'data'    => [
                'offer_id'   => (int) $offer->id,
                'url'        => $url,
                'expires_at' => $minted['expires_at']->toDateTimeString(),
                'email_sent' => $mail['sent'],
                'email_error' => $mail['error'],
            ],
        ], 200);
    }

    /**
     * Open a stored offer letter.
     *
     * routes/api.php:212 has pointed at this method since the route was added, but
     * the method did not exist - so "View offer letter" in the Offers tab and
     * "Open offer letter" in the offer drawer were both live 500s. Every one of the
     * 68 offers on the application database already carries an offer_letter_url
     * written by store(), so there was a file to serve the whole time.
     *
     * Returns a redirect rather than JSON because the frontend opens this URL in a
     * new tab (recruitment-center.tsx:892, recruitment-action-drawer.tsx:307).
     */
    public function getOfferLetter(Request $request, $offerId)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $offer = TalentOffer::where('id', $offerId)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (!$offer || !$offer->offer_letter_url) {
            return response()->json([
                'status' => 0,
                'message' => 'Offer letter not found.'
            ], 404);
        }

        return redirect()->away($offer->offer_letter_url);
    }

    /**
     * Offer letter templates for the caller's organisation.
     *
     * routes/api.php:213 pointed at a method that did not exist, so the template
     * picker in the offer drawer (recruitment-action-drawer.tsx:89) always failed.
     * talent_offer_templates holds zero rows on the application database today, so
     * this correctly returns an empty list rather than an error - an empty picker is
     * the truth, a 500 was not.
     */
    public function getTemplates(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $templates = DB::table('talent_offer_templates')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get(['id', 'module_name', 'title', 'sort_order']);

        return response()->json([
            'status' => 1,
            'message' => 'Templates retrieved successfully!',
            'data' => $templates,
        ], 200);
    }
}
