<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\talent\talent_screening_results;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AI screening verdicts for job applicants.
 *
 * ── WHY EVERY QUERY IN THIS FILE NAMES A TENANT ─────────────────────────────
 *
 * Until Sprint 1 this controller validated that a token existed and then threw
 * its owner away. None of its four queries filtered by sub_institute_id, and
 * store() persisted whatever sub_institute_id the caller posted. That was not
 * theoretical: a tenant-6 employee token reading
 * GET /api/talent-screening-results/candidate/18 - a row owned by tenant 3 -
 * returned HTTP 200 with that candidate's skill gaps, ranking score and
 * recommendation. The correct answer is 404; a 403 would confirm the row exists.
 *
 * The rule, copied from ResolvesApiIdentity: the token decides, the request is
 * never trusted for identity. The tenant predicate lives INSIDE the id lookup,
 * never as a fetch-then-compare.
 */
class talent_screening_results_controller extends Controller
{
    use ResolvesApiIdentity;

    public function store(Request $request)
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

        $validator = Validator::make($request->all(), [
            'candidate_id' => 'required|integer',
            'competency_match' => 'required|integer|min:0|max:100',
            'cultural_fit' => 'required|in:High,Medium,Low',
            'predicted_success' => 'required|in:Highly Likely,Likely,Possible,Unlikely',
            'overall_fit_score' => 'required|integer|min:0|max:100',
            'ranking_score' => 'required|integer|min:0|max:100',
            'skill_gaps' => 'nullable|array',
            'strengths' => 'nullable|array',
            'recommendation' => 'required|string',
            'deepseek_analysis' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // The application must belong to the caller's organisation. `exists:` alone
        // would only prove the id names SOME real application, not one this caller
        // may write a verdict against.
        $ownsApplication = DB::table('talent_job_applications')
            ->where('id', (int) $request->input('candidate_id'))
            ->where('sub_institute_id', $tenantId)
            ->exists();

        if (!$ownsApplication) {
            return response()->json(['success' => false, 'message' => 'Candidate not found.'], 404);
        }

        $data = $validator->validated();
        // From the token, never the request. A caller naming another organisation
        // here would otherwise file a screening verdict into that organisation.
        $data['sub_institute_id'] = $tenantId;
        $data['created_by'] = $identity['user_id'];

        $result = talent_screening_results::create($data);

        return response()->json(['success' => true, 'data' => $result], 201);
    }

    private function calculateMatchPercentage($candidate_id, $tenantId)
    {
        // Fetch candidate application data
        $application = DB::table('talent_job_applications')
            ->where('id', $candidate_id)
            ->where('sub_institute_id', $tenantId)
            ->first();
        if (!$application) {
            return null;
        }

        // Fetch job posting data
        $jobPosting = DB::table('talent_job_postings')
            ->where('id', $application->job_id)
            ->where('sub_institute_id', $tenantId)
            ->first();
        if (!$jobPosting) {
            return null;
        }

        $matchScores = [];

        // Skills matching (33.33% weight)
        $skillsMatch = $this->calculateSkillsMatch($application->skills, $jobPosting->skills);
        $matchScores['skills'] = $skillsMatch;

        // Education matching (33.33% weight)
        $educationMatch = $this->calculateEducationMatch($application->education, $jobPosting->education);
        $matchScores['education'] = $educationMatch;

        // Experience matching (33.33% weight)
        $experienceMatch = $this->calculateExperienceMatch($application->experience, $jobPosting->experience);
        $matchScores['experience'] = $experienceMatch;

        // Calculate overall match percentage
        $overallMatch = round(($skillsMatch + $educationMatch + $experienceMatch) / 3, 2);

        return [
            'overall_match' => $overallMatch,
            'skills_match' => $skillsMatch,
            'education_match' => $educationMatch,
            'experience_match' => $experienceMatch,
            'details' => $matchScores
        ];
    }

    private function calculateSkillsMatch($candidateSkills, $requiredSkills)
    {
        if (empty($candidateSkills) || empty($requiredSkills)) {
            return 0;
        }

        // Convert to lowercase and split by common delimiters
        $candidateSkillsArray = array_map('trim', preg_split('/[,;|\n]/', strtolower($candidateSkills)));
        $requiredSkillsArray = array_map('trim', preg_split('/[,;|\n]/', strtolower($requiredSkills)));

        // Remove empty values
        $candidateSkillsArray = array_filter($candidateSkillsArray);
        $requiredSkillsArray = array_filter($requiredSkillsArray);

        if (empty($requiredSkillsArray)) {
            return 0;
        }

        $matchedSkills = 0;
        foreach ($requiredSkillsArray as $requiredSkill) {
            foreach ($candidateSkillsArray as $candidateSkill) {
                // Simple string matching - could be enhanced with fuzzy matching
                if (strpos($candidateSkill, $requiredSkill) !== false || strpos($requiredSkill, $candidateSkill) !== false) {
                    $matchedSkills++;
                    break;
                }
            }
        }

        return round(($matchedSkills / count($requiredSkillsArray)) * 100, 2);
    }

    private function calculateEducationMatch($candidateEducation, $requiredEducation)
    {
        if (empty($candidateEducation) || empty($requiredEducation)) {
            return 0;
        }

        $candidateEdu = strtolower(trim($candidateEducation));
        $requiredEdu = strtolower(trim($requiredEducation));

        // Simple exact match for now - could be enhanced with education level hierarchy
        if (strpos($candidateEdu, $requiredEdu) !== false || strpos($requiredEdu, $candidateEdu) !== false) {
            return 100;
        }

        return 0;
    }

    private function calculateExperienceMatch($candidateExperience, $requiredExperience)
    {
        if (empty($candidateExperience) || empty($requiredExperience)) {
            return 0;
        }

        $candidateYears = $this->parseExperienceYears($candidateExperience);
        $requiredYears = $this->parseExperienceYears($requiredExperience);

        if ($candidateYears === null || $requiredYears === null) {
            return 0;
        }

        // If candidate has more or equal experience than required
        if ($candidateYears >= $requiredYears) {
            return 100;
        }

        // Partial match if candidate has at least 50% of required experience
        if ($candidateYears >= $requiredYears * 0.5) {
            return round(($candidateYears / $requiredYears) * 100, 2);
        }

        return 0;
    }

    private function parseExperienceYears($experienceString)
    {
        if (empty($experienceString)) {
            return null;
        }

        $experience = strtolower(trim($experienceString));

        // Handle ranges like "2-5 years"
        if (preg_match('/(\d+)-(\d+)\s*years?/', $experience, $matches)) {
            return (int) $matches[1]; // Take minimum years
        }

        // Handle "X years" or "X+ years"
        if (preg_match('/(\d+)\+?\s*years?/', $experience, $matches)) {
            return (int) $matches[1];
        }

        // Handle "X to Y years"
        if (preg_match('/(\d+)\s*to\s*(\d+)\s*years?/', $experience, $matches)) {
            return (int) $matches[1]; // Take minimum years
        }

        return null;
    }

    private function parseCandidateDataForBERT($candidateData)
    {
        if (!$candidateData) {
            return [
                'totalSkillsFound' => 0,
                'yearsExperience' => 0,
                'educationLevel' => '',
                'extractionScore' => 0.0
            ];
        }

        // Count skills
        $skillsCount = 0;
        if (!empty($candidateData->skills)) {
            $skillsArray = array_map('trim', preg_split('/[,;|\n]/', $candidateData->skills));
            $skillsArray = array_filter($skillsArray);
            $skillsCount = count($skillsArray);
        }

        // Parse experience years
        $yearsExperience = $this->parseExperienceYears($candidateData->experience) ?? 0;

        // Education level
        $educationLevel = $candidateData->education ?? '';

        // Calculate extraction score based on data completeness
        $completenessScore = 0;
        if (!empty($candidateData->skills)) $completenessScore += 0.3;
        if (!empty($candidateData->education)) $completenessScore += 0.3;
        if (!empty($candidateData->experience)) $completenessScore += 0.4;

        // Add quality score based on data richness
        $qualityScore = 0;
        if ($skillsCount > 3) $qualityScore += 0.2;
        if (strlen($candidateData->education ?? '') > 10) $qualityScore += 0.1;
        if ($yearsExperience > 0) $qualityScore += 0.1;

        $extractionScore = round(min(1.0, $completenessScore + $qualityScore), 2);

        return [
            'totalSkillsFound' => $skillsCount,
            'yearsExperience' => $yearsExperience,
            'educationLevel' => $educationLevel,
            'extractionScore' => $extractionScore
        ];
    }

    public function show(Request $request, $candidate_id)
    {
        $type = $request->input('type');

        if ($type !== 'API') {
            return response()->json(['message' => 'Invalid request type'], 400);
        }

        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        // The tenant predicate is part of the lookup, not a check afterwards, so a
        // row belonging to another organisation is indistinguishable from one that
        // does not exist. 404, never 403.
        $result = talent_screening_results::where('candidate_id', $candidate_id)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (!$result) {
            return response()->json(['success' => false, 'message' => 'Screening result not found'], 404);
        }

        $deepseek = $result->deepseek_analysis ?? [];

        // Compute skill_match_details from strengths
        $skill_match_details = [];
        foreach ($result->strengths ?? [] as $strength) {
            if (preg_match('/^(.+) \((.+)\)$/', $strength, $matches)) {
                $skill = trim($matches[1]);
                $prof = trim($matches[2]);
                $skill_match_details[] = [
                    'competency' => $skill,
                    'matched' => true,
                    'extractedSkill' => $skill,
                    'confidence' => 0.95,
                    'proficiency' => $prof
                ];
            }
        }

        // Get match percentage from scoring pipeline
        $matchData = $this->calculateMatchPercentage($candidate_id, $tenantId);

        // Fetch candidate data for dynamic bert_parsed_resume
        $candidateData = DB::table('talent_job_applications')
            ->where('id', $candidate_id)
            ->where('sub_institute_id', $tenantId)
            ->first();

        // Parse candidate data for bert_parsed_resume
        $bertData = $this->parseCandidateDataForBERT($candidateData);

        // Construct the response format
        $response = [
            'success' => true,
            'candidateId' => $result->candidate_id,
            'scoringPipeline' => [
                'bert_parsed_resume' => $bertData,
                'competency_scoring' => [
                    'overallFitScore' => $result->overall_fit_score,
                    'rankingScore' => $result->ranking_score,
                    'culturalFitIndex' => 78,
                    'matchedCompetencies' => 4,
                    'totalRequired' => 4
                ],
                'deepseek_validation' => [
                    'competency_match' => $result->competency_match,
                    'cultural_fit' => $result->cultural_fit,
                    'predicted_success' => $result->predicted_success,
                    'summary' => $deepseek['summary'] ?? $result->recommendation,
                    'skill_gaps' => $result->skill_gaps ?? [],
                    'strengths' => $result->strengths ?? [],
                    'recommendation' => $result->recommendation,
                    'reasoning' => $deepseek['reasoning'] ?? ''
                ],
                'match_percentage' => $matchData ? $matchData['overall_match'] : 0,
                'detailed_matches' => $matchData ? [
                    'skills_match' => $matchData['skills_match'],
                    'education_match' => $matchData['education_match'],
                    'experience_match' => $matchData['experience_match']
                ] : null
            ],
            'competency_match' => $result->competency_match,
            'cultural_fit' => $result->cultural_fit,
            'predicted_success' => $result->predicted_success,
            'summary' => $deepseek['summary'] ?? $result->recommendation,
            'skill_gaps' => $result->skill_gaps ?? [],
            'strengths' => $result->strengths ?? [],
            'recommendation' => $result->recommendation,
            'ranking_score' => $result->ranking_score,
            'skill_match_details' => $skill_match_details
        ];

        return response()->json($response);
    }

}