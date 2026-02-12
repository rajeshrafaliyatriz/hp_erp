<?php

namespace App\Http\Controllers\Api\Gemini;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyzeJDController extends Controller
{
    public function analyze(Request $request)
    {
        try {
            /* ===============================
             * 1️⃣ Validate Request
             * =============================== */
            $request->validate([
                'jd' => 'required|string',
                'sub_institute_id' => 'nullable|integer'
            ]);

            $jd = $request->jd;
            $subInstituteId = session()->get('sub_institute_id') ?? $request->sub_institute_id ?? 3;

            /* ===============================
             * 2️⃣ Fetch Gemini API Keys (MULTIPLE – Fallback Support)
             * =============================== */
            $geminiApiRows = Cache::remember(
                "gemini_api_rows_{$subInstituteId}",
                now()->addHours(6),
                function () use ($subInstituteId) {
                    return DB::table('gemini_api')
                        ->where('status', 1)
                        ->where(function ($query) use ($subInstituteId) {
                            $query->where('sub_institute_id', $subInstituteId)
                                  ->orWhereNull('sub_institute_id');
                        })
                        ->where(function ($q) {
                            $q->whereNull('limit')->orWhere('limit', '>', 0);
                        })
                        ->orderByRaw('sub_institute_id IS NULL') // institute-specific first
                        ->get();
                }
            );

            if ($geminiApiRows->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Gemini API key not found or inactive'
                ], 500);
            }

            /* ===============================
             * 3️⃣ Fetch ICF Competency Framework
             * =============================== */
            $icfApi = "https://hp.triz.co.in/getSkillCompetency?sub_institute_id={$subInstituteId}";
            $icfData = [];

            try {
                $icfResponse = Http::timeout(15)->get($icfApi);
                if ($icfResponse->successful()) {
                    $icfData = is_array($icfResponse->json())
                        ? $icfResponse->json()
                        : [];
                }
            } catch (\Exception $e) {
                // Silently ignore framework failure
            }

            /* ===============================
             * 4️⃣ Gemini Prompt
             * =============================== */
            $prompt = <<<PROMPT
Analyze this job description and extract:
1. Core technical skills (5-8 specific skills)
2. Behavioral traits (3-5 soft skills)
3. Competency level required for each skill (Beginner/Intermediate/Advanced/Expert)

Job Description:
{$jd}

Respond strictly in valid JSON:
{
  "core_skills": [],
  "behavioral_traits": [],
  "competency_level": {}
}
PROMPT;

            /* ===============================
             * 5️⃣ Call Gemini API (WITH FALLBACK)
             * =============================== */
            $response = null;
            $usedApiRow = null;

            foreach ($geminiApiRows as $geminiApiRow) {

                // Optional usage limit check (OLD LOGIC PRESERVED)
                if (!is_null($geminiApiRow->limit) && $geminiApiRow->limit <= 0) {
                    continue;
                }

                try {
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiApiRow->key}";

                    $apiResponse = Http::withHeaders([
                        'Content-Type' => 'application/json',
                    ])->withoutVerifying()->timeout(120)->post($url, [
                        "contents" => [
                            [
                                "parts" => [
                                    ["text" => $prompt]
                                ]
                            ]
                        ]
                    ]);

                    $json = $apiResponse->json();

                    if ($apiResponse->successful() && !isset($json['error'])) {
                        $response = $json;
                        $usedApiRow = $geminiApiRow;
                        break; // ✅ Stop at first success
                    }

                } catch (\Exception $e) {
                    // Try next API key
                    continue;
                }
            }

            if (!$response) {
                return response()->json([
                    'success' => false,
                    'error' => 'All Gemini API keys failed'
                ], 500);
            }

            $textResponse = $response['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

            /* ===============================
             * 6️⃣ Safe JSON Parsing (OLD LOGIC PRESERVED)
             * =============================== */
            try {
                $parsed = json_decode($textResponse, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Exception $e) {
                preg_match('/\{[\s\S]*\}/', $textResponse, $matches);
                $parsed = isset($matches[0])
                    ? json_decode($matches[0], true)
                    : [];
            }

            $coreSkills = $parsed['core_skills'] ?? [];
            $behavioralTraits = $parsed['behavioral_traits'] ?? [];
            $competencyLevels = $parsed['competency_level'] ?? [];

            /* ===============================
             * 7️⃣ Map Skills with ICF Framework (OLD LOGIC PRESERVED)
             * =============================== */
            $mappedCompetencies = [];

            foreach ($coreSkills as $skill) {
                $matched = collect($icfData)->first(function ($comp) use ($skill) {
                    return str_contains(strtolower($comp['skill_name'] ?? ''), strtolower($skill))
                        || str_contains(strtolower($comp['competency_name'] ?? ''), strtolower($skill));
                });

                $mappedCompetencies[] = [
                    'skill' => $skill,
                    'framework_match' => $matched['skill_name']
                        ?? $matched['competency_name']
                        ?? null,
                    'proficiency' => $competencyLevels[$skill] ?? 'Intermediate',
                    'matched' => !is_null($matched)
                ];
            }

            $frameworkCoverage = count($mappedCompetencies)
                ? (collect($mappedCompetencies)->where('matched', true)->count()
                    / count($mappedCompetencies)) * 100
                : 0;

            /* ===============================
             * 8️⃣ Decrease API Usage Limit (ONLY SUCCESSFUL KEY)
             * =============================== */
            if ($usedApiRow && !is_null($usedApiRow->limit)) {
                DB::table('gemini_api')
                    ->where('id', $usedApiRow->id)
                    ->decrement('limit');
            }

            /* ===============================
             * 9️⃣ Final Response
             * =============================== */
            return response()->json([
                'success' => true,
                'core_skills' => $coreSkills,
                'behavioral_traits' => $behavioralTraits,
                'competency_level' => $competencyLevels,
                'mapped_competencies' => $mappedCompetencies,
                'framework_coverage' => round($frameworkCoverage, 2)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to analyze job description',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
