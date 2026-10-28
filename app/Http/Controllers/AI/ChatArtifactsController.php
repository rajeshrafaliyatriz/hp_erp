<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Chat\ChatReportService;
use App\Domain\AI\Chat\ChatTemplateService;
use Illuminate\Http\Request;
use Throwable;

/**
 * HTTP face of the chat's report and template helpers. The tenant and role come from the
 * token (`scope()`), never from input; `module_key` only selects which module's data.
 */
class ChatArtifactsController extends AiController
{
    public function __construct(
        private readonly ChatReportService $reports,
        private readonly ChatTemplateService $templates,
    ) {
    }

    /** Suggested report types for a module, optionally narrowed by a message. */
    public function reportSuggestions(Request $request)
    {
        try {
            $data = $request->validate([
                'module_key' => 'required|string|max:60',
                'message' => 'nullable|string|max:2000',
            ]);

            return $this->success('Report suggestions resolved.', [
                'module_key' => $data['module_key'],
                'suggestions' => $this->reports->suggest($this->scope($request), $data['module_key'], $data['message'] ?? null),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Build and save the report a message asks for; `report` is null (with `reason`) when none matches. */
    public function report(Request $request)
    {
        try {
            $data = $request->validate([
                'module_key' => 'required|string|max:60',
                'message' => 'required|string|max:2000',
            ]);

            $result = $this->reports->attempt($this->scope($request), $data['module_key'], $data['message']);

            return $this->success(
                $result['report'] === null ? 'No report was made.' : 'Report built.',
                ['report' => $result['report'], 'reason' => $result['reason'], 'matched' => $result['matched']],
                $result['report'] === null ? 200 : 201
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Templates the module has (published and draft). */
    public function templateSuggestions(Request $request)
    {
        try {
            $data = $request->validate(['module_key' => 'required|string|max:60']);

            return $this->success('Template suggestions resolved.', [
                'module_key' => $data['module_key'],
                'templates' => $this->templates->suggest($this->scope($request), $data['module_key']),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** One template rendered with the organisation's real rows (nothing is saved). */
    public function templatePreview(Request $request, int $id)
    {
        try {
            $preview = $this->templates->preview($this->scope($request), $id);

            return $preview === null
                ? $this->failure('That template was not found.', 404)
                : $this->success('Template previewed.', ['preview' => $preview]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
