<?php

namespace App\Domain\Ask\Tools;

use App\Domain\AgentGateway\Rejected;
use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRecords;
use App\Domain\Ask\AskRights;
use App\Domain\Ask\ToolResult;

/**
 * get_record {type, id}: one record with its facts, its description and last notes, and the few things that hang off it (an organisation's open deals,
 * contacts, open tasks and signals; an opportunity's or contact's organisation; a lead's source signal), each as a row of its own so it can be cited.
 * A record of another organisation, a deleted one and one that never existed all answer "nothing found".
 */
final class GetRecordTool extends AskTool
{
    public function name(): string
    {
        return 'get_record';
    }

    public function describe(): string
    {
        return 'get_record {"type": "lead" | "contact" | "organization" | "opportunity", "id": number or "$1" | "$2" | "$3"}. Opens one record and lists what hangs off it: '
            . 'for an organisation its open deals, contacts, open tasks and signals. Use the id of the record the person is viewing, or "$N" to open the first record of that type '
            . 'that lookup number N (earlier in your list) found: for "what is open for Tapovan?" plan search_records for the organisation, then get_record with id "$1".';
    }

    protected function allowed(): array
    {
        return ['type', 'id'];
    }

    protected function normalise(AskCaller $caller, array $args): array
    {
        $type = SearchRecordsTool::kind($args['type'] ?? null) ?? throw new Rejected('bad_type', 'type must be lead, contact, organization or opportunity.');
        $id = $args['id'] ?? null;
        // "$1", "$2" or "$3": the first record of this type that the lookup with that number found (a plan cannot see a result before it runs, so a search
        // and the opening of what it finds are planned together). The id is then one the server read in THIS question, for this person, never one invented.
        if (is_string($id) && preg_match('/^\$([1-3])$/', trim($id), $m) === 1) {
            return ['type' => $type, 'id' => null, 'from_call' => (int) $m[1]];
        }
        if (! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
            throw new Rejected('bad_id', 'id must be a whole number or "$1", "$2", "$3".');
        }
        $id = (int) $id;
        if ($id < 1 || $id > 9_999_999_999) {
            throw new Rejected('bad_id', 'id must be a whole number of 1 or more.');
        }

        return ['type' => $type, 'id' => $id, 'from_call' => null];
    }

    public function run(AskCaller $caller, array $args): ToolResult
    {
        $type = $args['type'];
        if (! AskRights::canRead($caller, $type)) {
            return new ToolResult($this->name(), $args, ToolResult::NOT_PERMITTED, [], [AskRights::plural($type)]);
        }
        if ($args['id'] === null) {
            // A "$N" that was never resolved (the earlier lookup found no record of this kind): there is nothing to open.
            return new ToolResult($this->name(), $args, ToolResult::OK, [], [], null, 'for the ' . $type . ' the earlier lookup was meant to find');
        }
        $label = 'for ' . $type . ' #' . $args['id'];
        $record = AskRecords::find($caller, $type, $args['id']);
        if ($record === null) {
            return new ToolResult($this->name(), $args, ToolResult::OK, [], [], null, $label);
        }
        $related = AskRecords::related($caller, $type, $record);

        return new ToolResult($this->name(), $args, ToolResult::OK, [AskRecords::detail($caller, $type, $record), ...$related['rows']], $related['withheld'], null, $label);
    }
}
