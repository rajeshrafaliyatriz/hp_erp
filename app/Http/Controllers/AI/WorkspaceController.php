<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Workspace\PageContextResolver;
use Illuminate\Http\Request;
use Throwable;

/**
 * What the assistant knows about the page the user has open.
 *
 * Counterpart of LMS K-12's `POST /workspace/context`, in G2G's terms: the page is a
 * `tblmenumaster_g2g` row, the caller is the Sanctum token's user, and the suggestions come
 * from `PageContextResolver` - real data sources and curated `ai_suggestions`, for the
 * module that page belongs to and no other.
 *
 * Tenant and role come from the token (`AiContextHydrator`); the only inputs are which page.
 */
class WorkspaceController extends AiController
{
    public function __construct(private readonly PageContextResolver $pages)
    {
    }

    public function context(Request $request)
    {
        try {
            $scope = $this->scope($request);

            $data = $request->validate([
                'menu_id' => 'nullable|integer|min:1',
                'route' => 'nullable|string|max:300',
                // What the browser read off the page. Rebuilt and capped server-side before use.
                'page_data' => 'nullable|array',
                // On a module's AI Stack: which module and tab the user has open (validated server-side).
                'ai_stack_module' => 'nullable|string|max:60',
                'ai_stack_tab' => 'nullable|string|max:40',
            ]);

            return $this->success('Page context resolved.', $this->pages->resolve(
                $scope,
                isset($data['menu_id']) ? (int) $data['menu_id'] : null,
                $data['route'] ?? null,
                $data['page_data'] ?? null,
                $data['ai_stack_module'] ?? null,
                $data['ai_stack_tab'] ?? null
            ));
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
