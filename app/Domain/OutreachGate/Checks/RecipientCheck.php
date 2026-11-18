<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\RecipientMap;

/** Fails when the gate cannot tell who the recipient is (see RecipientMap): no valid address, a record that does not exist for the organisation, an unknown kind of link. */
final class RecipientCheck implements Check
{
    public function __construct(private readonly RecipientMap $map)
    {
    }

    public function key(): string
    {
        return 'recipient';
    }

    public function label(): string
    {
        return 'Recipient identified';
    }

    public function run(Draft $draft): CheckResult
    {
        $r = $this->map->for($draft);

        return $r->mapped ? CheckResult::pass($this->key(), $this->label(), $r->detail) : CheckResult::fail($this->key(), $this->label(), $r->detail);
    }
}
