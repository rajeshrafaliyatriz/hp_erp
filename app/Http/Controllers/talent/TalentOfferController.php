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
    private function g2gActorId(\Illuminate\Http\Request $request): ?int
    {
        $fromToken = $this->apiUserId($request);
        if ($fromToken) {
            return $fromToken;
        }
        $fromSession = $request->session()->get('user_id');

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }


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
            'reportmanager' => 'nullable|string',
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

                    Mail::to($application->email)->send(new OfferLetterMail($offer, $pdfPath));

                    // Update status to 'sent' after successful email send
                    $offer->status = 'sent';
                    $offer->sent_at = now();
                    $offer->save();
                }

                return response()->json([
                    'status' => 1,
                    'message' => 'Talent offer created successfully!',
                    'data' => $offer
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

        // Check and validate token
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        try {
            $offer = TalentOffer::find($id);

            if (!$offer) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Offer not found'
                ], 404);
            }

            $offer->status = 'rejected';
            DB::table('talent_job_applications')
                ->where('id', $offer->application_id)
                ->update(['status' => 'rejected']);
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
}
