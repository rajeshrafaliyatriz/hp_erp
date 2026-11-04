<?php

namespace App\Domain\AgentGateway;

use App\Domain\AgentGateway\Tools\AddNoteTool;
use App\Domain\AgentGateway\Tools\AddTaskTool;
use App\Domain\AgentGateway\Tools\AgentTool;
use App\Domain\AgentGateway\Tools\ConfirmLinkTool;

/**
 * The tools a model may ask for: three, fixed in code. There is no registration call and no configuration that adds one. There is NO tool for sending
 * (email, SMS, any message), deleting or merging, and none can be enabled by a setting or by an administrator: this list is the ceiling, and a test
 * reads it to prove no tool name contains send, email, sms, delete or merge. Raising the ceiling is a code change and an owner decision.
 */
final class ToolRegistry
{
    /** @var list<class-string<AgentTool>> */
    private const TOOLS = [AddNoteTool::class, AddTaskTool::class, ConfirmLinkTool::class];

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (string $class) => (new $class())->name(), self::TOOLS);
    }

    public function find(string $name): ?AgentTool
    {
        foreach (self::TOOLS as $class) {
            $tool = new $class();
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
