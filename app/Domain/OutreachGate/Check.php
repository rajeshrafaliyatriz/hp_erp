<?php

namespace App\Domain\OutreachGate;

/**
 * One rule of the outreach gate. A check looks at a Draft and answers pass or fail with a reason; it never changes anything and never sends anything. A check that
 * cannot tell (a missing address, a record it cannot find) FAILS: the gate is closed unless every check says yes.
 */
interface Check
{
    /** The stable key stored with the result (at most 40 characters). */
    public function key(): string;

    /** The name a person reads on the screen. */
    public function label(): string;

    public function run(Draft $draft): CheckResult;
}
