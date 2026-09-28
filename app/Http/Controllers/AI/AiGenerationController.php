<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\AI\Templates\TemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Render one published prompt template with the caller's variables and return what the
 * model wrote — LMS_K12's `POST /api/ai/generate` contract, answered by G2G's own
 * template store and model client.
 *
 * WHAT CALLS IT
 *
 * The shared AI Stack's field assistant (the sparkle beside a form field), through the
 * Next route `/api/ai/field-edit`, with `template_key = g2g.field_edit`.
 *
 * ONE PATH TO A MODEL
 *
 * The call goes through `AiModelClient::complete()`, so it is resolved by the central
 * configuration (and, when `purpose` names a module, by that module's own Models choice),
 * checked against the quota before it is sent, and metered into `ai_usage_events`.
 * Nothing here holds a key or picks a provider.
 */
class AiGenerationController extends AiController
{
    public function __construct(
        private readonly TemplateCatalog $templates,
        private readonly AiModelClient $models,
    ) {
    }

    public function generate(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $data = $request->validate([
                'template_key' => 'required|string|max:120',
                'purpose' => 'nullable|string|max:120',
                'domain' => 'nullable|string|max:40',
                'variables' => 'nullable|array',
                'variables.*' => 'nullable|string|max:20000',
            ]);

            // The organisation's own copy of the key wins over the platform's.
            $template = DB::table('ai_templates')
                ->where('template_key', $data['template_key'])
                ->where('status', 'published')
                ->where('kind', 'prompt')
                ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
                ->orderByRaw('sub_institute_id IS NULL ASC')
                ->orderByDesc('version')
                ->first();

            if ($template === null) {
                return $this->failure('That prompt template is not published for this organisation.', 404);
            }

            $rendered = $this->templates->preview(
                (string) ($template->system_prompt ?? ''),
                (string) $template->user_prompt,
                array_map(fn ($value) => (string) ($value ?? ''), $data['variables'] ?? [])
            );

            // `field_edit:<module>` — the module the request came from, so that module's
            // own AI Stack model choice is the one that answers.
            $purpose = (string) ($data['purpose'] ?? '');
            $productModule = str_contains($purpose, ':') ? substr($purpose, strpos($purpose, ':') + 1) : null;
            $productModule = $productModule !== null && preg_match('/^[a-z0-9_\-]+$/', $productModule) ? $productModule : null;

            $messages = [];
            if (($rendered['system'] ?? null) !== null) {
                $messages[] = ['role' => 'system', 'content' => $rendered['system']];
            }
            $messages[] = ['role' => 'user', 'content' => $rendered['user']];

            $completion = $this->models->complete(
                'conversational_ai',
                $messages,
                array_filter([
                    'temperature' => $template->temperature === null ? 0.3 : (float) $template->temperature,
                    'max_tokens' => $template->max_tokens === null ? null : (int) $template->max_tokens,
                    'related_type' => 'ai_templates',
                    'related_id' => (int) $template->id,
                    'user_id' => $scope->userId,
                ], fn ($value) => $value !== null),
                $institute,
                $productModule
            );

            return $this->success('Generated.', [
                'content' => $completion->text,
                'model' => $completion->model ?? null,
                'template_id' => (int) $template->id,
            ]);
        } catch (AiNotConfiguredException $exception) {
            return $this->failure($exception->getMessage(), 503);
        } catch (AiQuotaExceededException $exception) {
            return $this->failure($exception->getMessage(), 429);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
