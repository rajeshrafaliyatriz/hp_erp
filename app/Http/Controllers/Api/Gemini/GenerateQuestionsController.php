<?php

namespace App\Http\Controllers\Api\Gemini;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class GenerateQuestionsController extends Controller
{
    private $workingModel = null;

    /**
     * Generate questions using Gemini API with batch processing and automatic API key failover.
     *
     * POST /api/gemini/generate-questions
     */
    public function generate(Request $request): JsonResponse
    {
        // Increase PHP execution time for long-running API calls
        ini_set('max_execution_time', 600); // 5 minutes

        // ── 0. Robust body parsing ────────────────────────────────────────────
        $rawBody = file_get_contents('php://input');
        $allInput = $request->all();

        if (empty($allInput) && !empty($rawBody)) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', trim($rawBody));
            $decoded = json_decode($cleaned, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $allInput = $decoded;
                $request->merge($decoded);
            }
        }

        if (empty($allInput)) {
            $jsonError = 'No body received';
            if (!empty($rawBody)) {
                json_decode(trim($rawBody), true, 512, JSON_INVALID_UTF8_IGNORE);
                $jsonError = json_last_error_msg();
            }

            return response()->json([
                'success' => false,
                'message' => 'Request body is empty or could not be parsed.',
                'hint' => 'In Postman: Body → raw → select JSON from dropdown. Ensure Content-Type: application/json header is present.',
                'debug' => [
                    'content_type' => $request->header('Content-Type'),
                    'raw_body_length' => strlen((string)$rawBody),
                    'raw_preview' => substr((string)$rawBody, 0, 300),
                    'json_decode_error' => $jsonError,
                ],
            ], 400);
        }

        // ── 1. Validate ───────────────────────────────────────────────────────
        $validator = Validator::make($allInput, [
            'jobRole' => 'required|string',
            'question_type' => 'required|string|in:multiple,single',
            'questionCount' => 'required|integer|min:1|max:100',
            'data' => 'required|array',
            'data.jobRole' => 'required|array',
            'data.jobRole.industries' => 'required|string',
            'data.jobRole.department' => 'required|string',
            'data.jobRole.jobrole' => 'required|string',
            'data.jobRole.description' => 'required|string',
            'data.skillsData' => 'nullable|array',
            'data.tasksData' => 'nullable|array',
            'data.knowledgeItems' => 'nullable|array',
            'data.abilityItems' => 'nullable|array',
            'data.attitudeItems' => 'nullable|array',
            'data.behaviourItems' => 'nullable|array',
            'mappings' => 'required|array|min:1',
            'mappings.*.typeId' => 'required|integer',
            'mappings.*.typeName' => 'required|string',
            'mappings.*.valueId' => 'required|integer',
            'mappings.*.valueName' => 'required|string',
            'mappings.*.domainCategory' => 'required|string',
            'mappings.*.marks' => 'required|integer|min:1',
            'mappings.*.questionCount' => 'required|integer|min:1',
            'mappings.*.reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // ── 2. Fetch all active Gemini API keys from DB ───────────────────────────────────
        $apiKeys = DB::table('gemini_api')
            ->where('status', 1)
            ->get();

        if ($apiKeys->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No active Gemini API keys found in the database.',
                'solution' => 'Please add at least one active API key to the gemini_api table with status=1'
            ], 500);
        }

        // Convert to array for easier manipulation
        $apiKeysArray = $apiKeys->toArray();
        Log::info('Loaded API keys for failover', ['total_keys' => count($apiKeysArray)]);

        // ── 3. Test and get working model with first API key ─────────────────────────────────────
        $firstKey = $apiKeysArray[0]->key ?? null;

