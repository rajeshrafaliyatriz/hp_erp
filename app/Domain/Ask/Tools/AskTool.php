<?php

namespace App\Domain\Ask\Tools;

use App\Domain\Ask\AskCaller;
use App\Domain\Ask\ToolResult;
use App\Domain\AgentGateway\Rejected;

/**
 * A read-only lookup the Ask planner may ask for (slice 8). A tool never writes: it has no way to (a test scans this folder for the calls that would). The
 * planner's arguments are untrusted, so they are validated here, on the server, whatever the model was told: a key the tool does not know (a tenant, a user,
 * a column) rejects the whole call, an argument of the wrong shape rejects it, and a rejected call is dropped and audited, never run.
 *
 * run() answers with a ToolResult. It never throws for a record that is not there or a right the person lacks: those are results.
 */
abstract class AskTool
{
    abstract public function name(): string;

    /** What the planner is told about the tool: one paragraph, its arguments and their allowed values. */
    abstract public function describe(): string;

    /** @return list<string> the argument names this tool accepts */
    abstract protected function allowed(): array;

    /**
     * @param  array<string, mixed>  $args  only the allowed keys, each present exactly as the model gave it
     * @return array<string, mixed> the arguments as the tool will use them
     *
     * @throws Rejected
     */
    abstract protected function normalise(AskCaller $caller, array $args): array;

    /**
     * @param  array<string, mixed>  $args  as validate() returned them
     */
    abstract public function run(AskCaller $caller, array $args): ToolResult;

    /**
     * The arguments a model asked for, checked and normalised.
     *
     * @return array<string, mixed>
     *
     * @throws Rejected `bad_arguments` (not an object) | `unknown_argument` (a key outside allowed()) | whatever normalise() refuses
     */
    final public function validate(AskCaller $caller, mixed $args): array
    {
        if ($args === null || $args === []) {
            $args = [];   // a tool with no arguments is asked for with {} or with nothing at all
        } elseif (! is_array($args) || array_is_list($args)) {
            throw new Rejected('bad_arguments', 'The arguments are not an object.');
        }
        foreach (array_keys($args) as $key) {
            if (! in_array((string) $key, $this->allowed(), true)) {
                throw new Rejected('unknown_argument', "'{$key}' is not an argument of {$this->name()}.");
            }
        }

        return $this->normalise($caller, $args);
    }
}
