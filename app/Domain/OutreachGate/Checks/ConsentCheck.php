<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\Facts\ConsentGate;
use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\RecipientMap;

/**
 * May this person be e-mailed? Asks the ONE consent gate (App\Domain\Facts\ConsentGate, the answer of its four sources: the record's own flags, crm_consent_preferences,
 * the suppression list and the outreach contact's status) for the email channel on EVERY CRM record the recipient maps to. Any record that says no fails the check.
 * A recipient that could not be mapped fails closed, without asking. Read only.
 */
final class ConsentCheck implements Check
{
    public function __construct(private readonly RecipientMap $map, private readonly ConsentGate $consent)
    {
    }

    public function key(): string
    {
        return 'consent';
    }

    public function label(): string
    {
        return 'Allowed to email';
    }

    public function run(Draft $draft): CheckResult
    {
        $r = $this->map->for($draft);
        if (! $r->mapped) {
            return CheckResult::fail($this->key(), $this->label(), 'Consent could not be read because the recipient could not be identified: ' . $r->detail);
        }
        if ($r->entities === []) {
            return CheckResult::pass($this->key(), $this->label(), 'No CRM record carries this address; consent rests on the outreach contact status and the suppression list (both checked).');
        }

        $no = [];
        foreach ($r->entities as [$type, $id]) {
            $answer = $this->consent->allows($type, $id, 'email', $draft->tenant);
            if (! $answer['allowed']) {
                $no[] = ucfirst($type) . " #{$id}: " . rtrim((string) $answer['reason'], '.') . '.';
            }
        }

        return $no === []
            ? CheckResult::pass($this->key(), $this->label(), 'Email is allowed on ' . count($r->entities) . ' CRM record' . (count($r->entities) === 1 ? '' : 's') . '.')
            : CheckResult::fail($this->key(), $this->label(), implode(' ', array_slice($no, 0, 3)) . (count($no) > 3 ? ' and ' . (count($no) - 3) . ' more.' : ''));
    }
}
