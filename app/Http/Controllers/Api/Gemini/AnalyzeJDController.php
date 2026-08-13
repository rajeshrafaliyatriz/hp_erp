<?php

namespace App\Http\Controllers\Api\Gemini;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyzeJDController extends Controller
{
    use ResolvesApiIdentity;

    public function analyze(Request $request)
    {
        try {
            /* ===============================
             * 1️⃣ Validate Request
             * =============================== */
            // `sub_institute_id` is no longer accepted as an input. It was declared
            // here and never read, which advertises a tenant parameter the server
            // ignores - and a parameter that looks honoured is worse than one that
            // is refused, because a caller will trust it.
            $request->validate([
                'jd' => 'required|string',
            ]);

            $jd = $request->jd;

            // THE TOKEN DECIDES WHEN THERE IS ONE, THE SESSION DECIDES OTHERWISE,
            // AND A CALLER WHO HAS NEITHER IS REFUSED. Same shape as
            // HrmsLeaveController::store(), which is the reference for any endpoint
            // reachable both by token and by session.
            //
            // WAS: session() ?? apiTenantId() ?? 3
            //   - the SESSION came first, so a token-authenticated caller with a
            //     stale session read another organisation's data. That is G-SEC-27's
            //     precedence inverted.
            //   - `?? 3` hardcoded the demo tenant as the answer to "who are you?",
            //     so identity FAILED OPEN onto a real, populated tenant.
            //   - `??` only falls through on null, so an empty-string session value
            //     was treated as a valid tenant. `?:` is used below for that reason.
            //
            // The cache key below is built from this value. A wrong tenant here did
            // not just read the wrong rows once - it POISONED A SHARED CACHE KEY for
            // six hours.
            $subInstituteId = $this->apiTenantId($request) ?: session('sub_institute_id');

            if (!$subInstituteId) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Unable to identify your organisation.',
                ], 401);
            }

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