        if (!$firstKey) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key found in database.',
            ], 500);
        }

        $workingModel = $this->getWorkingModel($firstKey);

        if (!$workingModel) {
            return response()->json([
                'success' => false,
                'message' => 'No working Gemini model found. Please check your API keys and ensure Gemini API is enabled.',
                'note' => 'Make sure at least one Gemini API key is correctly configured in your database.',
                'troubleshooting' => [
                    'Check API key validity at https://makersuite.google.com/app/apikey',
                    'Enable Gemini API in Google Cloud Console',
                    'Verify API keys in your gemini_api table have status=1'
                ]
            ], 500);
        }

        $this->workingModel = $workingModel;

        // ── 4. Extract data and BATCH configuration ──
        $totalQuestionCount = (int)$allInput['questionCount'];
        $data = $allInput['data'];
        $mappings = $allInput['mappings'];

        // Dynamic batch size (2-4 questions per batch for stable generation)
        $batchSize = $this->calculateBatchSize($totalQuestionCount);

        // Calculate questions per mapping based on total count if needed
        $mappings = $this->redistributeQuestions($mappings, $totalQuestionCount);

        // ── 5. Generate questions in batches with key failover ──
        $allQuestions = [];
        $currentQuestionId = 1;
        $batchNumber = 1;
        $totalBatches = 0;
        $currentKeyIndex = 0;
        $failedKeys = []; // Track permanently failed keys

        // Calculate total batches for logging
        foreach ($mappings as $mapping) {
            $totalBatches += ceil($mapping['questionCount'] / $batchSize);
        }

        foreach ($mappings as $mappingIndex => $mapping) {
            $questionsNeeded = $mapping['questionCount'];

            if ($questionsNeeded <= 0) {
                continue;
            }

            // Split mapping questions into smaller batches
            $batchQuestionCounts = array_fill(0, ceil($questionsNeeded / $batchSize), $batchSize);
            $lastBatch = count($batchQuestionCounts) - 1;
            $batchQuestionCounts[$lastBatch] = $questionsNeeded - (($lastBatch) * $batchSize);

            foreach ($batchQuestionCounts as $batchIndex => $batchQuestionCount) {
                if ($batchQuestionCount <= 0) continue;

                Log::info("Processing batch {$batchNumber} of {$totalBatches}", [
                    'mapping' => $mapping['typeName'],
                    'questions_in_batch' => $batchQuestionCount
                ]);

                // Create a temporary mapping for this batch
                $batchMapping = $mapping;
                $batchMapping['questionCount'] = $batchQuestionCount;
                $batchMapping['startId'] = $currentQuestionId;

                // Generate prompt for this batch
                $prompt = $this->buildBatchPrompt(
                    $data,
                    $batchMapping,
                    $batchQuestionCount,
                    $currentQuestionId,
                    $totalQuestionCount
                );

                // Call Gemini API with automatic key failover
                $response = $this->callGeminiAPIWithKeyFailover(
                    $prompt,
                    $apiKeysArray,
                    $this->workingModel,
                    $currentKeyIndex,
                    $failedKeys
                );

                if (!$response['success']) {
                    Log::error('Failed to generate batch with all available keys', [
                        'mapping' => $mapping['typeName'],
                        'batch' => $batchNumber,
                        'error' => $response['error'],
                        'failed_keys_count' => count($failedKeys)
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => "Failed to generate batch for mapping: {$mapping['typeName']}",
                        'error' => $response['error'],
                        'details' => 'All available API keys failed. Please check your API keys and quota limits.',
                        'failed_keys_count' => count($failedKeys)
                    ], 500);
                }

                // Update current key index for next batch (load balancing)
                $currentKeyIndex = $response['key_used_index'];

                // Extract and fix question IDs
                $batchQuestions = $response['questions'];
                foreach ($batchQuestions as &$question) {
                    $question['id'] = $currentQuestionId++;
                }

                $allQuestions = array_merge($allQuestions, $batchQuestions);
                $batchNumber++;

                // Small delay between batches to avoid rate limiting
               if ($batchNumber <= $totalBatches) {
                  // Space batches so 4 keys × N batches stay under RPM limit
                 // 10 RPM per key = 1 request per 6s per key
                 // With 4 keys in round-robin, safe delay is ~1.5s between batches
                 $delaySeconds = max(1.5, ceil(60 / (count($apiKeysArray) * 10)));
                 usleep($delaySeconds * 1000000);
                }
            }
        }

        // Verify we generated the requested number of questions
        if (count($allQuestions) !== $totalQuestionCount) {
            Log::warning('Question count mismatch', [
                'requested' => $totalQuestionCount,
                'generated' => count($allQuestions)
            ]);
        }

        // ── 6. Return final response ──
        return response()->json([
            'success' => true,
            'message' => 'Questions generated successfully.',
            'requestedCount' => $totalQuestionCount,
            'generatedCount' => count($allQuestions),
            'data' => [
                'questions' => $allQuestions
            ],
        ], 200);
    }

    /**
     * Call Gemini API with automatic key failover and load balancing.
     * Transient errors (rate limits, quota) retry with backoff before moving to next key.
     * Only permanently failed keys (invalid/expired) are blacklisted for the request.
     */
    private function callGeminiAPIWithKeyFailover(
        string $prompt,
        array $apiKeys,
        string $model,
        int $startKeyIndex = 0,
        array &$failedKeys = []
    ): array {
        $totalKeys        = count($apiKeys);
        $maxRetriesPerKey = 1;     // retry same key on transient errors
        $retryDelayMs     = 2000;  // 2s between retries on same key

        for ($i = 0; $i < $totalKeys; $i++) {
            $keyIndex = ($startKeyIndex + $i) % $totalKeys;

            // Only skip keys marked permanently failed for this request
            if (in_array($keyIndex, $failedKeys)) {
                Log::info('Skipping permanently failed key', ['key_index' => $keyIndex]);
                continue;
            }

            $currentKey  = $apiKeys[$keyIndex];
            $apiKeyValue = $currentKey->key ?? $currentKey['key'] ?? null;

            if (!$apiKeyValue) {
                Log::warning('Invalid API key entry', ['key_index' => $keyIndex]);
                $failedKeys[] = $keyIndex;
                continue;
            }

            // ── Retry loop for transient errors on this key ──────────────────
            for ($attempt = 1; $attempt <= $maxRetriesPerKey; $attempt++) {
                Log::info('Attempting API call with key', [
                    'key_index'  => $keyIndex,
                    'key_prefix' => substr($apiKeyValue, 0, 10) . '...',
                    'attempt'    => $attempt,
                    'total_keys' => $totalKeys,
                ]);

                $result = $this->callGeminiAPI($prompt, $apiKeyValue, $model);

                if ($result['success']) {
                    Log::info('Successfully used API key', [
                        'key_index'           => $keyIndex,
                        'attempt'             => $attempt,
                        'questions_generated' => count($result['questions'] ?? []),
                    ]);

                    return [
                        'success'        => true,
                        'questions'      => $result['questions'],
                        'key_used_index' => ($keyIndex + 1) % $totalKeys, // next key for round-robin
                        'key_used'       => $keyIndex,
                    ];
                }

                $error = $result['error'];

                Log::warning('API key failed', [
                    'key_index' => $keyIndex,
                    'attempt'   => $attempt,
                    'error'     => $error,
                ]);

                // ── Permanent failure: disable key in DB and blacklist it ────
                if ($this->isKeyPermanentlyFailed($error)) {
                    $this->updateKeyStatus($currentKey->id ?? null, 0);
                    Log::warning('Marking API key as inactive in database', [
                        'key_id' => $currentKey->id ?? 'unknown',
                        'reason' => $error,
                    ]);
                    $failedKeys[] = $keyIndex; // skip for rest of this request
                    break;                     // stop retrying this key
                }

                // ── Transient failure: wait and retry (NOT added to $failedKeys) ──
                if ($this->isKeyTransientlyFailed($error)) {
                   if ($attempt < $maxRetriesPerKey) {
                        $backoffMs = $retryDelayMs * pow(2, $attempt - 1); // 1.5s, then 3s
                        Log::info("Transient error — retrying key {$keyIndex} after {$backoffMs}ms");
                        usleep($backoffMs * 1000);
                        continue;
                    }

                    // Exhausted retries on this key for now — try next key,
                    // but do NOT blacklist it (it may recover for later batches)
                    Log::warning("Transient error — exhausted retries on key {$keyIndex}, trying next key", [
                        'error' => $error,
                    ]);
                    break;
                }

                // ── Unknown error type: try next key, do not blacklist ───────
                Log::warning('Unknown error type — skipping to next key', ['error' => $error]);
                break;
            }
        }

        return [
            'success' => false,
            'error'   => 'All available API keys failed. Please check your API keys and quota limits.',
        ];
    }

    /**
     * Check if error indicates permanent failure (invalid/expired key — should disable in DB).
     */
    private function isKeyPermanentlyFailed(string $error): bool
    {
        $permanentErrors = [
            'API key not valid',
            'API key expired',
            'Invalid API key',
            'PERMISSION_DENIED',
            'API_KEY_INVALID',
            '403',
            '401',
        ];

        foreach ($permanentErrors as $permanentError) {
            if (stripos($error, $permanentError) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error indicates a transient failure (rate limit / quota — retry-able).
     */
    private function isKeyTransientlyFailed(string $error): bool
    {
        $transientErrors = [
            'RESOURCE_EXHAUSTED',
            'quota',
            'rate limit',
            'Too Many Requests',
            '429',
            'overloaded',
            'SERVICE_UNAVAILABLE',
            '503',
            'UNAVAILABLE',
            'capacity',
            'try again',
        ];

        foreach ($transientErrors as $transientError) {
            if (stripos($error, $transientError) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update API key status in database.
     */
    private function updateKeyStatus(?int $keyId, int $status): void
    {
        if (!$keyId) {
            return;
        }

        try {
            DB::table('gemini_api')
                ->where('id', $keyId)
                ->update([
                    'status' => $status,
                    'last_failed_at' => now(),
                    'failure_reason' => 'Auto-disabled due to API errors'
                ]);
            Log::info('Updated API key status', ['key_id' => $keyId, 'status' => $status]);
        } catch (\Exception $e) {
            Log::error('Failed to update API key status', [
                'key_id' => $keyId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Test available Gemini models and cache the working one.
     */
    private function getWorkingModel(string $apiKey): ?string
    {
        // Check cache first (cache for 1 hour)
        $cachedModel = Cache::get('gemini_working_model');
        if ($cachedModel) {
            Log::info('Using cached working model', ['model' => $cachedModel]);
            return $cachedModel;
        }

        $modelsToTest = [
            'gemini-2.5-flash'
        ];

        foreach ($modelsToTest as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            try {
                Log::info('Testing model availability', ['model' => $model]);

                $response = Http::timeout(10)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => 'Say "OK"']
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 5
                    ]
                ]);

                if ($response->successful()) {
                    Log::info('Model is available', ['model' => $model]);
                    Cache::put('gemini_working_model', $model, 3600);
                    return $model;
                } else {
                    Log::warning('Model not available', [
                        'model' => $model,
                        'status' => $response->status()
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Model test failed', [
                    'model' => $model,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return null;
    }

    /**
     * Calculate optimal batch size based on total questions.
     */
    private function calculateBatchSize(int $totalQuestions): int
    {
        if ($totalQuestions <= 4) {
            return $totalQuestions; // One batch
        } elseif ($totalQuestions <= 10) {
            return 4; // 4 per batch
        } elseif ($totalQuestions <= 20) {
            return 3; // 3 per batch
        } else {
            return 2; // 2 per batch (safe for large requests)
        }
    }

    /**
     * Redistribute questions across mappings proportionally.
     */
    private function redistributeQuestions(array $mappings, int $totalQuestions): array
    {
        $totalMappedQuestions = array_sum(array_column($mappings, 'questionCount'));

        if ($totalMappedQuestions === $totalQuestions) {
            return $mappings;
        }

        // Proportional distribution
        foreach ($mappings as &$mapping) {
            $proportion = $mapping['questionCount'] / $totalMappedQuestions;
            $newCount = round($proportion * $totalQuestions);
            $mapping['questionCount'] = max(1, $newCount);
        }

        // Adjust for rounding errors
        $newTotal = array_sum(array_column($mappings, 'questionCount'));
        $difference = $totalQuestions - $newTotal;

        if ($difference != 0) {
            $mappings[0]['questionCount'] += $difference;
            if ($mappings[0]['questionCount'] < 1) {
                $mappings[0]['questionCount'] = 1;
            }
        }

        return $mappings;
    }

    /**
     * Build prompt for a single batch.
     */
    private function buildBatchPrompt(array $data, array $mapping, int $questionCount, int $startId, int $totalCount): string
    {
        $jobData = $data['jobRole'];
        $skillsData = $data['skillsData'] ?? [];
        $tasksData = $data['tasksData'] ?? [];
        $knowledgeItems = $data['knowledgeItems'] ?? [];
        $abilityItems = $data['abilityItems'] ?? [];
        $attitudeItems = $data['attitudeItems'] ?? [];
        $behaviourItems = $data['behaviourItems'] ?? [];

        // Limit data size to avoid token overflow
        $skillsData = array_slice($skillsData, 0, 10);
        $tasksData = array_slice($tasksData, 0, 10);
        $knowledgeItems = array_slice($knowledgeItems, 0, 10);

        $prompt = "You are an expert assessment designer. Generate EXACTLY {$questionCount} high-quality multiple-choice questions.

IMPORTANT INSTRUCTIONS:
- Generate EXACTLY {$questionCount} questions - no more, no less
- This is batch " . floor($startId / $questionCount) . " of multiple batches
- Each question must be realistic and job-relevant
- All answer options must be plausible and comparable in length

JOB ROLE DETAILS:
Industry: {$jobData['industries']}
Department: {$jobData['department']}
Job Role: {$jobData['jobrole']}
Description: {$jobData['description']}

SPECIFIC REQUIREMENTS FOR THIS BATCH:
- Assessment Type: {$mapping['typeName']}
- Core Competency: {$mapping['valueName']}
- Domain Category: {$mapping['domainCategory']}
- Number of Questions: {$questionCount}
- Marks per Question: {$mapping['marks']}
- Reason for Assessment: {$mapping['reason']}

DOMAIN KNOWLEDGE DATA (use these as source material):
- Skills Data: " . json_encode($skillsData, JSON_PRETTY_PRINT) . "
- Tasks Data: " . json_encode($tasksData, JSON_PRETTY_PRINT) . "
- Knowledge Items: " . json_encode($knowledgeItems, JSON_PRETTY_PRINT) . "

QUESTION QUALITY RULES:
1. Each question must assess exactly ONE primary domain category: {$mapping['domainCategory']}
2. Ground each question in a specific item from the provided datasets
3. Correct answer must not be identifiable by length or complexity
4. Distractors must be realistic and plausible

OUTPUT FORMAT (ONLY JSON, no markdown, no explanation):
{
  \"questions\": [
    {
      \"id\": {$startId},
      \"domainCategory\": \"{$mapping['domainCategory']}\",
      \"sourceItem\": {
        \"dataset\": \"{$mapping['domainCategory']} ITEMS\",
        \"title\": \"Exact title from dataset\"
      },
      \"question_title\": \"Clear, scenario-based question text\",
      \"answers\": [
        {\"answer\": \"Plausible option A\", \"correct_answer\": 0},
        {\"answer\": \"Plausible option B\", \"correct_answer\": 1},
        {\"answer\": \"Plausible option C\", \"correct_answer\": 0},
        {\"answer\": \"Plausible option D\", \"correct_answer\": 0}
      ],
      \"mappingType\": \"{$mapping['typeName']}\",
      \"mappingValue\": \"{$mapping['valueName']}\",
      \"marks\": {$mapping['marks']},
      \"reason\": \"Explanation linking question to the source item and job context\"
    }
  ]
}

Remember: Return ONLY valid JSON. No markdown formatting. No extra text.";

        return $prompt;
    }

    /**
     * Call Gemini API with proper endpoint.
     */
    private function callGeminiAPI(string $prompt, string $apiKey, ?string $model = null): array
    {
        // Use the provided model or the working model
        $model = $model ?: ($this->workingModel ?: 'gemini-2.5-flash');

        // added by uma on 08-05-2026 for API count
        $findAPI = DB::table('ai_daily_used_api')->where('key', $apiKey)->where('date', date('Y-m-d'))->first();
        if ($findAPI && isset($findAPI->key)) {
            $apiUsedCount = $findAPI->count + 1;
            DB::table('ai_daily_used_api')->where('id', $findAPI->id)->where('date', date('Y-m-d'))->update(['count' => $apiUsedCount]);
        } else {
            $getParentId = DB::table('gemini_api')->where('key', $apiKey)->first();
            DB::table('ai_daily_used_api')->insert([
                'api_name'         => 'gemini',
                'parent_id'        => $getParentId ? $getParentId->id : null,
                'key'              => $apiKey,
                'date'             => date('Y-m-d'),
                'count'            => 1,
                'sub_institute_id' => null,
                'created_at'       => now(),
            ]);
        }

        // Correct URL format for Gemini API
        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            Log::info('Making Gemini API request', [
                'url_preview'   => substr($geminiUrl, 0, 100) . '...',
                'prompt_length' => strlen($prompt)
            ]);

            $response = Http::timeout(90)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($geminiUrl, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.7,
                        'topP'            => 0.95,
                        'topK'            => 40,
                        'maxOutputTokens' => 4096,
                    ],
                    'safetySettings' => [
                        [
                            'category'  => 'HARM_CATEGORY_HARASSMENT',
                            'threshold' => 'BLOCK_NONE'
                        ],
                        [
                            'category'  => 'HARM_CATEGORY_HATE_SPEECH',
                            'threshold' => 'BLOCK_NONE'
                        ],
                        [
                            'category'  => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                            'threshold' => 'BLOCK_NONE'
                        ],
                        [
                            'category'  => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                            'threshold' => 'BLOCK_NONE'
                        ]
                    ]
                ]);

            Log::info('Gemini API response received', [
                'status'     => $response->status(),
                'successful' => $response->successful()
            ]);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('Gemini API error response', [
                    'status' => $response->status(),
                    'body'   => $errorBody
                ]);

                // Parse error details if available
                $errorData    = $response->json();
                $errorMessage = $errorData['error']['message'] ?? $errorBody;

                return [
                    'success' => false,
                    'error'   => "API Error (Status: {$response->status()}): {$errorMessage}"
                ];
            }

            $responseData = $response->json();

            // Extract generated text
            $generatedText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$generatedText) {
                Log::error('Empty response from Gemini', ['response' => $responseData]);
                return [
                    'success' => false,
                    'error'   => 'Empty response from Gemini API'
                ];
            }

            // Clean the response (remove markdown code blocks)
            $cleaned = trim($generatedText);

            // Remove markdown JSON code blocks if present
            $cleaned = preg_replace('/^```json\s*/i', '', $cleaned);
            $cleaned = preg_replace('/^```\s*/', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/m', '', $cleaned);
            $cleaned = trim($cleaned);

            // Try to extract JSON if there's extra text
            if (!preg_match('/^\{.*\}$/s', $cleaned)) {
                preg_match('/\{.*\}/s', $cleaned, $matches);
                if (!empty($matches)) {
                    $cleaned = $matches[0];
                }
            }

            // Parse JSON
            $parsed = json_decode($cleaned, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('JSON parse error in Gemini response', [
                    'error'        => json_last_error_msg(),
                    'raw_response' => substr($generatedText, 0, 1000)
                ]);

                return [
                    'success' => false,
                    'error'   => 'Failed to parse JSON response: ' . json_last_error_msg()
                ];
            }

            // Validate response structure
            if (!isset($parsed['questions']) || !is_array($parsed['questions'])) {
                Log::error('Invalid response structure', ['parsed' => $parsed]);
                return [
                    'success' => false,
                    'error'   => 'Response missing "questions" array'
                ];
            }

            return [
                'success'   => true,
                'questions' => $parsed['questions']
            ];

        } catch (\Exception $e) {
            Log::error('Gemini API request exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error'   => 'Request failed: ' . $e->getMessage()
            ];
        }
    }
}