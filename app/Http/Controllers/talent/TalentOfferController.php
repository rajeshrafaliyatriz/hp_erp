<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\TalentOffer;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\TalentOfferTemplate;
use App\Mail\OfferLetterMail;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

class TalentOfferController extends Controller
{
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

        $sub_institute_id = $request->input('sub_institute_id');

        // Validation rules
        $validator = Validator::make($request->all(), [
            'application_id' => 'required|exists:talent_job_applications,id',
            'job_id' => 'required|exists:talent_job_postings,id',
            'position' => 'required|string|max:255',
            'salary' => 'nullable|string|max:100',
            'start_date' => 'nullable|date',
            'template_id' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'sub_institute_id' => 'required|integer',
            'user_id' => 'required|integer',
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
                'template_id' => $request->template_id,
                'notes' => $request->notes,
                'sub_institute_id' => $sub_institute_id,
                'created_by' => $request->user_id,
                'status' => 'draft', // default status
            ]);

            if ($offer->save()) {
                // Set updated_at to null for new records
                DB::table('talent_offers')->where('id', $offer->id)->update(['updated_at' => null]);

                // Send offer letter email with PDF attachment
                $application = talent_jobapplication::find($offer->application_id);
                if ($application && $application->email) {
                    $pdfPath = null;
                    if ($offer->template_id) {
                        $template = TalentOfferTemplate::find($offer->template_id);
                        if ($template) {
                            // ... inside your if($template) block ...

                            $html = $template->html_content;

                            // Prepare formatting
                            $userName = $application->first_name . ' ' . $application->last_name;
                            $todayDate = now()->format('F j, Y');
                            // Calculate a deadline (e.g., 7 days from now)
                            $deadlineDate = now()->addDays(7)->format('F j, Y');

                            // Define the clean replacements
                            $replacements = [
                                '{{candidate_name}}'    => $userName,
                                '{{position}}'          => $offer->position,
                                '{{start_date}}'        => $offer->start_date,
                                '{{salary}}'            => $offer->salary,
                                '{{current_date}}'      => $todayDate,
                                '{{acceptance_deadline}}' => $deadlineDate,

                                // KEEP OLD ONES just in case you have mixed templates in DB
                                'Adeline Palmerston'    => $userName,
                                'Graphic Designer'      => $offer->position,
                            ];

                            // Perform the replacement
                            $html = str_replace(array_keys($replacements), array_values($replacements), $html);

                            // Generate PDF
                            $pdf = PDF::loadHTML($html);
                            // ... continue with save and mail ...
                            $fileName = 'offer_letter_' . $offer->id . '.pdf';
                            $pdfPath = storage_path('app/public/' . $fileName);
                            $pdf->save($pdfPath);
                        }
                    }
                    Mail::to($application->email)->send(new OfferLetterMail($offer, $pdfPath));
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
}
