<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 - which automations (tool agents) are configured for this module?
 *
 * Read-only. The chat does not run an agent on its own: agents are run from the module's
 * Automations tab. This stage reports what is configured, so the trace and the answer can point
 * at it, and the data tools the chat may use are the module's own sources either way.
 */
final class AgentStage implements LifecycleStage
{
    public function __construct(private readonly ModuleGrounding $grounding)
    {
    }

    public function key(): StageKey
    {
        return StageKey::Agent;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->module === null) {
            return StageResult::skipped('The organisation-wide assistant has no module automations.');
        }

        if (! Schema::hasTable('agentic_agents')) {
            return StageResult::skipped('Automations are not installed on this deployment.');
        }

        $rows = DB::table('agentic_agents')
            ->where('sub_institute_id', $context->scope->selectedInstituteId)
            ->whereIn('sub_module', $this->grounding->keys((string) $context->module->module_key))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'status']);

        if ($rows->isEmpty()) {
            return StageResult::skipped('No automation is configured for this module.');
        }

        $context->agents = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
        ])->all();

        return StageResult::ran(
            sprintf('%d automation%s configured for this module.', $rows->count(), $rows->count() === 1 ? '' : 's'),
            ['agents' => $context->agents]
        );
    }
}
