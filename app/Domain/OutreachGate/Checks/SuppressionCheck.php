<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\Gtm\Outreach\Suppressions;
use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\RecipientMap;

/** Fails when the address, or its whole domain, is on the organisation's suppression list (gtm_suppressions). No address, no answer: it fails. Read only. */
final class SuppressionCheck implements Check
{
    public function __construct(private readonly RecipientMap $map, private readonly Suppressions $suppressions)
    {
    }

    public function key(): string
    {
        return 'suppression';
    }

    public function label(): string
    {
        return 'Not on the suppression list';
    }

    public function run(Draft $draft): CheckResult
    {
        $r = $this->map->for($draft);
        if ($r->email === '') {
            return CheckResult::fail($this->key(), $this->label(), 'The suppression list could not be checked because there is no valid address.');
        }
        $block = $this->suppressions->blocking($draft->tenant, $r->email);

        return $block === null
            ? CheckResult::pass($this->key(), $this->label(), 'Neither the address nor its domain is on the suppression list.')
            : CheckResult::fail($this->key(), $this->label(), $block['kind'] === 'domain'
                ? "The domain {$block['value']} is on the suppression list (reason: {$block['reason']})."
                : "This address is on the suppression list (reason: {$block['reason']}).");
    }
}
