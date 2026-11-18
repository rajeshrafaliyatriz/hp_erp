<?php

namespace App\Domain\Ask;

use App\Domain\Ask\Tools\AskTool;
use App\Domain\Ask\Tools\GetRecordTool;
use App\Domain\Ask\Tools\ListProductsTool;
use App\Domain\Ask\Tools\ListSignalsTool;
use App\Domain\Ask\Tools\PipelineSummaryTool;
use App\Domain\Ask\Tools\SearchRecordsTool;

/**
 * The lookups the Ask planner may ask for: five, fixed in code, all READ ONLY. There is no registration call and no configuration that adds one. There is no
 * tool that creates, changes, sends or deletes anything, and none can be enabled by a setting or by an administrator: this list is the ceiling, and a test
 * lists it and scans the whole Ask folder for the calls that would write. Raising the ceiling is a code change and an owner decision.
 */
final class AskToolRegistry
{
    /** @var list<class-string<AskTool>> */
    private const TOOLS = [SearchRecordsTool::class, GetRecordTool::class, ListSignalsTool::class, ListProductsTool::class, PipelineSummaryTool::class];

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (string $class) => (new $class())->name(), self::TOOLS);
    }

    /** @return list<AskTool> */
    public function all(): array
    {
        return array_map(fn (string $class) => new $class(), self::TOOLS);
    }

    public function find(string $name): ?AskTool
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
